<?php

namespace App\Http\Controllers;


use App\Models\AgentEvent;
use App\Models\Attndy;
use App\Models\Booking;
use App\Models\ComplimentaryBookings;
use App\Models\CorporateUser;
use App\Models\MasterBooking;
use App\Models\PosBooking;
use App\Models\ScanHistory;
use App\Services\DateRangeService;
use Carbon\Carbon;
use Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class ScanController extends Controller
{


    public function verifyTicket(Request $request, $orderId)
    {
        $loggedInUser = Auth::user();

        if ($loggedInUser->hasRole('Scanner')) {

            if (!$request->user_id || !$request->event_id) {
                return response()->json([
                    "status" => false,
                    "message" => "user_id and event_id are required for scanner"
                ], 400);
            }

            // Check agent_events permission
            $assigned = AgentEvent::where('user_id', $request->user_id)
                ->where('event_id', $request->event_id)
                ->exists();

            if (!$assigned) {
                return response()->json([
                    "status" => false,
                    "message" => "You are not assigned for this event"
                ], 403);
            }
        }


        try {
            $ticketRelations = [
                'ticket' => function ($q) {
                    $q->select('id', 'event_id', 'name');
                },
                'ticket.event' => function ($q) {
                    $q->select('id', 'event_key', 'name', 'category');
                },
                'ticket.event.category' => function ($q) {
                    $q->select('id', 'title', 'attendy_required');
                },
            ];

            $booking = Booking::with($ticketRelations + ['attendee', 'user:id,name,number,email'])
                ->where('event_id', $request->event_id)
                ->where('token', $orderId)
                ->first();

            $posBooking = PosBooking::with($ticketRelations + ['attendee'])
                ->where('event_id', $request->event_id)
                ->where('token', $orderId)
                ->first();

            $complimentaryBookings = ComplimentaryBookings::with($ticketRelations + ['attendee'])
                ->where('event_id', $request->event_id)
                ->where('token', $orderId)
                ->first();

            $masterBookings = MasterBooking::where('order_id', $orderId)->first();

            $sessionId = Str::uuid()->toString();
            $table = null;
            $bookingId = null;

            /*
            |--------------------------------------------------------------------------
            | CASE 1: POS BOOKING — CHECK FOR SET BOOKING FIRST
            |--------------------------------------------------------------------------
            */
            if ($posBooking) {

                $event = $posBooking->ticket->event;

                // Check if this is a SET booking (has set_id)
                if (!empty($posBooking->set_id)) {

                    // Fetch all POS bookings with same set_id
                    $setBookings = PosBooking::with($ticketRelations + ['attendee', 'user'])
                        ->where('set_id', $posBooking->set_id)
                        ->get();

                    if ($setBookings->count() > 1) {

                        // STORE SESSION FOR SET BOOKING
                        $bookingIds = $setBookings->pluck('id')->toArray();

                        Cache::put("scan_session:$sessionId", [
                            'order_id' => $orderId,
                            'booking_id' => implode(',', $bookingIds),
                            'table_name' => "pos_bookings_set",
                        ], now()->addMinutes(1));

                        // Total quantity
                        $totalQuantity = $setBookings->sum('quantity');

                        // Attendees array
                        $attendees = $setBookings->map(fn($b) => $b->attendee)->filter()->values();

                        // Tickets array (without event)
                        $tickets = $setBookings->filter(fn($b) => $b->ticket)->groupBy('ticket_id')->map(function ($group) {
                            $ticketData = $group->first()->ticket->toArray();
                            unset($ticketData['event']);
                            $ticketData['quantity'] = $group->sum('quantity') ?: $group->count();
                            return $ticketData;
                        })->values();

                        // Build set data
                        $setData = [
                            'set_id' => $posBooking->set_id,
                            'token' => $posBooking->token,
                            'total_bookings' => $setBookings->count(),
                            'quantity' => $totalQuantity,
                            'total_amount' => $setBookings->sum('total_amount'),
                            'discount' => $setBookings->sum('discount'),
                            'booking_date' => $setBookings->pluck('created_at')->filter()->first() ?? $posBooking->created_at,
                            'status' => $posBooking->status,
                            'name' => $setBookings->pluck('name')->filter()->first() ?? $posBooking->name,
                            'number' => $setBookings->pluck('number')->filter()->first() ?? $posBooking->number,
                            'email' => $setBookings->pluck('email')->filter()->first() ?? $posBooking->email,
                            'payment_method' => $posBooking->payment_method,
                            'attendees' => $attendees,
                            'tickets' => $tickets,
                            'user' => optional($setBookings->first())->user,
                        ];

                        return response()->json([
                            "status" => true,
                            "session_id" => $sessionId,
                            "is_master" => false,
                            "is_set" => true,
                            "bookings" => $setData,
                            "attendee_required" => $event->category->attendy_required ?? false,
                            "event" => $event,
                            "type" => "POS",
                        ]);
                    }
                }

                // SINGLE POS BOOKING (no set_id or only one in set)
                $table = "pos_bookings";
                $bookingId = $posBooking->id;

                Cache::put("scan_session:$sessionId", [
                    'order_id' => $orderId,
                    'booking_id' => $bookingId,
                    'table_name' => $table,
                ], now()->addMinutes(1));

                $ticketData = $posBooking->ticket->toArray();
                unset($ticketData['event']);

                return response()->json([
                    "status" => true,
                    "session_id" => $sessionId,
                    "is_master" => false,
                    "is_set" => false,
                    "bookings" => [
                        "id" => $posBooking->id,
                        "name" => $posBooking->name,
                        "number" => $posBooking->number,
                        "token" => $posBooking->token,
                        "quantity" => $posBooking->quantity ?? 1,
                        "status" => $posBooking->status,
                        "booking_date" => $posBooking->created_at,
                        "ticket_id" => $posBooking->ticket_id,
                        "attendee_id" => $posBooking->attendee_id,
                        "total_amount" => $posBooking->total_amount,
                        "attendee" => $posBooking->attendee,
                        "tickets" => $ticketData,
                        "user" => $posBooking->user,
                    ],
                    "attendee_required" => $event->category->attendy_required ?? false,
                    "event" => $event,
                    "type" => "POS",
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CASE 2: NORMAL / COMPLIMENTARY — SINGLE BOOKING
            |--------------------------------------------------------------------------
            */
            if ($booking || $complimentaryBookings) {

                $b = $booking ?? $complimentaryBookings;
                $event = $b->ticket->event;

                if ($booking) {
                    $table = "bookings";
                } else {
                    $table = "complimentary_bookings";
                }

                $bookingId = $b->id;

                Cache::put("scan_session:$sessionId", [
                    'order_id' => $orderId,
                    'booking_id' => $bookingId,
                    'table_name' => $table,
                ], now()->addMinutes(1));

                // ✅ Remove event from ticket to avoid duplication
                $ticketData = $b->ticket->toArray();
                unset($ticketData['event']);

                return response()->json([
                    "status" => true,
                    "session_id" => $sessionId,
                    "is_master" => false,
                    "is_set" => false,
                    "bookings" => [
                        "id" => $b->id,
                        "name" => $b->name,
                        "number" => $b->number,
                        "token" => $b->token,
                        "quantity" => $b->quantity ?? 1,
                        "status" => $b->status,
                        "ticket_id" => $b->ticket_id,
                        "attendee_id" => $b->attendee_id,
                        "total_amount" => $b->total_amount,
                        "booking_date" => $b->created_at,
                        "attendee" => $b->attendee,
                        "tickets" => $ticketData,
                        "user" => $b->user ?? null,
                    ],
                    "attendee_required" => $event->category->attendy_required ?? false,
                    "event" => $event,
                    "type" => "Online",
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CASE 3: MASTER BOOKING — MULTIPLE BOOKINGS
            |--------------------------------------------------------------------------
            */
            if ($masterBookings) {

                $bookingIds = $masterBookings->booking_id;

                // Fetch all linked bookings
                $relatedBookings = Booking::with($ticketRelations + ['attendee', 'user:id,name,number,email'])
                    ->whereIn('id', $bookingIds)
                    ->get();

                if ($relatedBookings->isEmpty()) {
                    return response()->json([
                        "status" => false,
                        "message" => "No bookings found"
                    ], 404);
                }

                // STORE SESSION FOR MASTER BOOKING
                Cache::put("scan_session:$sessionId", [
                    'order_id' => $orderId,
                    'booking_id' => implode(',', $bookingIds),
                    'table_name' => "master_bookings",
                ], now()->addMinutes(1));

                // Event from first booking
                $event = $relatedBookings->first()->ticket->event ?? null;

                $totalQuantity = $relatedBookings->count();

                // Attendees array
                $attendees = $relatedBookings->map(function ($b) {
                    return $b->attendee;
                })->filter()->values();

                // Tickets array (without event)
                $tickets = $relatedBookings->map(function ($b) {
                    $ticketData = $b->ticket->toArray();
                    unset($ticketData['event']);
                    return $ticketData;
                })->filter()->unique('id')->values();

                // Clean master booking data
                $masterData = $masterBookings->toArray();
                unset($masterData['bookings']);
                $masterData['quantity'] = $totalQuantity;
                $masterData['attendees'] = $attendees;
                $masterData['user'] = optional($relatedBookings->first())->user;
                $masterData['booking_date'] = $relatedBookings->pluck('created_at')->filter()->first();
                $masterData['tickets'] = $tickets;

                return response()->json([
                    "status" => true,
                    "session_id" => $sessionId,
                    "is_master" => true,
                    "is_set" => false,
                    "bookings" => $masterData,
                    "attendee_required" => $event->category->attendy_required ?? false,
                    "event" => $event,
                    "type" => "Online",
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | INVALID ORDER ID
            |--------------------------------------------------------------------------
            */
            return response()->json([
                "status" => false,
                "message" => "Invalid Ticket / Order ID"
            ], 404);

        } catch (\Exception $e) {

            return response()->json([
                "status" => false,
                "message" => "An error occurred: " . $e->getMessage()
            ], 500);
        }
    }
    public function ChekIn(Request $request, $sessionId)
    {
        try {
            // Fetch session
            $sessionData = Cache::get("scan_session:" . $sessionId);
    
            if (!$sessionData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired session ID'
                ], 404);
            }
    
            $orderId = $sessionData['order_id'];
            $bookingId = $sessionData['booking_id'];
            $table = $sessionData['table_name'];
    
            // MODEL MAP
            $modelMap = [
                'bookings' => Booking::class,
                'pos_bookings' => PosBooking::class,
                'complimentary_bookings' => ComplimentaryBookings::class,
                'master_bookings' => MasterBooking::class,
                'pos_bookings_set' => PosBooking::class, // ✅ Added POS Set
            ];
    
            if (!isset($modelMap[$table])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid table name'
                ], 400);
            }
    
            $model = $modelMap[$table];
    
            /*
            |--------------------------------------------------------------------------
            | CASE 1: MASTER BOOKING
            |--------------------------------------------------------------------------
            */
            if ($table == "master_bookings") {
    
                $bookingRecord = $model::where('order_id', $orderId)->first();
    
                if (!$bookingRecord) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Master booking not found'
                    ], 404);
                }
    
                $bookingIds = $bookingRecord->booking_id;
                $relatedBookings = Booking::whereIn('id', $bookingIds)->get();
    
                foreach ($relatedBookings as $b) {
                    $b->status = 1;
                    $b->is_scaned = true;
                    $b->save();
                }
    
                Cache::forget("scan_session:" . $sessionId);
    
                return response()->json([
                    'status' => true,
                    'type' => 'Master',
                    'message' => 'Master booking scanned successfully',
                    'scanned_count' => $relatedBookings->count()
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | CASE 2: POS SET BOOKING
            |--------------------------------------------------------------------------
            */
            if ($table == "pos_bookings_set") {
    
                // booking_id contains comma-separated IDs
                $bookingIds = array_map('intval', explode(',', $bookingId));
    
                $setBookings = PosBooking::whereIn('id', $bookingIds)->get();
    
                if ($setBookings->isEmpty()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'POS set bookings not found'
                    ], 404);
                }
    
                foreach ($setBookings as $b) {
                    $b->status = 1;
                    $b->is_scaned = true;
                    $b->save();
                }
    
                Cache::forget("scan_session:" . $sessionId);
    
                return response()->json([
                    'status' => true,
                    'type' => 'POS Set',
                    'message' => 'POS set booking scanned successfully',
                    'scanned_count' => $setBookings->count()
                ]);
            }
    
            /*
            |--------------------------------------------------------------------------
            | CASE 3: SINGLE BOOKING (Normal, POS, Complimentary)
            |--------------------------------------------------------------------------
            */
            $bookingRecord = $model::where('id', $bookingId)
                ->where('token', $orderId)
                ->first();
    
            if (!$bookingRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Record not found in table: ' . $table
                ], 404);
            }
    
            $bookingRecord->status = 1;
            $bookingRecord->is_scaned = true;
            $bookingRecord->save();
    
            Cache::forget("scan_session:" . $sessionId);
    
            return response()->json([
                'status' => true,
                'type' => ucfirst(str_replace('_', ' ', $table)),
                'message' => 'Ticket scanned successfully',
                'scanned_count' => 1
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    private function logScanHistory($userId, $scannerId, $tokenId, $bookingSource = null)
    {
        $now = now()->toDateTimeString();

        $query = ScanHistory::where('user_id', $userId)
            ->where('scanner_id', $scannerId)
            ->where('token', $tokenId);

        if ($bookingSource !== null) {
            $query->where('booking_source', $bookingSource);
        }

        $history = $query->first();


        if ($history) {
            $times = json_decode($history->scan_time ?? '[]', true);
            $times[] = $now;


            $history->scan_time = json_encode($times);
            $history->count += 1;
            // $history->token = $tokenId;
            $history->save();
        } else {
            $history = ScanHistory::create([
                'user_id' => $userId,
                'scanner_id' => $scannerId,
                'token' => $tokenId,
                'booking_source' => $bookingSource,
                'scan_time' => json_encode([$now]),
                'count' => 1,
            ]);
        }

        return $history;
    }

    public function getScanHistories(Request $request, DateRangeService $dateRangeService)
    {
        try {
            $dateRange = $dateRangeService->parseDateRangeSafe($request);
            
            if (isset($dateRange['error'])) {
                return response()->json([
                    'status' => false,
                    'message' => $dateRange['error']
                ], 400);
            }

            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];

            $user = auth()->user();

            $histories = ScanHistory::with(['user:id,name', 'scanner:id,name'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $histories
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function attendeesChekIn($orderId)
    {
        try {
            // First: Try to fetch from Attndy table
            $attendee = Attndy::where('token', $orderId)->with('event.user')->first();

            // If not found in Attndy, try in CorporateBooking
            if (!$attendee) {
                $corporate = CorporateUser::where('token', $orderId)->first();

                if (!$corporate) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No attendee found with this token'
                    ], 404);
                }

                return response()->json([
                    'status' => true,
                    'bookings' => $corporate,
                    'source' => 'corporate'
                ], 200);
            }

            return response()->json([
                'status' => true,
                'bookings' => $attendee,
                'source' => 'attndy'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function attendeesVerify($orderId)
    {
        try {
            // First try in Attndy table
            $attendee = Attndy::where('token', $orderId)->with('event.user')->first();

            if ($attendee) {
                $attendee->status = true;
                $attendee->save();

                $this->logScanHistory($attendee->user_id, auth()->id(), $attendee->token, 'attendee');

                return response()->json([
                    'status' => true,
                    'bookings' => $attendee,
                    'source' => 'attndy'
                ], 200);
            }

            // If not found in Attndy, try CorporateBooking table
            $corporate = CorporateUser::where('token', $orderId)->with('event.user')->first();

            if ($corporate) {
                $corporate->status = true;
                $corporate->save();

                $this->logScanHistory($corporate->user_id, auth()->id(), $corporate->token, 'corporate');

                return response()->json([
                    'status' => true,
                    'bookings' => $corporate,
                    'source' => 'corporate'
                ], 200);
            }

            // If not found in both
            return response()->json([
                'status' => false,
                'message' => 'No attendee found with this token'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred: ' . $e->getMessage(),
            ], 500);
        }
    }
}
