<?php

namespace App\Http\Controllers;

use App\Exports\AgentReportExport;
use App\Exports\EventReportExport;
use App\Exports\PosReportExport;
use App\Models\Booking;
use App\Models\Event;
use App\Models\PosBooking;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DateRangeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function EventReport(Request $request, DateRangeService $dateRangeService)
    {
        $result = $this->buildEventReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return response()->json(['data' => $result['data']], 200);
    }

    private function buildEventReportData(Request $request, DateRangeService $dateRangeService): array
    {
        $dateRange = $dateRangeService->parseDateRangeSafe($request);

        if (isset($dateRange['error'])) {
            return ['error' => $dateRange['error'], 'status' => 400];
        }

        $startDate = $dateRange['startDate'];
        $endDate = $dateRange['endDate'];

        $eventType = $request->type;

        $eventsQuery = Event::with([
            'tickets.bookings' => function ($query) use ($startDate, $endDate) {
                $query->select('id', 'ticket_id', 'user_id', 'gateway', 'status', 'discount', 'created_at', 'booking_by', 'booking_type', 'total_amount')
                    ->with(['bookingTax:id,booking_id,base_amount,convenience_fee']);
                $query->whereBetween('created_at', [$startDate, $endDate]);
            },
            'tickets.posBookings' => function ($query) use ($startDate, $endDate) {
                $query->select('id', 'ticket_id', 'quantity', 'status', 'discount', 'created_at', 'total_amount')
                    ->with(['bookingTax:id,booking_id,base_amount,convenience_fee']);
                $query->whereBetween('created_at', [$startDate, $endDate]);
            },
            'user'
        ]);

        $today = Carbon::today()->toDateString();
        if ($eventType == 'all') {
            $events = $eventsQuery->get(['id', 'name as event_name', 'user_id']);
        } else {
            $events = $eventsQuery->where(function ($query) use ($today) {
                $query->where('date_range', 'LIKE', "%$today%")
                    ->orWhereRaw("? BETWEEN SPLIT_PART(date_range, ',', 1) AND SPLIT_PART(date_range, ',', 2)", [$today]);
            })->get(['id', 'name as event_name', 'user_id']);
        }

        $eventReport = [];

        foreach ($events as $event) {
            $totalTickets = $event->tickets->sum(function ($ticket) {
                return (int)$ticket->ticket_quantity;
            });

            // Initialize totals
            $totalEventBookings = $totalAgentBookings = $totalNonAgentBookings = $totalPosQuantity = $totalIns = 0;
            $totalSponsorBookings = 0; // ✅ NEW
            $totalEasebuzzTotalAmount = $totalInstamojoTotalAmount = $totalRazorpayTotalAmount = $totalPhonepeTotalAmount  = $totalCashfreeTotalAmount = 0; // ✅ NEW gateways

            $totalOnlineBaseAmount = $totalOnlineConvenienceFee = $totalOnlineDiscount = 0;
            $totalAgentBaseAmount = $totalAgentConvenienceFee = $totalAgentDiscount = 0;
            $totalSponsorBaseAmount = $totalSponsorConvenienceFee = $totalSponsorDiscount = 0; // ✅ NEW
            $totalPosBaseAmount = $totalPosConvenienceFee = $totalPosDiscount = 0;

            $eventSummaryIndex = count($eventReport);
            $eventReport[] = [
                'event_name' => $event->event_name,
                'ticket_quantity' => $totalTickets,
                'available_tickets' => 0,
                'total_bookings' => 0,
                'agent_bookings' => 0,
                'sponsor_bookings' => 0, // ✅ NEW
                'non_agent_bookings' => 0,
                'pos_bookings_quantity' => 0,
                'total_ins' => 0,
                'easebuzz_total_amount' => 0,
                'instamojo_total_amount' => 0,
                'razorpay_total_amount' => 0, // ✅ NEW
                'phonepe_total_amount' => 0,  // ✅ NEW
                'online_base_amount' => 0,
                'online_convenience_fee' => 0,
                'online_discount' => 0,
                'agent_base_amount' => 0,
                'agent_convenience_fee' => 0,
                'agent_discount' => 0,
                'sponsor_base_amount' => 0, // ✅ NEW
                'sponsor_convenience_fee' => 0, // ✅ NEW
                'sponsor_discount' => 0, // ✅ NEW
                'pos_base_amount' => 0,
                'pos_convenience_fee' => 0,
                'pos_discount' => 0,
                'organizer' => $event->user->name ?? 'N/A',
                'parent' => true
            ];

            foreach ($event->tickets as $ticket) {
                $totalTicketBookings = $ticket->bookings->count();
                $totalPosQuantityForTicket = $ticket->posBookings->sum(fn($b) => (int)$b->quantity);
                $totalTicketCheckIns = $ticket->bookings->where('status', 1)->count();
                $totalPosCheckInsForTicket = $ticket->posBookings->where('status', 1)->sum(fn($b) => (int)$b->quantity);

                // Separate booking types
                $onlineBookings = $ticket->bookings()->whereNull('deleted_at')->where('booking_type', 'online')->get();
                $agentBookings = $ticket->bookings()->whereNull('deleted_at')->where('booking_type', 'agent')->get();
                $sponsorBookings = $ticket->bookings()->whereNull('deleted_at')->where('booking_type', 'sponsor')->get();

                $agentBookingsCount = $agentBookings->count();
                $sponsorBookingsCount = $sponsorBookings->count();
                $nonAgentBookingsCount = $onlineBookings->count();

                // ✅ Gateway totals for online
                $onlineEasebuzzTotalAmount = (float)$onlineBookings->where('gateway', 'easebuzz')->sum('total_amount');
                $onlineInstamojoTotalAmount = (float)$onlineBookings->where('gateway', 'instamojo')->sum('total_amount');
                $onlineRazorpayTotalAmount = (float)$onlineBookings->where('gateway', 'razorpay')->sum('total_amount');
                $onlinePhonepeTotalAmount = (float)$onlineBookings->where('gateway', 'phonepe')->sum('total_amount');
                $onlineCashfreeTotalAmount = (float)$onlineBookings->where('gateway', 'cashfree')->sum('total_amount');

                // ✅ Online
                $onlineBaseAmount = $onlineBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->base_amount : 0);
                $onlineConvenienceFee = $onlineBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->convenience_fee : 0);
                $onlineDiscount = $onlineBookings->sum(fn($i) => is_numeric($i->discount) ? (float)$i->discount : 0);

                // ✅ Agent
                $agentBaseAmount = $agentBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->base_amount : 0);
                $agentConvenienceFee = $agentBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->convenience_fee : 0);
                $agentDiscount = $agentBookings->sum(fn($i) => is_numeric($i->discount) ? (float)$i->discount : 0);

                // ✅ Sponsor
                $sponsorBaseAmount = $sponsorBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->base_amount : 0);
                $sponsorConvenienceFee = $sponsorBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->convenience_fee : 0);
                $sponsorDiscount = $sponsorBookings->sum(fn($i) => is_numeric($i->discount) ? (float)$i->discount : 0);

                // ✅ POS
                $posBaseAmount = $ticket->posBookings->sum(fn($i) => is_numeric($i->total_amount) ? (float)$i->total_amount : 0);
                $posConvenienceFee = $ticket->posBookings->sum(fn($i) => $i->bookingTax ? (float)$i->bookingTax->convenience_fee : 0);
                $posDiscount = $ticket->posBookings->sum(fn($i) => is_numeric($i->discount) ? (float)$i->discount : 0);

                // Update totals
                $totalEventBookings += $totalTicketBookings;
                $totalAgentBookings += $agentBookingsCount;
                $totalSponsorBookings += $sponsorBookingsCount;
                $totalNonAgentBookings += $nonAgentBookingsCount;
                $totalPosQuantity += $totalPosQuantityForTicket;
                $totalIns += $totalTicketCheckIns + $totalPosCheckInsForTicket;

                $totalEasebuzzTotalAmount += $onlineEasebuzzTotalAmount;
                $totalInstamojoTotalAmount += $onlineInstamojoTotalAmount;
                $totalRazorpayTotalAmount += $onlineRazorpayTotalAmount;
                $totalPhonepeTotalAmount += $onlinePhonepeTotalAmount;
                $totalCashfreeTotalAmount += $onlineCashfreeTotalAmount;

                $totalOnlineBaseAmount += $onlineBaseAmount;
                $totalOnlineConvenienceFee += $onlineConvenienceFee;
                $totalOnlineDiscount += $onlineDiscount;

                $totalAgentBaseAmount += $agentBaseAmount;
                $totalAgentConvenienceFee += $agentConvenienceFee;
                $totalAgentDiscount += $agentDiscount;

                $totalSponsorBaseAmount += $sponsorBaseAmount;
                $totalSponsorConvenienceFee += $sponsorConvenienceFee;
                $totalSponsorDiscount += $sponsorDiscount;

                $totalPosBaseAmount += $posBaseAmount;
                $totalPosConvenienceFee += $posConvenienceFee;
                $totalPosDiscount += $posDiscount;

                // ✅ Available tickets (subtract both online bookings + POS quantity)
                $availableTickets = max(0, (int)$ticket->ticket_quantity - ($totalTicketBookings + $totalPosQuantityForTicket));

                // Child row
                $eventReport[] = [
                    'event_name' => $event->event_name . ' (' . $ticket->name . ')',
                    'organizer' => '-',
                    'ticket_quantity' => (int)$ticket->ticket_quantity,
                    'available_tickets' => $availableTickets,
                    'total_bookings' => $totalTicketBookings,
                    'agent_bookings' => $agentBookingsCount,
                    'sponsor_bookings' => $sponsorBookingsCount,
                    'non_agent_bookings' => $nonAgentBookingsCount,
                    'pos_bookings_quantity' => $totalPosQuantityForTicket,
                    'total_ins' => $totalTicketCheckIns + $totalPosCheckInsForTicket,
                    'easebuzz_total_amount' => $onlineEasebuzzTotalAmount,
                    'instamojo_total_amount' => $onlineInstamojoTotalAmount,
                    'razorpay_total_amount' => $onlineRazorpayTotalAmount,
                    'phonepe_total_amount' => $onlinePhonepeTotalAmount,
                    'online_base_amount' => $onlineBaseAmount,
                    'online_convenience_fee' => $onlineConvenienceFee,
                    'online_discount' => $onlineDiscount,
                    'agent_base_amount' => $agentBaseAmount,
                    'agent_convenience_fee' => $agentConvenienceFee,
                    'agent_discount' => $agentDiscount,
                    'sponsor_base_amount' => $sponsorBaseAmount,
                    'sponsor_convenience_fee' => $sponsorConvenienceFee,
                    'sponsor_discount' => $sponsorDiscount,
                    'pos_base_amount' => $posBaseAmount,
                    'pos_convenience_fee' => $posConvenienceFee,
                    'pos_discount' => $posDiscount,
                    'parent' => false
                ];
            }

            // ✅ Total available tickets (for parent summary)
            $totalAvailableTickets = $event->tickets->sum(function ($t) {
                $booked = $t->bookings->count();
                $posQty = $t->posBookings->sum('quantity');
                return max(0, (int)$t->ticket_quantity - ($booked + $posQty));
            });

            // ✅ Update parent summary
            $eventReport[$eventSummaryIndex]['total_bookings'] = $totalEventBookings;
            $eventReport[$eventSummaryIndex]['agent_bookings'] = $totalAgentBookings;
            $eventReport[$eventSummaryIndex]['sponsor_bookings'] = $totalSponsorBookings;
            $eventReport[$eventSummaryIndex]['non_agent_bookings'] = $totalNonAgentBookings;
            $eventReport[$eventSummaryIndex]['pos_bookings_quantity'] = $totalPosQuantity;
            $eventReport[$eventSummaryIndex]['total_ins'] = $totalIns;
            $eventReport[$eventSummaryIndex]['easebuzz_total_amount'] = $totalEasebuzzTotalAmount;
            $eventReport[$eventSummaryIndex]['instamojo_total_amount'] = $totalInstamojoTotalAmount;
            $eventReport[$eventSummaryIndex]['razorpay_total_amount'] = $totalRazorpayTotalAmount;
            $eventReport[$eventSummaryIndex]['phonepe_total_amount'] = $totalPhonepeTotalAmount;
            $eventReport[$eventSummaryIndex]['cashfree_total_amount'] = $totalCashfreeTotalAmount;
            $eventReport[$eventSummaryIndex]['online_base_amount'] = $totalOnlineBaseAmount;
            $eventReport[$eventSummaryIndex]['online_convenience_fee'] = $totalOnlineConvenienceFee;
            $eventReport[$eventSummaryIndex]['online_discount'] = $totalOnlineDiscount;
            $eventReport[$eventSummaryIndex]['agent_base_amount'] = $totalAgentBaseAmount;
            $eventReport[$eventSummaryIndex]['agent_convenience_fee'] = $totalAgentConvenienceFee;
            $eventReport[$eventSummaryIndex]['agent_discount'] = $totalAgentDiscount;
            $eventReport[$eventSummaryIndex]['sponsor_base_amount'] = $totalSponsorBaseAmount;
            $eventReport[$eventSummaryIndex]['sponsor_convenience_fee'] = $totalSponsorConvenienceFee;
            $eventReport[$eventSummaryIndex]['sponsor_discount'] = $totalSponsorDiscount;
            $eventReport[$eventSummaryIndex]['pos_base_amount'] = $totalPosBaseAmount;
            $eventReport[$eventSummaryIndex]['pos_convenience_fee'] = $totalPosConvenienceFee;
            $eventReport[$eventSummaryIndex]['pos_discount'] = $totalPosDiscount;
            $eventReport[$eventSummaryIndex]['available_tickets'] = $totalAvailableTickets;
        }

        return [
            'data' => $eventReport,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }


    public function AgentReport(Request $request, DateRangeService $dateRangeService)
    {
        $result = $this->buildAgentReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return response()->json([
            'status' => true,
            'message' => 'Agent Report fetched successfully',
            'date_range' => [
                'start' => $result['startDate']->toDateString(),
                'end' => $result['endDate']->toDateString(),
            ],
            'data' => $result['data'],
        ]);
    }

    private function buildAgentReportData(Request $request, DateRangeService $dateRangeService): array
    {
        try {
            $loggedInUser = Auth::user();

            $dateRange = $dateRangeService->parseDateRangeSafe($request);

            if (isset($dateRange['error'])) {
                return ['error' => $dateRange['error'], 'status' => 400];
            }

            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];

            // 👤 Fetch agents based on role
            if ($loggedInUser->hasRole('Admin')) {
                $agents = User::whereHas('roles', fn($q) => $q->where('name', 'Agent'))
                    ->with('reportingUser')
                    ->get();
            } else {
                $agents = $loggedInUser->usersUnder()
                    ->whereHas('roles', fn($q) => $q->where('name', 'Agent'))
                    ->with('reportingUser')
                    ->get();
            }

            $today = Carbon::today();

            // 🧾 Prepare report
            $report = $agents->map(function ($agent) use ($startDate, $endDate, $today) {
                // Fetch bookings where booking_by = agent_id
                $bookings = Booking::where('booking_type', 'agent')->where('booking_by', $agent->id)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                // Count and sum by payment method
                $totalUPI = $bookings->where('payment_method', 'UPI')->count();
                $totalCash = $bookings->where('payment_method', 'Cash')->count();
                $totalNetBanking = $bookings->where('payment_method', 'Net Banking')->count();

                $totalUPIAmount = $bookings->where('payment_method', 'UPI')->sum('total_amount');
                $totalCashAmount = $bookings->where('payment_method', 'Cash')->sum('total_amount');
                $totalNetBankingAmount = $bookings->where('payment_method', 'Net Banking')->sum('total_amount');
                $totalDiscount = $bookings->sum('discount');

                // Today’s bookings only
                $todayBookings = $bookings->filter(fn($b) => $b->created_at->isSameDay($today));
                $todayTotalAmount = $todayBookings->sum('total_amount');
                $todayBookingCount = $todayBookings->count();

                return [
                    'agent_name' => $agent->name,
                    'organizer_name' => $agent->reportingUser->organisation ?? 'N/A',

                    'total_UPI_bookings' => $totalUPI,
                    'total_Cash_bookings' => $totalCash,
                    'total_Net_Banking_bookings' => $totalNetBanking,
                    'total_bookings' => $totalUPI + $totalCash + $totalNetBanking,

                    'total_UPI_amount' => $totalUPIAmount,
                    'total_Cash_amount' => $totalCashAmount,
                    'total_Net_Banking_amount' => $totalNetBankingAmount,
                    'total_amount' => $totalUPIAmount + $totalCashAmount + $totalNetBankingAmount,
                    'total_discount' => $totalDiscount,

                    'today_total_amount' => $todayTotalAmount,
                    'today_booking_count' => $todayBookingCount,
                ];
            });

            return [
                'data' => $report,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'status' => 500];
        }
    }

    public function PosReport(Request $request, DateRangeService $dateRangeService)
    {
        $result = $this->buildPosReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return response()->json([
            'status' => true,
            'message' => 'POS report fetched successfully',
            'date_range' => [
                'start' => $result['startDate']->toDateString(),
                'end' => $result['endDate']->toDateString(),
            ],
            'data' => $result['data'],
        ]);
    }

    private function buildPosReportData(Request $request, DateRangeService $dateRangeService): array
    {
        try {
            $loggedInUser = Auth::user();

            // 🗓 Date logic
            $dateRange = $dateRangeService->parseDateRangeSafe($request);

            if (isset($dateRange['error'])) {
                return ['error' => $dateRange['error'], 'status' => 400];
            }

            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];

            // 👤 Role wise users fetch
            if ($loggedInUser->hasRole('Admin')) {
                $underUsers = User::whereHas('roles', fn($q) => $q->where('name', 'POS'))
                    ->with(['PosBooking' => fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]), 'reportingUser'])
                    ->get();
            } else {
                $underUsers = $loggedInUser->usersUnder()
                    ->whereHas('roles', fn($q) => $q->where('name', 'POS'))
                    ->with(['PosBooking' => fn($q) => $q->whereBetween('created_at', [$startDate, $endDate]), 'reportingUser'])
                    ->get();
            }

            // 🧾 Format report data
            $report = $underUsers->map(function ($user) {
                $bookings = $user->PosBooking;

                $todayBookings = $bookings
                    ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()]);

                return [
                    'pos_user_name' => $user->name,
                    'organizer_name' => $user->reportingUser->name ?? 'N/A',
                    // totals for export view
                    'total_bookings' => $bookings->sum('quantity'),
                    'total_amount' => $bookings->sum('total_amount'),
                    'total_discount' => $bookings->sum('discount'),
                    'total_UPI_amount' => $bookings->where('payment_method', 'UPI')->sum('total_amount'),
                    'total_Cash_amount' => $bookings->where('payment_method', 'Cash')->sum('total_amount'),
                    'total_Net_Banking_amount' => $bookings->where('payment_method', 'Net Banking')->sum('total_amount'),
                    // 🎟 Ticket counts (also used as booking counts in export)
                    'total_UPI_bookings' => $bookings->where('payment_method', 'UPI')->sum('quantity'),
                    'total_Cash_bookings' => $bookings->where('payment_method', 'Cash')->sum('quantity'),
                    'total_Net_Banking_bookings' => $bookings->where('payment_method', 'Net Banking')->sum('quantity'),

                    // 🎫 Today total tickets (all)
                    'today_ticket_total' => $todayBookings
                        ->whereIn('payment_method', ['UPI', 'Cash', 'Net Banking'])
                        ->sum('quantity'),
                    // Totals needed by export blade
                    'today_total_amount' => $todayBookings->sum('total_amount'),
                    'today_booking_count' => $todayBookings->sum('quantity'),
                ];
            });

            return [
                'data' => $report,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage(), 'status' => 500];
        }
    }


    public function exportEventReport(Request $request, DateRangeService $dateRangeService)
    {
        // Reuse the same data builder used by the API response
        $result = $this->buildEventReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return Excel::download(new EventReportExport($result['data']), 'Event_Report.xlsx');
      

        if (isset($dateRange['error'])) {
            return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
        }

        if ($eventType == 'all') {
            $events = $eventsQuery->get(['id', 'name as event_name', 'user_id']);
        } else {
            $events = $eventsQuery->where(function ($query) use ($today) {
                $query->where('date_range', 'LIKE', "%$today%")
                    ->orWhereRaw("? BETWEEN SPLIT_PART(date_range, ',', 1) AND SPLIT_PART(date_range, ',', 2)", [$today]);
            })->get(['id', 'name as event_name', 'user_id']);
        }

        $eventReport = [];

        foreach ($events as $event) {
            $totalTickets = $event->tickets->sum('ticket_quantity');
            $totalEventBookings = $totalAgentBookings = $totalSponsorBookings = $totalNonAgentBookings = $totalPosQuantity = $totalIns = 0;

            $totalEasebuzzTotalAmount = $totalInstamojoTotalAmount = $totalRazorpayTotalAmount = $totalPhonepeTotalAmount  = $totalCashfreeTotalAmount = 0;

            $totalOnlineBaseAmount = $totalOnlineConvenienceFee = $totalOnlineDiscount = 0;
            $totalAgentBaseAmount = $totalAgentConvenienceFee = $totalAgentDiscount = 0;
            $totalSponsorBaseAmount = $totalSponsorConvenienceFee = $totalSponsorDiscount = 0;
            $totalPosBaseAmount = $totalPosConvenienceFee = $totalPosDiscount = 0;

            $eventSummaryIndex = count($eventReport);
            $eventReport[] = [
                'event_name' => $event->event_name,
                'ticket_quantity' => $totalTickets,
                'available_tickets' => 0,
                'total_bookings' => 0,
                'agent_bookings' => 0,
                'sponsor_bookings' => 0,
                'non_agent_bookings' => 0,
                'pos_bookings_quantity' => 0,
                'total_ins' => 0,
                'easebuzz_total_amount' => 0,
                'instamojo_total_amount' => 0,
                'razorpay_total_amount' => 0,
                'phonepe_total_amount' => 0,
                'cashfree_total_amount' => 0,
                'online_base_amount' => 0,
                'online_convenience_fee' => 0,
                'online_discount' => 0,
                'agent_base_amount' => 0,
                'agent_convenience_fee' => 0,
                'agent_discount' => 0,
                'sponsor_base_amount' => 0,
                'sponsor_convenience_fee' => 0,
                'sponsor_discount' => 0,
                'pos_base_amount' => 0,
                'pos_convenience_fee' => 0,
                'pos_discount' => 0,
                'organizer' => $event->user->name ?? 'N/A',
                'parent' => true
            ];

            foreach ($event->tickets as $ticket) {
                $onlineBookings = $ticket->bookings()->where('booking_type', 'online')->get();
                $agentBookings = $ticket->bookings()->where('booking_type', 'agent')->get();
                $sponsorBookings = $ticket->bookings()->where('booking_type', 'sponsor')->get();

                $totalTicketBookings = $ticket->bookings->count();
                $totalPosQuantityForTicket = $ticket->posBookings->sum('quantity');
                $totalTicketCheckIns = $ticket->bookings->where('status', 1)->count();
                $totalPosCheckInsForTicket = $ticket->posBookings->where('status', 1)->sum('quantity');

                $agentBookingsCount = $agentBookings->count();
                $sponsorBookingsCount = $sponsorBookings->count();
                $nonAgentBookingsCount = $onlineBookings->count();

                // Gateways
                $onlineEasebuzzTotalAmount = $onlineBookings->where('gateway', 'easebuzz')->sum('total_amount');
                $onlineInstamojoTotalAmount = $onlineBookings->where('gateway', 'instamojo')->sum('total_amount');
                $onlineRazorpayTotalAmount = $onlineBookings->where('gateway', 'razorpay')->sum('total_amount');
                $onlinePhonepeTotalAmount = $onlineBookings->where('gateway', 'phonepe')->sum('total_amount');
                $onlineCashfreeTotalAmount = $onlineBookings->where('gateway', 'cashfree')->sum('total_amount');

                // Base / Convenience / Discount
                $onlineBaseAmount = $onlineBookings->sum(fn($i) => $i->bookingTax->base_amount ?? 0);
                $onlineConvenienceFee = $onlineBookings->sum(fn($i) => $i->bookingTax->convenience_fee ?? 0);
                $onlineDiscount = $onlineBookings->sum(fn($i) => $i->discount ?? 0);

                $agentBaseAmount = $agentBookings->sum(fn($i) => $i->bookingTax->base_amount ?? 0);
                $agentConvenienceFee = $agentBookings->sum(fn($i) => $i->bookingTax->convenience_fee ?? 0);
                $agentDiscount = $agentBookings->sum(fn($i) => $i->discount ?? 0);

                $sponsorBaseAmount = $sponsorBookings->sum(fn($i) => $i->bookingTax->base_amount ?? 0);
                $sponsorConvenienceFee = $sponsorBookings->sum(fn($i) => $i->bookingTax->convenience_fee ?? 0);
                $sponsorDiscount = $sponsorBookings->sum(fn($i) => $i->discount ?? 0);

                $posBaseAmount = $ticket->posBookings->sum(fn($i) => $i->total_amount ?? 0);
                $posConvenienceFee = $ticket->posBookings->sum(fn($i) => $i->bookingTax->convenience_fee ?? 0);
                $posDiscount = $ticket->posBookings->sum(fn($i) => $i->discount ?? 0);

                // Totals
                $totalEventBookings += $totalTicketBookings;
                $totalAgentBookings += $agentBookingsCount;
                $totalSponsorBookings += $sponsorBookingsCount;
                $totalNonAgentBookings += $nonAgentBookingsCount;
                $totalPosQuantity += $totalPosQuantityForTicket;
                $totalIns += $totalTicketCheckIns + $totalPosCheckInsForTicket;

                $totalEasebuzzTotalAmount += $onlineEasebuzzTotalAmount;
                $totalInstamojoTotalAmount += $onlineInstamojoTotalAmount;
                $totalRazorpayTotalAmount += $onlineRazorpayTotalAmount;
                $totalPhonepeTotalAmount += $onlinePhonepeTotalAmount;
                $totalCashfreeTotalAmount += $onlineCashfreeTotalAmount;

                $totalOnlineBaseAmount += $onlineBaseAmount;
                $totalOnlineConvenienceFee += $onlineConvenienceFee;
                $totalOnlineDiscount += $onlineDiscount;

                $totalAgentBaseAmount += $agentBaseAmount;
                $totalAgentConvenienceFee += $agentConvenienceFee;
                $totalAgentDiscount += $agentDiscount;

                $totalSponsorBaseAmount += $sponsorBaseAmount;
                $totalSponsorConvenienceFee += $sponsorConvenienceFee;
                $totalSponsorDiscount += $sponsorDiscount;

                $totalPosBaseAmount += $posBaseAmount;
                $totalPosConvenienceFee += $posConvenienceFee;
                $totalPosDiscount += $posDiscount;

                $availableTickets = max(0, (int)$ticket->ticket_quantity - ($totalTicketBookings + $totalPosQuantityForTicket));

                $eventReport[] = [
                    'event_name' => $event->event_name . ' (' . $ticket->name . ')',
                    'organizer' => '-',
                    'ticket_quantity' => $ticket->ticket_quantity,
                    'available_tickets' => $availableTickets,
                    'total_bookings' => $totalTicketBookings,
                    'agent_bookings' => $agentBookingsCount,
                    'sponsor_bookings' => $sponsorBookingsCount,
                    'non_agent_bookings' => $nonAgentBookingsCount,
                    'pos_bookings_quantity' => $totalPosQuantityForTicket,
                    'total_ins' => $totalTicketCheckIns + $totalPosCheckInsForTicket,
                    'easebuzz_total_amount' => $onlineEasebuzzTotalAmount,
                    'instamojo_total_amount' => $onlineInstamojoTotalAmount,
                    'razorpay_total_amount' => $onlineRazorpayTotalAmount,
                    'phonepe_total_amount' => $onlinePhonepeTotalAmount,
                    'cashfree_total_amount' => $totalCashfreeTotalAmount,
                    'online_base_amount' => $onlineBaseAmount,
                    'online_convenience_fee' => $onlineConvenienceFee,
                    'online_discount' => $onlineDiscount,
                    'agent_base_amount' => $agentBaseAmount,
                    'agent_convenience_fee' => $agentConvenienceFee,
                    'agent_discount' => $agentDiscount,
                    'sponsor_base_amount' => $sponsorBaseAmount,
                    'sponsor_convenience_fee' => $sponsorConvenienceFee,
                    'sponsor_discount' => $sponsorDiscount,
                    'pos_base_amount' => $posBaseAmount,
                    'pos_convenience_fee' => $posConvenienceFee,
                    'pos_discount' => $posDiscount,
                    'parent' => false
                ];
            }

            $eventReport[$eventSummaryIndex]['total_bookings'] = $totalEventBookings;
            $eventReport[$eventSummaryIndex]['agent_bookings'] = $totalAgentBookings;
            $eventReport[$eventSummaryIndex]['sponsor_bookings'] = $totalSponsorBookings;
            $eventReport[$eventSummaryIndex]['non_agent_bookings'] = $totalNonAgentBookings;
            $eventReport[$eventSummaryIndex]['pos_bookings_quantity'] = $totalPosQuantity;
            $eventReport[$eventSummaryIndex]['total_ins'] = $totalIns;
            $eventReport[$eventSummaryIndex]['easebuzz_total_amount'] = $totalEasebuzzTotalAmount;
            $eventReport[$eventSummaryIndex]['instamojo_total_amount'] = $totalInstamojoTotalAmount;
            $eventReport[$eventSummaryIndex]['razorpay_total_amount'] = $totalRazorpayTotalAmount;
            $eventReport[$eventSummaryIndex]['phonepe_total_amount'] = $totalPhonepeTotalAmount;
            $eventReport[$eventSummaryIndex]['cashfree_total_amount'] = $totalCashfreeTotalAmount;
            $eventReport[$eventSummaryIndex]['online_base_amount'] = $totalOnlineBaseAmount;
            $eventReport[$eventSummaryIndex]['online_convenience_fee'] = $totalOnlineConvenienceFee;
            $eventReport[$eventSummaryIndex]['online_discount'] = $totalOnlineDiscount;
            $eventReport[$eventSummaryIndex]['agent_base_amount'] = $totalAgentBaseAmount;
            $eventReport[$eventSummaryIndex]['agent_convenience_fee'] = $totalAgentConvenienceFee;
            $eventReport[$eventSummaryIndex]['agent_discount'] = $totalAgentDiscount;
            $eventReport[$eventSummaryIndex]['sponsor_base_amount'] = $totalSponsorBaseAmount;
            $eventReport[$eventSummaryIndex]['sponsor_convenience_fee'] = $totalSponsorConvenienceFee;
            $eventReport[$eventSummaryIndex]['sponsor_discount'] = $totalSponsorDiscount;
            $eventReport[$eventSummaryIndex]['pos_base_amount'] = $totalPosBaseAmount;
            $eventReport[$eventSummaryIndex]['pos_convenience_fee'] = $totalPosConvenienceFee;
            $eventReport[$eventSummaryIndex]['pos_discount'] = $totalPosDiscount;
        }

        return Excel::download(new EventReportExport($eventReport), 'Event_Report.xlsx');
    }

    public function exportPosReport(Request $request, DateRangeService $dateRangeService)
    {
        $result = $this->buildPosReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return Excel::download(new PosReportExport($result['data']), 'Pos_Report.xlsx');
    }


    public function exportAgentReport(Request $request, DateRangeService $dateRangeService)
    {
        $result = $this->buildAgentReportData($request, $dateRangeService);

        if (isset($result['error'])) {
            return response()->json(['status' => false, 'message' => $result['error']], $result['status'] ?? 400);
        }

        return Excel::download(new AgentReportExport($result['data']), 'Agent_Report.xlsx');
    }

}
