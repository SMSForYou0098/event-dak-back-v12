<?php

namespace App\Http\Controllers;

use App\Exports\PosExport;
use App\Jobs\SendBookingAlertJob;
use App\Models\PosBooking;
use App\Models\Ticket;
use App\Services\BookingWithLockingService;
use App\Services\BookingTaxService;
use App\Services\DateRangeService;
use App\Services\EventSeatStatusService;
use App\Services\PermissionService;
use App\Services\SeatLockingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

// its laravel
class PosController extends Controller
{
    protected $eventSeatStatusService;
    protected $seatLockingService;
    protected $bookingWithLockingService;
    protected $bookingTaxService;

    public function __construct(
        EventSeatStatusService $eventSeatStatusService,
        SeatLockingService $seatLockingService,
        BookingWithLockingService $bookingWithLockingService,
        BookingTaxService $bookingTaxService
    ) {
        $this->eventSeatStatusService = $eventSeatStatusService;
        $this->seatLockingService = $seatLockingService;
        $this->bookingWithLockingService = $bookingWithLockingService;
        $this->bookingTaxService = $bookingTaxService;
    }

    public function index(Request $request, $id, DateRangeService $dateRangeService, PermissionService $permissionService)
    {
        $loggedInUser = Auth::user();
        $isAdmin = $loggedInUser->hasRole('Admin');
        $userIds = collect();

        // 📄 Pagination & search params
        $perPage = min($request->input('per_page', 10), 100);
        $page = (int) $request->input('page', 1);
        $search = trim($request->input('search', ''));

        // 🔐 Permissions for masking sensitive data (same pattern as Agent list)
        $permissions = $permissionService->check(['View Username', 'View User Number']);
        $canViewUsername = $permissions['View Username'];
        $canViewContact  = $permissions['View User Number'];

        // 🗓 Date Filtering Logic
        $dateRange = $dateRangeService->parseDateRangeSafe($request);

        if (isset($dateRange['error'])) {
            return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
        }

        $startDate = $dateRange['startDate'];
        $endDate = $dateRange['endDate'];

        // 🔍 Base Query
        $query = PosBooking::with([
            'bookingTax',
            'ticket:id,name,event_id',
            'ticket.event:id,name',
            'user:id,name,number,email,reporting_user,payment_method',
            'user.reportingUser:id,name',
            'eventSeatStatus:id,booking_id,seat_name,section_id,type,ticket_id',
            'eventSeatStatus.section:id,name'
        ])->whereBetween('created_at', [$startDate, $endDate]);

        // 👥 Role-based filter
        if ($isAdmin) {
            $bookingsQuery = $query->withTrashed();
            $activeBookingsQuery = clone $query;
        } else {
            $underUserIds = $loggedInUser->usersUnder()->pluck('id');
            $userIds = $underUserIds->push($loggedInUser->id);
            $bookingsQuery = $query->withTrashed()->whereIn('user_id', $userIds);
            $activeBookingsQuery = clone $query->whereIn('user_id', $userIds);
        }

        // 🧾 Fetch bookings
        $bookings = $bookingsQuery->latest()->get();

        // 🧩 Group by set_id if exists; else treat as single booking
        $grouped = $bookings->groupBy(function ($item) {
            return $item->set_id ?: 'SINGLE-' . $item->id;
        })->map(function ($group, $key) {
            $first = $group->first();
            $firstInner = $group->skip(1)->first();

            // ✅ Case 1: Multiple bookings with same set_id
            if (!empty($first->set_id) && $group->count() > 1) {
                return [
                    'set_id' => $first->set_id,
                    'total_bookings' => $group->count(),
                    'total_amount' => $group->sum('total_amount'),
                    'discount' => $group->sum('discount'),
                    'quantity' => $group->sum('quantity'),
                    'status' => $first->status,
                    'is_set' => true,
                    'token' => $first->token,
                    // 'discount' => $first->discount,
                    'bookings' => $group->values(),
                    'name' => $firstInner->name ?? $first->name ?? '',
                    'number' => $firstInner->number ?? $first->number ?? '',
                    'email' => $firstInner->email ?? $first->email ?? '',
                    'payment_method' => $firstInner->payment_method ?? $first->payment_method ?? '',
                    'created_at' => $firstInner->created_at,

                    'repoting' => $firstInner->organizer ?? $first->organizer ?? '',
                    'reporting_user_name' => $firstInner->user->reportingUser->name ?? ($first->user->reportingUser->name ?? ''),
                    'ticket' => [
                        'name' => $group->pluck('ticket.name')->filter()->first() ?? ($firstInner->ticket->name ?? $first->ticket->name ?? ''),
                        'event' => [
                            'name' => $group->pluck('ticket.event.name')->filter()->first() ?? '',
                        ],
                    ],
                ];
            }

            // ✅ Case 2: Single booking (no set_id or only one record)
            $parent = $group->first();
            $parent->is_set = false;
            $parent->is_deleted = $parent->trashed();
            $parent->user_name = $parent->user->name ?? 'Unknown User';
            $parent->payment_method = $parent->payment_method ?? '';
            // $parent->payment_method = $parent->user->payment_method ?? '';
            $parent->reporting_user_name = $parent->user->reportingUser->name ?? 'N/A';
            $parent->related = $group->skip(1)->values(); // usually empty for single
            return $parent;
        })->values();

        // 💰 Totals
        $amount = $activeBookingsQuery->sum('amount');
        $discount = $activeBookingsQuery->sum('discount');

        // 🔍 Apply search on the grouped collection
        $filtered = $grouped;
        if ($search !== '') {
            $needle = mb_strtolower($search);

            $filtered = $filtered->filter(function ($item) use ($needle) {
                // Grouped/set rows are arrays, single bookings are models
                if (is_array($item)) {
                    $haystack = [
                        $item['set_id'] ?? null,
                        $item['name'] ?? null,
                        $item['number'] ?? null,
                        $item['email'] ?? null,
                        $item['payment_method'] ?? null,
                        $item['reporting_user_name'] ?? null,
                        $item['ticket']['name'] ?? null,
                        $item['ticket']['event']['name'] ?? null,
                    ];

                    foreach ($haystack as $value) {
                        if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                            return true;
                        }
                    }

                    // Also scan child bookings if present
                    if (isset($item['bookings'])) {
                        foreach ($item['bookings'] as $booking) {
                            $childHaystack = [
                                $booking->name ?? null,
                                $booking->number ?? null,
                                $booking->email ?? null,
                                $booking->payment_method ?? null,
                                optional($booking->ticket)->name,
                                optional(optional($booking->ticket)->event)->name,
                            ];
                            foreach ($childHaystack as $value) {
                                if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                                    return true;
                                }
                            }
                        }
                    }

                    return false;
                }

                // Single PosBooking model
                $haystack = [
                    $item->name ?? null,
                    $item->number ?? null,
                    $item->email ?? null,
                    $item->payment_method ?? null,
                    $item->user_name ?? null,
                    $item->reporting_user_name ?? null,
                    optional($item->ticket)->name,
                    optional(optional($item->ticket)->event)->name,
                ];

                foreach ($haystack as $value) {
                    if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        // 📊 Pagination over in-memory collection
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $paginated = $filtered->slice($offset, $perPage)
            ->map(function ($item) use ($canViewUsername, $canViewContact) {
                // Mask sensitive data according to permissions
                if (is_array($item)) {
                    if (!$canViewUsername && isset($item['name'])) {
                        $item['name'] = null;
                    }
                    if (!$canViewContact && isset($item['number'])) {
                        $item['number'] = null;
                    }
                    return $item;
                }

                if (!$canViewUsername) {
                    $item->name = null;
                    if (isset($item->user_name)) {
                        $item->user_name = null;
                    }
                }
                if (!$canViewContact) {
                    $item->number = null;
                }

                return $item;
            })
            ->values();

        return response()->json([
            'status' => true,
            'bookings' => $paginated,
            'amount' => $amount,
            'discount' => $discount,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
            'message' => $bookings->isNotEmpty() ? null : 'No Bookings Found',
        ], 200);
    }

    public function create(Request $request)
    {
        try {
            $user = auth()->user();
            $isAdminOrOrganizer = $user->hasRole('Admin') || $user->hasRole('Organizer');

            // 🔥 SEAT VALIDATION PHASE - CHECK BEFORE ANY BOOKING
            if ($request->seating_module === true || $request->seating_module === 'true') {
                $sessionId = $request->sessionId ?? uniqid('pos_', true);

                $validation = $this->eventSeatStatusService->validateSeatsBeforeBooking(
                    $request->tickets,
                    $this->seatLockingService,
                    $sessionId
                );

                if (!$validation['valid']) {
                    return response()->json([
                        'status' => false,
                        'meta' => 409,
                        'message' => $validation['message'],
                        'seats' => $validation['unavailable_seat_ids'] ?? []
                    ], 409);
                }
            }

            // ✅ ALL VALIDATIONS PASSED - NOW PROCEED WITH BOOKING
            DB::beginTransaction();
            $bookings = [];
            $token = $this->generateHexadecimalCode();
            $setId = strtoupper('SET-' . Str::random(10));
            $sessionId = uniqid('pos_', true);

            foreach ($request->tickets as $ticketData) {
                $ticket = Ticket::findOrFail($ticketData['id']);
                $event = $ticket->event;

                $requestedQty = (int) $ticketData['quantity'];
                $remaining = $ticket->remaining_count ?? $ticket->ticket_quantity;

                // ✅ 1️⃣ Check if enough tickets available before booking
                if ($remaining <= 0) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKETS_SOLD_OUT",
                        "message" => "Tickets are sold out."
                    ], 410);
                }

                if ($requestedQty > $remaining) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKET_LIMIT_REACHED",
                        'message' => "Ticket limit reached - just  {$remaining} tickets left.",
                    ], 409);
                }

                $booking = new PosBooking();
                $booking->token = $token;
                $booking->user_id = $request->user_id;
                $booking->ticket_id = $ticketData['id'];
                $booking->event_id = $ticket->event_id;
                $booking->set_id = $setId;
                $booking->name = $request->name;
                $booking->number = $request->number;
                $booking->quantity = $ticketData['quantity'];
                $booking->discount = $ticketData['discount'] ?? 0;
                $booking->price = $ticketData['price'] ?? 0;
                $booking->amount = $ticketData['finalAmount'] ?? 0;
                $booking->total_amount = $ticketData['totalFinalAmount'] ?? 0;
                $booking->seating = (isset($ticketData['seats']) && !empty($ticketData['seats'])) ? 1 : 0;

                // 🔹 Payment method logic
                if ($isAdminOrOrganizer) {
                    $booking->payment_method = $request->payment_method;
                } else {
                    $booking->payment_method = $user->payment_method ?? 'cash';
                }

                $booking->booking_date = now();
                $booking->status = 0;
                $booking->save();

                $quantity = (int) $ticketData['quantity'];
                $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
                $newRemaining = max(0, $newRemaining - $quantity);
                $ticket->remaining_count = $newRemaining;
                $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
                $ticket->save();

                // 🔹 Save tax details
                // 🔹 Save tax details
                $this->bookingTaxService->createBookingTax(
                    $booking->id,
                    'POS',
                    $ticketData,
                    $booking->discount ?? 0
                );

                if (!empty($ticketData['seats'])) {
                    foreach ($ticketData['seats'] as $seat) {
                        // ✅ USE SERVICE: Update or create seat status (Agent pattern)
                        $this->eventSeatStatusService->markSeatAsBooked(
                            $seat,
                            $booking->id,
                            $ticket->id,
                            $ticket->event_id,
                            $event->event_key ?? null,
                            'POS',
                            $sessionId
                        );
                    }
                }

                $booking->load([
                    'ticket:id,name,event_id',
                    'ticket.event:id,name',
                    'bookingTax:id,total_tax,convenience_fee,booking_id',
                    'eventSeatStatus:id,booking_id,seat_name,seat_id,section_id,type,ticket_id',
                    'eventSeatStatus.section:id,name'
                ]);

                $bookings[] = $booking;
            }

            DB::commit();

            // 🔹 Send SMS / WhatsApp Alerts Asynchronously
            $bookingIds = collect($bookings)->pluck('id')->toArray();
            SendBookingAlertJob::dispatch($bookingIds, 'pos');

            return response()->json([
                'status' => true,
                'message' => 'Tickets booked successfully',
                'bookings' => $bookings
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to book tickets',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function generateHexadecimalCode($length = 8)
    {
        $characters = '0123456789ABCDEF'; // Hexadecimal characters
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function extractNumericId($value): ?int
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public function destroy($id)
    {
        try {
            $booking = PosBooking::findOrFail($id);

            // 🔍 Check if related ticket exists
            $ticket = Ticket::withTrashed()->find($booking->ticket_id);

            if (!$ticket) {
                return response()->json([
                    'status' => false,
                    'message' => 'Related ticket not found.'
                ], 404);
            }

            // 🚫 If ticket is soft deleted — prevent deletion
            if ($ticket->trashed()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete — related booking is deleted.',
                ], 400);
            }

            // ✅ Proceed to delete booking
            $booking->delete();

            return response()->json([
                'status' => true,
                'message' => 'Booking deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error deleting booking: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function restoreBooking($id)
    {
        try {
            $booking = PosBooking::withTrashed()->findOrFail($id);

            if (!$booking) {
                return response()->json([
                    'status' => false,
                    'message' => 'Booking not found.'
                ], 404);
            }

            // 🔍 Check related ticket
            $ticket = Ticket::withTrashed()->find($booking->ticket_id);

            if (!$ticket) {
                return response()->json([
                    'status' => false,
                    'message' => 'Related ticket not found.'
                ], 404);
            }

            // 🚫 If ticket is soft-deleted, stop restore
            if ($ticket->trashed()) {
                return response()->json([
                    'status' => false,
                    'message' => 'This booking event is disabled.',
                ], 400);
            }

            // ✅ Restore booking
            $booking->restore();

            return response()->json([
                'status' => true,
                'message' => 'Booking restored successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error restoring booking: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function export(Request $request, DateRangeService $dateRangeService)
    {

        $Attendee = $request->input('user_id');
        $eventName = $request->input('ticket_id');
        $status = $request->input('status');

        $query = PosBooking::query();

        if ($request->has('ticket_id')) {
            $query->where('ticket_id', $eventName);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $Attendee);
        }

        if ($request->has('status')) {
            $query->where('status', $status);
        }

        // Date filtering
        if ($request->has('date')) {
            $dateRange = $dateRangeService->parseDateRange($request, null, false);

            if ($dateRange === null) {
                // No date provided, skip date filtering
            } else {
                $startDate = $dateRange['startDate'];
                $endDate = $dateRange['endDate'];

                // Use whereDate for single day, whereBetween for range
                if ($startDate->isSameDay($endDate)) {
                    $query->whereDate('created_at', $startDate->toDateString());
                } else {
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                }
            }
        }

        // $PosBooking = $query->get();
        $PosBooking = $query->with([
            'ticket.event.user',
            'user'
        ])->get();
        // return response()->json(['Booking' => $PosBooking]);
        return Excel::download(new PosExport($PosBooking), 'PosBooking_export.xlsx');
    }

    public function posDataByNumber($number)
    {
        $booking = PosBooking::select('name', 'number')
            ->where('number', $number)
            ->latest()
            ->first();

        if ($booking) {
            return response()->json(['status' => true, 'data' => $booking], 200);
        } else {
            return response()->json(['status' => false, 'message' => 'No booking found for this number'], 404);
        }
    }
}
