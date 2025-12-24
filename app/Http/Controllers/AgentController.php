<?php

namespace App\Http\Controllers;

use App\Events\SeatStatusUpdated;
use App\Exports\AgentBookingExport;
use App\Models\Booking;
use App\Models\MasterBooking;
use App\Models\Balance;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\BookingWithLockingService;
use App\Services\BookingTaxService;
use App\Services\EventSeatStatusService;
use App\Services\SeatLockingService;
use App\Services\WebhookService;
use App\Jobs\SendBookingAlertJob;
use App\Services\SessionIdService;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AgentController extends Controller
{
    protected $WebhookService;
    protected $eventSeatStatusService;
    protected $seatLockingService;
    protected $bookingWithLockingService;
    protected $bookingTaxService;
    protected $sessionIdService;

    public function __construct(
        WebhookService $WebhookService,
        EventSeatStatusService $eventSeatStatusService,
        SeatLockingService $seatLockingService,
        BookingWithLockingService $bookingWithLockingService,
        BookingTaxService $bookingTaxService,
        SessionIdService $sessionIdService
    ) {
        $this->WebhookService = $WebhookService;
        $this->eventSeatStatusService = $eventSeatStatusService;
        $this->seatLockingService = $seatLockingService;
        $this->bookingWithLockingService = $bookingWithLockingService;
        $this->bookingTaxService = $bookingTaxService;
        $this->sessionIdService = $sessionIdService;
    }


    public function store(Request $request, $type)
    {
        try {
            $user = auth()->user();
            $userId = auth()->id() ?? $request->user_id;

            // 🔹 Step 1: Balance Check for Agent (Optimized with select and index hint)
            $latestBalance = null;
            if ($user->hasRole('Agent') || $user->hasRole('Sponsor')) {
                // Only select needed columns to reduce memory usage
                $latestBalance = Balance::select('id', 'user_id', 'total_credits')
                    ->where('user_id', $user->id)
                    ->latest('id')
                    ->first();

                if (!$latestBalance) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Booking failed due to insufficient balance.'
                    ], 400);
                }

                $totalAmount = collect($request->tickets)->sum('totalFinalAmount');

                if ($latestBalance->total_credits < $totalAmount) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Not sufficient amount in balance.'
                    ], 400);
                }
            }


            // 1. Session ID for Seat Locking (Redis check)
            $lockSessionId = $request->session_id ?? $request->sessionId ?? ($userId ? "user_{$userId}" : null);
            //  SEAT VALIDATION PHASE - CHECK BEFORE ANY BOOKING
            $seating_module = $request->seating_module;
            // return $seating_module;
            if ($seating_module || $seating_module === 'true') {

                $validation = $this->eventSeatStatusService->validateSeatsBeforeBooking(
                    $request->tickets,
                    $this->seatLockingService,
                    $lockSessionId
                );
                // return $validation;
                if (!$validation['valid']) {
                    return response()->json([
                        'status' => false,
                        'meta' => 409,
                        'seats' => $validation['unavailable_seat_ids'],
                        'message' => $validation['message']
                    ], 409);
                }
            }

            // 2. Session ID for Database Storage (Booking record)
            $dbSessionId = $this->sessionIdService->generateEncryptedSessionId()['original'];

            // Fallback: If no lock session ID (e.g. non-seated booking without user), use the DB one
            if (!$lockSessionId) {
                $lockSessionId = $dbSessionId;
            }

            DB::beginTransaction();
            // $sessionId already defined above
            $setId = strtoupper('SET-' . Str::random(10));
            $bookings = [];
            $totalAmount = 0;

            // 🔹 Step 3: Loop tickets (Optimized batch loading)
            // Preload all tickets at once to reduce N+1 queries
            $ticketIds = collect($request->tickets)->pluck('id')->toArray();
            $tickets = Ticket::select('id', 'event_id', 'batch_id', 'remaining_count', 'ticket_quantity')
                ->whereIn('id', $ticketIds)
                ->get()
                ->keyBy('id');

            foreach ($request->tickets as $ticketData) {
                $ticket = $tickets->get($ticketData['id']);
             
                if (!$ticket) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Ticket not found.'
                    ], 404);
                }

                // Handle both seated and non-seated tickets
                $hasSeats = isset($ticketData['seats']) && is_array($ticketData['seats']) && count($ticketData['seats']) > 0;
                $quantity = $hasSeats ? count($ticketData['seats']) : ($ticketData['quantity'] ?? 1);
                $remaining = $ticket->remaining_count ?? $ticket->ticket_quantity;

                if ($remaining <= 0) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKETS_SOLD_OUT",
                        "message" => "Tickets are sold out."
                    ], 200);
                }

                if ($quantity > $remaining) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKET_LIMIT_REACHED",
                        'message' => "Ticket limit reached — only {$remaining} seats left.",
                    ], 200);
                }

                $totalAmount += (float) $ticketData['totalFinalAmount'];
                $ticketBookingIds = [];

                // 🔥 Build items to loop: seats if available, otherwise generate placeholder items based on quantity
                $itemsToBook = $hasSeats
                    ? $ticketData['seats']
                    : array_fill(0, $quantity, ['seat_id' => null, 'seat_name' => null, 'section_id' => null, 'row_id' => null]);

                // 🔥 Loop each item (one booking = one seat or one quantity unit)
                foreach ($itemsToBook as $index => $seat) {

                    $token = $this->WebhookService->generateHexadecimalCode();

                    // Prepare booking data as array for potential batch insert
                    // return $ticketData['id'];
                    $bookingData = [
                        'ticket_id' => $ticketData['id'],
                        'event_id' => $ticket->event_id,
                        'batch_id' => $ticket->batch_id,
                        'set_id' => $setId,
                        'booking_by' => $request->agent_id,
                        'user_id' => $request->user_id,
                        'session_id' => $dbSessionId,
                        'token' => $token,
                        'email' => $request->email,
                        'name' => $request->name,
                        'number' => $request->number,
                        'type' => $request->type,
                        'payment_method' => $request->payment_method,
                        'booking_type' => $type,
                        'status' => 0,
                        'quantity' => 1,
                        'total_amount' => $ticketData['finalAmount'],
                        'discount' => $ticketData['discountPerUnit'] ?? 0,
                        'seat_id' => $seat['seat_id'] ?? null,
                        'seat_name' => $seat['seat_name'] ?? null,
                        'section_id' => $seat['section_id'] ?? null,
                        'row_id' => $seat['row_id'] ?? null,
                        'attendee_id' => $ticketData['attendee_ids'][$index] ?? ($ticketData['attendee_ids'][0] ?? null),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $booking = Booking::create($bookingData);

                    // 🔥 Booking Tax
                    $this->bookingTaxService->createBookingTax(
                        $booking->id,
                        'agent',
                        $ticketData,
                        $booking->discount ?? 0
                    );

                    // 🔥 UPDATE ESS TABLE (Seat Booked) - only for seated tickets
                    if ($hasSeats && !empty($seat['seat_id'])) {
                        $ess = $this->eventSeatStatusService->markSeatAsBooked(
                            $seat,
                            $booking->id,
                            $ticket->id,
                            $ticket->event_id,
                            $ticket->event->event_key ?? null,
                            $type,
                            $dbSessionId
                        );

                        if ($ess) {
                            $booking->ess_id = $ess->id;
                            $booking->save();
                        }
                    }

                    $bookings[] = $booking;
                    $ticketBookingIds[] = $booking->id;
                }

                // � Broadcast Seat Updates for this Ticket
                if ($hasSeats) {
                    $bookedSeatIds = array_column($itemsToBook, 'seat_id');
                    $bookedSeatIds = array_filter($bookedSeatIds); // Remove nulls

                    if (!empty($bookedSeatIds) && env('ENABLE_SEAT_STATUS_UPDATES', true)) {
                        event(new SeatStatusUpdated(
                            $ticket->event_id,
                            $bookedSeatIds,
                            'booked',
                            $request->user_id // Pass the lock session ID (user_id based)
                        ));
                    }
                }

                // �🔹 Master Booking Per Ticket
                if ($quantity > 1) {
                    $masterToken = $this->WebhookService->generateHexadecimalCode();

                    $masterBookingData = [
                        'booking_id' => $ticketBookingIds,
                        'user_id' => $request->user_id,
                        'booking_by' => $request->agent_id,
                        'session_id' => $dbSessionId,
                        'set_id' => $setId,
                        'order_id' => $masterToken,
                        'total_amount' => $ticketData['totalFinalAmount'],
                        'discount' => $ticketData['discount'] ?? 0,
                        'payment_method' => $request->payment_method ?? 'cash',
                        'booking_type' => $type,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $masterBooking = MasterBooking::create($masterBookingData);

                    // Use whereIn for batch update instead of individual updates
                    Booking::whereIn('id', $ticketBookingIds)->update(['master_token' => $masterBooking->order_id]);

                    $masterBookings[] = $masterBooking->id;
                }

                // 🔥 UPDATE ticket stock based on seats count
                $currentRemaining = (int) $ticket->remaining_count;
                $newRemaining = max(0, $currentRemaining - $quantity);
                
                $ticket->update([
                    'remaining_count' => (string) $newRemaining,
                    'sold_out' => $newRemaining <= 0 ? 1 : 0
                ]);
            }

            // 🔹 Deduct Agent Credits
            $this->deductAgentCredits($user, $latestBalance, $totalAmount);

            DB::commit();

            // 🔹 Send SMS / WhatsApp (Async via Job)
            $bookingIds = collect($bookings)->pluck('id')->toArray();
            SendBookingAlertJob::dispatch($bookingIds, 'agent');


            // 🔹 Step 6: Format Response using master_token
            $responseData = [];

            // Get all bookings for this session
            $allBookings = Booking::with([
                'ticket' => function ($query) {
                    $query->select('id', 'name', 'event_id'); // Ticket name and relation to Event
                },
                'ticket.event' => function ($query) {
                    $query->select('id', 'name'); // Event name
                },
                'attendee' => function ($query) {
                    $query->select('id', 'name', 'email', 'number'); // Attendee details
                },
                'LSection' => function ($query) {
                    $query->select('id', 'name'); // Attendee details
                }
            ])->where('session_id', $dbSessionId)
                ->select('id', 'email', 'name', 'number', 'total_amount', 'discount', 'ticket_id', 'attendee_id', 'seat_name', 'section_id')
                ->get();


            // Get unique master tokens
            $masterTokens = $allBookings->whereNotNull('master_token')->pluck('master_token')->unique();

            // Add master bookings with their related bookings
            foreach ($masterTokens as $masterToken) {
                $masterBooking = MasterBooking::with([
                    'bookings' => function ($query) {
                        $query->select('id', 'master_booking_id', 'email', 'name', 'number', 'total_amount', 'discount', 'ticket_id', 'attendee_id');
                    },
                    'bookings.ticket' => function ($query) {
                        $query->select('id', 'name', 'event_id'); // Ticket fields
                    },
                    'bookings.ticket.event' => function ($query) {
                        $query->select('id', 'name'); // Event fields
                    },
                    'bookings.attendee' => function ($query) {
                        $query->select('id', 'name', 'email', 'number'); // Attendee fields
                    }
                ])->where('order_id', $masterToken)
                    ->first();


                if ($masterBooking) {
                    $responseData[] = $masterBooking->toArray();
                }
            }

            // Add individual bookings (those without master_token)
            $individualBookings = $allBookings->whereNull('master_token');
            foreach ($individualBookings as $booking) {
                $responseData[] = $booking->toArray();
            }



            // 🔹 Response with bookings grouped
            return response()->json([
                'status' => true,
                'message' => 'Tickets booked successfully',
                'bookings' => $responseData,
                'session_id' => $dbSessionId,
                'set_id' => $setId,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to book tickets',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function deductAgentCredits($user, $latestBalance, $totalAmount)
    {
        // 🔹 Step 4: Deduct Agent Balance
        if ($user->hasRole('Agent')) {
            $newTotalCredits = $latestBalance->total_credits - $totalAmount;

            $newBalance = new Balance();
            $newBalance->user_id = $user->id;
            $newBalance->total_credits = $newTotalCredits;
            $newBalance->new_credit = $totalAmount;
            $newBalance->booking_id = $bookings[0]->id ?? null;
            $newBalance->payment_method = $request->payment_method ?? 'cash';
            $newBalance->payment_type = 'debit';
            $newBalance->transaction_id = $this->generateTransactionId();
            $newBalance->description = 'agentBooking';
            $newBalance->save();
        }
    }

    public function export(Request $request)
    {
        $loggedInUser = Auth::user();
        $dates = $request->input('date') ? explode(',', $request->input('date')) : [Carbon::today()->format('Y-m-d')];

        // Optimize: Only select needed columns from the start
        $query = Booking::select([
                'bookings.id',
                'bookings.booking_type',
                'bookings.booking_by',
                'bookings.session_id',
                'bookings.created_at',
                'bookings.deleted_at',
                'bookings.ticket_id',
                'bookings.user_id'
            ])
            ->where('booking_type', 'agent')
            ->withTrashed()
            ->with([
                'ticket:id,name,event_id',
                'ticket.event:id,name,user_id',
                'ticket.event.user:id,organisation',
                'user:id,name',
                'agentUser:id,name'
            ]);

        // Role-based filtering
        if ($loggedInUser->hasRole('Admin')) {
            // Admin can see all bookings
        } elseif ($loggedInUser->hasRole('Organizer')) {
            // Organizer can only see bookings from their agents
            $query->whereHas('ticket.event', function ($q) use ($loggedInUser) {
                $q->where('user_id', $loggedInUser->id);
            });
        } elseif ($loggedInUser->hasRole('Agent')) {
            // Agent can only see their own bookings
            $query->where('booking_by', $loggedInUser->id);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }

        // Apply date filters with proper PostgreSQL date handling
        if ($dates) {
            if (count($dates) === 1) {
                $singleDate = Carbon::parse($dates[0])->toDateString();
                $query->whereDate('bookings.created_at', $singleDate);
            } elseif (count($dates) === 2) {
                $startDate = Carbon::parse($dates[0])->startOfDay();
                $endDate = Carbon::parse($dates[1])->endOfDay();
                $query->whereBetween('bookings.created_at', [$startDate, $endDate]);
            }
        }

        // Use chunk for large datasets to reduce memory usage
        $bookings = $query->latest('bookings.created_at')->get();

        $grouped = $bookings->groupBy('session_id')->map(function ($group) {
            $first = $group->first();
            return [
                'event_name' => $first->ticket->event->name ?? 'N/A',
                'organizer' => $first?->ticket?->event?->user?->organisation ?? 'N/A',
                'agent_name' => $first?->agentUser?->name ?? 'N/A',
                'user_name' => $first?->user?->name ?? 'N/A',
                'booking_date' => $first?->created_at?->format('Y-m-d') ?? 'N/A',
                'status' => $first?->trashed() ? 'Cancelled' : 'Active',
                'quantity' => $group->count(),
                'amount' => $first?->amount ?? 0,
            ];
        })->values();

        // return Excel::download(new AgentBookingExport($bookings), 'AgentBooking_export.xlsx');
        return Excel::download(new AgentBookingExport($grouped), 'AgentBooking_export.xlsx');
    }

    public function userFormNumber(Request $request, $id)
    {
        try {
            // Find user by number
            $user = User::where('number', $id)->first();

            if ($user) {
                return response()->json([
                    'status' => true,
                    'message' => 'User fetched successfully',
                    'user' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'photo' => $user->photo,
                        'doc' => $user->doc,
                        'company_name' => $user->company_name,
                    ],
                ], 200);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($type, $token)
    {
        try {
            // 🟢 Check for Master Booking
            $masterBooking = MasterBooking::where('booking_type', $type)
                ->where('order_id', $token)
                ->first();

            if ($masterBooking) {
                $bookingIds = $masterBooking->booking_id;

                if (!empty($bookingIds) && is_array($bookingIds)) {
                    // 🔍 Get related ticket IDs
                    $ticketIds = Booking::whereIn('id', $bookingIds)->pluck('ticket_id')->unique();

                    // 🔎 Check if any related ticket is already deleted
                    $deletedTicketExists = Ticket::onlyTrashed()->whereIn('id', $ticketIds)->exists();

                    if ($deletedTicketExists) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Cannot delete — related booking are already deleted.',
                        ], 200);
                    }

                    // ✅ Delete related bookings
                    Booking::whereIn('id', $bookingIds)->delete();
                }

                // ✅ Delete master booking
                $masterBooking->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Master Booking and related bookings deleted successfully.',
                ], 200);
            }

            // 🟠 Otherwise, check for single booking
            $normalBooking = Booking::where('booking_type', $type)
                ->where('token', $token)
                ->first();

            if ($normalBooking) {
                // 🔍 Check related ticket
                $ticket = Ticket::withTrashed()->find($normalBooking->ticket_id);

                if ($ticket && $ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete — related booking is already deleted.',
                    ], 400);
                }

                // ✅ Safe to delete
                $normalBooking->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Booking deleted successfully.',
                ], 200);
            }

            // 🟥 Not found
            return response()->json([
                'status' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function restoreBooking($type, $token)
    {
        try {
            // Step 1: Try restoring Master Booking
            $masterBooking = MasterBooking::withTrashed()
                ->where('booking_type', $type)
                ->where('order_id', $token)
                ->first();

            if ($masterBooking) {
                $bookingIds = $masterBooking->booking_id;

                if (!empty($bookingIds) && is_array($bookingIds)) {
                    // 🔍 Get related ticket_ids from those bookings
                    $ticketIds = Booking::withTrashed()
                        ->whereIn('id', $bookingIds)
                        ->pluck('ticket_id')
                        ->unique();

                    // 🔎 Check if any of these tickets are deleted
                    $deletedTicketExists = Ticket::onlyTrashed()
                        ->whereIn('id', $ticketIds)
                        ->exists();

                    if ($deletedTicketExists) {
                        return response()->json([
                            'status' => false,
                            'message' => 'This booking event is disabled.',
                        ], 200);
                    }

                    // ✅ Restore master booking
                    $masterBooking->restore();

                    // ✅ Restore related bookings
                    Booking::withTrashed()->whereIn('id', $bookingIds)->restore();

                    return response()->json([
                        'status' => true,
                        'message' => 'Master Booking and related bookings restored successfully.',
                    ], 200);
                }
            }

            // Step 2: Try restoring single booking
            $normalBooking = Booking::withTrashed()
                ->where('booking_type', $type)
                ->where('token', $token)
                ->first();

            if ($normalBooking) {
                // 🔍 Check related ticket status
                $ticket = Ticket::withTrashed()->find($normalBooking->ticket_id);

                if ($ticket && $ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot restore — related ticket is deleted.',
                    ], 200);
                }

                // ✅ Restore booking
                $normalBooking->restore();

                return response()->json([
                    'status' => true,
                    'message' => 'Booking restored successfully.',
                ], 200);
            }

            // Step 3: Nothing found
            return response()->json([
                'status' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }


    private function generateTransactionId()
    {
        return strtoupper(bin2hex(random_bytes(10))); // Generates a 20-character alphanumeric ID
    }


    public function ganerateCard($token)
    {
        // $order_id = Cache::get($token);
        $cacheKey = 'token_order_' . $token;
        $order_id = Cache::get($cacheKey);


        if (!$order_id) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired token'
            ], 403);
        }

        // Common helper function with caching
        $getUserDetails = function ($userId) {
            return Cache::remember("user_details_{$userId}", 3600, function () use ($userId) {
                $user = User::select('id', 'name', 'number', 'email')->find($userId);
                return $user ? [
                    'name' => $user->name ?? null,
                    'number' => $user->number ?? null,
                    'email' => $user->email ?? null,
                ] : null;
            });
        };

        // Helper function to get ticket_terms content from ContentMaster
        $getTicketTermsContent = function ($event) {
            if (!$event) return null;
            
            $ticketTermsId = $event->ticket_terms;
            if ($ticketTermsId && is_numeric($ticketTermsId)) {
                try {
                    $contentMaster = $event->ticket_terms()->select('content')->first();
                    return $contentMaster ? $contentMaster->content : null;
                } catch (\Exception $e) {
                    return null;
                }
            }
            return null;
        };

        // Optimize: Define eager loading once
        $eagerLoad = [
            'attendee:id,name,email,number,photo',
            'ticket:id,name,price,currency,background_image,event_id',
            'ticket.event:id,name,venue_id,date_range,start_time,entry_time,end_time,ticket_terms',
            'ticket.event.venue:id,address,city,state'
        ];

        // ===================== Agent Master (unified) =====================
        $master = MasterBooking::select('id', 'order_id', 'booking_id', 'booking_type', 'user_id')
            ->withTrashed()
            ->where('order_id', $order_id)
            ->where('booking_type', 'agent')
            ->first();

        if ($master) {
            $ids = is_array($master->booking_id) ? $master->booking_id : [];
            if (!empty($ids)) {
                $bookings = Booking::select('id', 'token', 'booking_type', 'created_at', 'ticket_id', 'attendee_id', 'total_amount')
                    ->withTrashed()
                    ->whereIn('id', $ids)
                    ->where('booking_type', 'agent')
                    ->with($eagerLoad)
                    ->get();
            }
        }
        // ===================== Booking Master =====================
        elseif ($master = MasterBooking::select('id', 'order_id', 'booking_id', 'user_id')
            ->withTrashed()
            ->where('order_id', $order_id)
            ->first()
        ) {
            $ids = is_array($master->booking_id) ? $master->booking_id : [];
            if (!empty($ids)) {
                $bookings = Booking::select('id', 'token', 'created_at', 'ticket_id', 'attendee_id', 'total_amount')
                    ->withTrashed()
                    ->whereIn('id', $ids)
                    ->with($eagerLoad)
                    ->get();
            }
        }
        // ===================== Sponsor Master (unified) =====================
        elseif ($master = MasterBooking::select('id', 'order_id', 'booking_id', 'booking_type', 'user_id')
            ->withTrashed()
            ->where('order_id', $order_id)
            ->where('booking_type', 'sponsor')
            ->first()
        ) {
            $ids = is_array($master->booking_id) ? $master->booking_id : [];
            if (!empty($ids)) {
                $bookings = Booking::select('id', 'token', 'booking_type', 'created_at', 'ticket_id', 'attendee_id', 'total_amount')
                    ->withTrashed()
                    ->whereIn('id', $ids)
                    ->where('booking_type', 'sponsor')
                    ->with($eagerLoad)
                    ->get();
            }
        }

        if (!empty($bookings ?? null)) {
            $userArray = [];
            $firstCardUrl = null;

            foreach ($bookings as $index => $booking) {
                if ($index === 0) {
                    $firstCardUrl = $booking->ticket->background_image ?? null;
                }

                $attendee = $booking->attendee;
                $userArray[] = [
                    'token' => $booking->token,
                    'booking_date' => $booking->created_at->format('d-m-Y'),
                    'attendee' => $attendee ? [
                        'name' => $attendee->name ?? '',
                        'email' => $attendee->email ?? '',
                        'phone' => $attendee->number ?? '',
                        'photo' => $attendee->photo ?? null,
                    ] : null,
                ];
            }

            return response()->json([
                'status' => true,
                'type' => 'master',
                'card_url' => $firstCardUrl,
                'ticket' => [
                    'price' => $bookings[0]->total_amount ?? null,
                    'name' => $bookings[0]->ticket->name ?? null,
                    'currency' => $bookings[0]->ticket->currency ?? null,
                ],
                'event' => [
                    'name' => $bookings[0]->ticket->event->name ?? null,
                    'state' => $bookings[0]->ticket->event->venue->state ?? null,
                    'city' => $bookings[0]->ticket->event->venue->city ?? null,
                    'date_range' => $bookings[0]->ticket->event->date_range ?? null,
                    'start_time' => $bookings[0]->ticket->event->start_time ?? null,
                    'entry_time' => $bookings[0]->ticket->event->entry_time ?? null,
                    'end_time' => $bookings[0]->ticket->event->end_time ?? null,
                    'address' => $bookings[0]->ticket->event->venue->address ?? null,
                    'ticket_terms' => $getTicketTermsContent($bookings[0]->ticket->event),
                ],
                'tokendata' => $token,
                'data' => $userArray,
                'users' => $getUserDetails($master->user_id)
            ]);
        }

        // ===================== Normal Agent (unified) =====================
        $booking = Booking::select('id', 'token', 'booking_type', 'created_at', 'ticket_id', 'attendee_id', 'user_id', 'total_amount')
            ->withTrashed()
            ->with($eagerLoad)
            ->where('token', $order_id)
            ->where('booking_type', 'agent')
            ->first();
        // ===================== Normal Booking =====================
        if (!$booking) {
            $booking = Booking::select('id', 'token', 'booking_type', 'created_at', 'ticket_id', 'attendee_id', 'user_id', 'total_amount')
                ->withTrashed()
                ->with($eagerLoad)
                ->where('token', $order_id)
                ->where('booking_type', 'online')
                ->first();
        }
        // ===================== Normal Sponsor (unified) =====================
        if (!$booking) {
            $booking = Booking::select('id', 'token', 'booking_type', 'created_at', 'ticket_id', 'attendee_id', 'user_id', 'total_amount')
                ->withTrashed()
                ->with($eagerLoad)
                ->where('token', $order_id)
                ->where('booking_type', 'sponsor')
                ->first();
        }

        if ($booking) {
            $attendee = $booking->attendee;
            return response()->json([
                'status' => true,
                'type' => 'normal',
                'card_url' => $booking->ticket->background_image ?? null,
                'ticket' => [
                    'price' => $booking->total_amount ?? null,
                    'name' => $booking->ticket->name ?? null,
                    'currency' => $booking->ticket->currency ?? null,
                ],
                'event' => [
                    'name' => $booking->ticket->event->name ?? null,
                    'state' => $booking->ticket->event->venue->state ?? null,
                    'city' => $booking->ticket->event->venue->city ?? null,
                    'date_range' => $booking->ticket->event->date_range ?? null,
                    'start_time' => $booking->ticket->event->start_time ?? null,
                    'entry_time' => $booking->ticket->event->entry_time ?? null,
                    'end_time' => $booking->ticket->event->end_time ?? null,
                    'address' => $booking->ticket->event->venue->address ?? null,
                    'ticket_terms' => $getTicketTermsContent($booking->ticket->event),
                ],
                'tokendata' => $token,
                'data' => [
                    [
                        'token' => $booking->token,
                        'booking_date' => $booking->created_at->format('d-m-Y'),
                        'attendee' => $attendee ? [
                            'name' => $attendee->name ?? '',
                            'email' => $attendee->email ?? '',
                            'phone' => $attendee->number ?? '',
                            'photo' => $attendee->photo ?? null,
                        ] : null,
                    ]
                ],
                'users' => $getUserDetails($booking->user_id)
            ]);
        }

        // ===================== Not Found =====================
        return response()->json(['status' => false, 'message' => 'Booking not found.'], 404);
    }

    public function generate(Request $request, $order_id)
    {
        $orderId = $order_id;

        if (!$orderId) {
            return response()->json(['status' => false, 'message' => 'Missing order_id'], 400);
        }

        // 🔍 STEP 1: Find event_id with optimized query (use UNION for better performance)
        $eventId = Cache::remember("event_for_order_{$orderId}", 300, function () use ($orderId) {
            // Try to find in bookings first (most common case)
            $eventId = Booking::where('token', $orderId)
                ->orWhere('master_token', $orderId)
                ->value('event_id');
            
            if (!$eventId) {
                // Then check master bookings
                $masterBooking = MasterBooking::where('order_id', $orderId)->first();
                if ($masterBooking && !empty($masterBooking->booking_id)) {
                    $eventId = Booking::whereIn('id', $masterBooking->booking_id)
                        ->value('event_id');
                }
            }
            
            return $eventId;
        });

        // 🔍 STEP 2: If event not found → invalid order
        if (!$eventId) {
            return response()->json(['status' => false, 'message' => 'Invalid order_id'], 403);
        }

        // 🔍 STEP 3: Check event status with caching (status is in event_controls table)
        $eventActive = Cache::remember("event_status_{$eventId}", 300, function () use ($eventId) {
            // Check if event exists and has active status in event_controls
            return Event::where('id', $eventId)
                ->whereHas('eventControls', function ($query) {
                    $query->where('status', 1);
                })
                ->exists();
        });

        if (!$eventActive) {
            return response()->json([
                'status' => false,
                'message' => 'Event is not active'
            ], 403);
        }



        // ✅ Check existence in unified bookings table
        $existsInBooking = Booking::where('token', $orderId)->exists();
        $existsInBookingMaster = MasterBooking::where('order_id', $orderId)->exists();

        if (!$existsInBooking && !$existsInBookingMaster) {
            return response()->json(['status' => false, 'message' => 'Invalid order_id'], 403);
        }

        // ✅ Allowed domain check
        // $allowedDomain = env('ALLOWED_DOMAIN', 'https://getyourticket.in/');
        // $referer = $request->headers->get('referer');

        // if (!$referer || strpos($referer, $allowedDomain) !== 0) {
        //     return response()->json(['status' => false, 'message' => 'Forbidden'], 200);
        // }

        // ✅ Token generation and caching
        $cacheKeyByOrder = 'token_for_order_' . $orderId;

        if (Cache::has($cacheKeyByOrder)) {
            $token = Cache::get($cacheKeyByOrder);

            // ✅ Even if token already exists, store reverse mapping again
            Cache::put('token_order_' . $token, $orderId, now()->addMinutes(05));
        } else {
            $token = Str::random(32);
            Cache::put($cacheKeyByOrder, $token, now()->addMinutes(05));
            Cache::put('token_order_' . $token, $orderId, now()->addMinutes(05));
        }

        return response()->json([
            'status' => true,
            'order_id' => $orderId,
            'group' => $existsInBookingMaster,
            'token' => $token
        ]);
    }
}
