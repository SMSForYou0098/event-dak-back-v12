<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Event;
use App\Models\ExhibitionBooking;
use App\Models\PaymentLog;
use App\Models\PosBooking;
use App\Models\Ticket;
use App\Models\User;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use App\Services\DateRangeService;
use App\Services\DashboardStatisticsService;

use App\Http\Resources\DashboardSummaryResource;
use Exception;


class DashboardController extends Controller
{
    protected DashboardStatisticsService $statsService;

    public function __construct(DashboardStatisticsService $statsService)
    {
        $this->statsService = $statsService;
    }
    public function BookingCounts($id)
    {
        $loggedInUser = Auth::user();

        // Use cache tags for better memory management in Octane
        $cacheKey = "dashboard_booking_counts_{$loggedInUser->id}";

        $data = Cache::tags(['dashboard', "user:{$loggedInUser->id}"])
            ->flexible($cacheKey, [30, 60], function () use ($loggedInUser) {
                return $this->statsService->getBookingCounts($loggedInUser);
            });

        return response()->json($data);
    }

    public function calculateSale(Request $request)
    {
        $loggedInUser = Auth::user();

        // Use cache tags for better memory management in Octane
        $cacheKey = "dashboard_sales_data_{$loggedInUser->id}";

        $data = Cache::tags(['dashboard', "user:{$loggedInUser->id}"])
            ->flexible($cacheKey, [30, 60], function () use ($loggedInUser) {
                return $this->statsService->getSalesPageData($loggedInUser);
            });

        return response()->json($data);
    }

    public function getUserStatistics(Request $request)
    {
        $loggedInUser = Auth::user();
        $cacheKey = "dashboard_user_stats_{$loggedInUser->id}";

        $data = Cache::tags(['dashboard', "user:{$loggedInUser->id}"])
            ->flexible($cacheKey, [30, 60], function () use ($loggedInUser) {
                return $this->statsService->getUserCounts($loggedInUser);
            });

        return response()->json(['data' => $data, 'status' => true]);
    }



    public function getAllData()
    {
        try {
            // Fetch booking from Booking, Agent, and ExhibitionBooking tables
            $booking = Booking::select(
                'bookings.token',
                'bookings.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.number as user_number',
                'attndies.name as attendee_name',
                'attndies.mo as attendee_number',
                'attndies.email as attendee_email',
                'attndies.photo as attendee_photo'
            )
                ->leftJoin('attndies', 'bookings.attendee_id', '=', 'attndies.id')
                ->leftJoin('users', 'bookings.user_id', '=', 'users.id')
                ->get();

            //agent booking (from unified bookings table)
            $agentBooking = Booking::select(
                'bookings.token',
                'bookings.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.number as user_number',
                'attndies.name as attendee_name',
                'attndies.mo as attendee_number',
                'attndies.email as attendee_email',
                'attndies.photo as attendee_photo'
            )
                ->where('bookings.booking_type', 'agent')
                ->leftJoin('attndies', 'bookings.attendee_id', '=', 'attndies.id')
                ->leftJoin('users', 'bookings.user_id', '=', 'users.id')
                ->get();
            //sponsor booking (from unified bookings table)
            $sponsorBooking = Booking::select(
                'bookings.token',
                'bookings.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.number as user_number',
                'attndies.name as attendee_name',
                'attndies.mo as attendee_number',
                'attndies.email as attendee_email',
                'attndies.photo as attendee_photo'
            )
                ->where('bookings.booking_type', 'sponsor')
                ->leftJoin('attndies', 'bookings.attendee_id', '=', 'attndies.id')
                ->leftJoin('users', 'bookings.user_id', '=', 'users.id')
                ->get();

            //exhibitionBooking
            $exhibitionBooking = ExhibitionBooking::select(
                'exhibition_bookings.token',
                'exhibition_bookings.user_id',
                'users.name as user_name',
                'users.email as user_email',
                'users.number as user_number',
                'attndies.name as attendee_name',
                'attndies.mo as attendee_number',
                'attndies.email as attendee_email',
                'attndies.photo as attendee_photo'
            )
                ->leftJoin('attndies', 'exhibition_bookings.attendee_id', '=', 'attndies.id')
                ->leftJoin('users', 'exhibition_bookings.user_id', '=', 'users.id')
                ->get();

            $booking->each(function ($item) {
                $item->type = 'Online Booking';
            });
            $agentBooking->each(function ($item) {
                $item->type = 'Offline Booking';
            });
            $sponsorBooking->each(function ($item) {
                $item->type = 'Sponsor Booking';
            });
            $exhibitionBooking->each(function ($item) {
                $item->type = 'Exhibition Booking';
            });

            // Combine all data
            $allBookings = array_merge($booking->toArray(), $agentBooking->toArray(), $sponsorBooking->toArray(), $exhibitionBooking->toArray());

            if (empty($allBookings)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Booking not found in any table'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Booking details retrieved successfully',
                'data' => $allBookings
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPaymentLog(Request $request, DateRangeService $dateRangeService)
    {
        try {

            // Parse date range using DateRangeService (defaults to today)
            $dateRange = $dateRangeService->parseDateRangeSafe($request);

            if (isset($dateRange['error'])) {
                return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
            }

            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];

            // Build query
            $query = PaymentLog::whereBetween('created_at', [$startDate, $endDate]);


            $PaymentLog = $query->get();

            return response()->json([
                'status' => true,
                'PaymentLog' => $PaymentLog
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function PaymentLogDelet(Request $request, DateRangeService $dateRangeService)
    {
        try {
            if ($request->has('date')) {
                // Parse date range using DateRangeService
                $dateRange = $dateRangeService->parseDateRangeSafe($request);

                if (isset($dateRange['error'])) {
                    return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
                }

                $startDate = $dateRange['startDate'];
                $endDate = $dateRange['endDate'];

                // Soft delete the logs
                $deletedCount = PaymentLog::whereBetween('created_at', [$startDate, $endDate])->delete();

                return response()->json([
                    'status' => true,
                    'message' => "{$deletedCount} payment logs soft deleted successfully."
                ]);
            }

            return response()->json(['status' => false, 'message' => 'Date parameter missing'], 400);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting payment logs.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function organizerWeeklyReport(Request $request)
    {
        $loggedInUser = Auth::user();
        $isAdmin = $loggedInUser->hasRole('Admin');
        $isOrganizer = $loggedInUser->hasRole('Organizer');

        // last 7 days
        $startDate = Carbon::now()->subDays(7)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // જો Admin હોય તો બધા Organizers માટે otherwise current organizer
        if ($isAdmin) {
            $organizerIds = User::role('Organizer')->pluck('id');
        } elseif ($isOrganizer) {
            $organizerIds = collect([$loggedInUser->id]);
        } else {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $report = [];

        foreach ($organizerIds as $orgId) {
            // organizer events
            $eventIds = Event::where('user_id', $orgId)->pluck('id');
            $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');

            // bookings last 7 days
            $bookings = Booking::whereIn('ticket_id', $ticketIds)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            // gateway wise summary
            $gatewayWise = $bookings->groupBy('gateway')->map(function ($gatewayBookings) {
                return [
                    'total_amount' => $gatewayBookings->sum('total_amount'),
                    'events' => $gatewayBookings->groupBy('ticket.event_id')->map(function ($eventBookings, $eventId) {
                        return [
                            'event_name' => Event::find($eventId)->name ?? 'N/A',
                            'ticket_count' => $eventBookings->count(),
                            'total_amount' => $eventBookings->sum('total_amount'),
                        ];
                    })->values()
                ];
            });

            $report[] = [
                'organizer_id' => $orgId,
                'organizer_name' => User::find($orgId)->name,
                'gateways' => $gatewayWise,
            ];
        }

        return response()->json([
            'status' => true,
            'data' => $report
        ]);
    }

    public function dashbordOrgData(Request $request, $type, $id = null)
    {
        $loggedInUser = Auth::user();
        $type = strtolower($type);
        $allowedTypes = ['agent', 'sponsor', 'user', 'pos'];

        if (!in_array($type, $allowedTypes)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid type. Allowed types: agent, sponsor, user, pos'
            ], 400);
        }

        // if (!$loggedInUser->hasRole('Organizer')) {
        //     return response()->json([
        //         'status' => false,
        //         'message' => 'Unauthorized or invalid role.'
        //     ]);
        // }
        $summary = [
            'total_agents' => 0,
            'total_sponsors' => 0,
            'total_users' => 0,
            'total_pos' => 0,
        ];

        if ($type === $type) {

            if ($loggedInUser->hasRole('Organizer')) {
                // Organizer → Get all under him
                $userIds = User::where('reporting_user', $loggedInUser->id)
                    ->whereHas('roles', fn($q) => $q->where('name', ucfirst($type)))
                    ->pluck('id')
                    ->toArray();

                // Fill summary counts only for organizer
                $summary['total_agents'] = User::role('Agent')->where('reporting_user', $loggedInUser->id)->count();
                $summary['total_sponsors'] = User::role('Sponsor')->where('reporting_user', $loggedInUser->id)->count();
                $summary['total_users'] = User::role('User')->where('reporting_user', $loggedInUser->id)->count();
                $summary['total_pos'] = User::role('POS')->where('reporting_user', $loggedInUser->id)->count();
            } else {
                // Agent/Sponsor/User/POS → only self
                $userIds = [$loggedInUser->id];
            }

            // ✅ Fetch all bookings for agents
            if ($type === 'pos') {
                $bookings = PosBooking::whereIn('user_id', $userIds)
                    ->with(['bookingsTax:id,booking_id,convenience_fee'])
                    ->get();
            } else {
                $bookings = Booking::where('booking_type', $type)
                    ->whereIn('booking_by', $userIds)
                    ->with(['ticket', 'bookingsTax:id,booking_id,convenience_fee'])
                    ->get();
            }


            // === TODAY / TOTAL ===
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();

            $todayBookings = $bookings->whereBetween('created_at', [$todayStart, $todayEnd]);
            $totalBookings = $bookings->pluck('set_id')->unique()->count();

            if ($type === 'pos') {
                // POS bookings: use quantity sum
                $todayTickets = $bookings->whereBetween('created_at', [$todayStart, $todayEnd])->sum('quantity');
                $totalTickets = $bookings->sum('quantity');
            } else {
                // Normal bookings: use count
                $todayTickets = $bookings->whereBetween('created_at', [$todayStart, $todayEnd])->count();
                $totalTickets = $bookings->count();
            }


            // === Sales ===
            $todaySales = $todayBookings->sum('total_amount');
            $totalSales = $bookings->sum('total_amount');

            // === Convenience Fee ===
            $todayCF = round($todayBookings->sum(fn($b) => optional($b->bookingTax)->convenience_fee ?? 0), 2);
            $totalCF = round($bookings->sum(fn($b) => optional($b->bookingTax)->convenience_fee ?? 0), 2);

            // === Discounts ===
            $todayDiscount = round($todayBookings->sum(fn($b) => $b->discount ?? 0), 2);
            $totalDiscount = round($bookings->sum(fn($b) => $b->discount ?? 0), 2);

            // Payment-type breakdown (today and total)
            $isCash = fn($b) => in_array(strtolower($b->payment_method), ['cash']);
            $isUpi = fn($b) => in_array(strtolower($b->payment_method), ['upi']);
            $isNb = fn($b) => in_array(strtolower($b->payment_method), ['nb', 'netbanking', 'net banking', 'Net Banking']);


            // === Last 7 Days Sales & Convenience Fee ===
            $dailySales = [];
            $dailyCF = [];

            foreach (range(6, 0) as $i) {
                $day = now()->subDays($i)->startOfDay();
                $dayEnd = (clone $day)->endOfDay();

                $dayBookings = $bookings->whereBetween('created_at', [$day, $dayEnd]);
                $dailySales[] = round($dayBookings->sum('total_amount'), 2);
                $dailyCF[] = round($dayBookings->sum(fn($b) => optional($b->bookingTax)->convenience_fee ?? 0), 2);
            }

            $weeklyData = [
                'name' => 'Offline Sales',
                'data' => $dailySales
            ];

            $weeklyCFData = [
                'name' => 'Offline Convenience Fee',
                'data' => $dailyCF
            ];

            // === Response ===
            return response()->json([
                'status' => true,
                'user_role' => $loggedInUser->getRoleNames()->first(),
                'user_id' => $loggedInUser->id,
                'organisation' => $loggedInUser->organisation ?? null,
                'type' => ucfirst($type),
                'summary' => $summary,
                'data' => [
                    'sales' => [
                        'today' => $todaySales,
                        'total' => $totalSales,
                    ],
                    'cash' => [
                        'today' => $todayBookings->filter($isCash)->sum('total_amount'),
                        'total' => $bookings->filter($isCash)->sum('total_amount'),
                    ],
                    'discount' => [
                        'today' => $todayDiscount,
                        'total' => $totalDiscount,
                    ],
                    'upi' => [
                        'today' => $todayBookings->filter($isUpi)->sum('total_amount'),
                        'total' => $bookings->filter($isUpi)->sum('total_amount'),
                    ],
                    'nb' => [
                        'today' => $todayBookings->filter($isNb)->sum('total_amount'),
                        'total' => $bookings->filter($isNb)->sum('total_amount'),
                    ],
                    'bookings' => [
                        'today' => $todayBookings->pluck('set_id')->unique()->count(),
                        'total' => $totalBookings,
                    ],
                    // 'tickets' => [
                    //     'today' => $todayTickets->count(),
                    //     'total' => $totalTickets,
                    // ],
                    'tickets' => [
                        'today' => $todayTickets,
                        'total' => $totalTickets,
                    ],

                    'convenience_fee' => [
                        'today' => $todayCF,
                        'total' => $totalCF,
                        'last_7_days' => $weeklyCFData,
                    ],
                    'salesDataNew' => $weeklyData,
                ],
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => 'Invalid request type.'
        ]);
    }

    public function getGatewayWiseSalesData(Request $request)
    {
        try {
            $todayStart = Carbon::today()->startOfDay();
            $todayEnd = Carbon::today()->endOfDay();
            $yesterdayStart = Carbon::yesterday()->startOfDay();
            $yesterdayEnd = Carbon::yesterday()->endOfDay();

            $gateways = ['phonepe', 'easebuzz', 'razorpay', 'cashfree', 'instamojo'];

            /*
            |--------------------------------------------------------------------------
            | SINGLE QUERY FOR ALL BOOKING STATS USING POSTGRESQL FILTER CLAUSE
            |--------------------------------------------------------------------------
            */
            $bookingStats = Booking::whereNull('deleted_at')
                ->selectRaw("
                booking_type,
                gateway,
                
                -- Today
                COUNT(DISTINCT session_id) FILTER (WHERE created_at BETWEEN ? AND ?) as today_count,
                COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as today_amount,
                
                -- Yesterday
                COUNT(DISTINCT session_id) FILTER (WHERE created_at BETWEEN ? AND ?) as yesterday_count,
                COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as yesterday_amount,
                
                -- Total
                COUNT(DISTINCT session_id) as total_count,
                COALESCE(SUM(total_amount), 0) as total_amount
            ", [
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->groupBy('booking_type', 'gateway')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | SINGLE QUERY FOR POS STATS WITH SET BOOKING LOGIC
            | - If set_id exists: count unique set_id as 1 booking
            | - If set_id is null: count each row as 1 booking
            |--------------------------------------------------------------------------
            */
            $posStats = PosBooking::whereNull('deleted_at')
                ->selectRaw("
                    -- Today Count: unique set_id + individual bookings without set_id
                    (
                        COUNT(DISTINCT set_id) FILTER (WHERE created_at BETWEEN ? AND ? AND set_id IS NOT NULL) +
                        COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ? AND set_id IS NULL)
                    ) as today_count,
                    COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as today_amount,
                    
                    -- Yesterday Count
                    (
                        COUNT(DISTINCT set_id) FILTER (WHERE created_at BETWEEN ? AND ? AND set_id IS NOT NULL) +
                        COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ? AND set_id IS NULL)
                    ) as yesterday_count,
                    COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as yesterday_amount,
                    
                    -- Total Count
                    (
                        COUNT(DISTINCT set_id) FILTER (WHERE set_id IS NOT NULL) +
                        COUNT(*) FILTER (WHERE set_id IS NULL)
                    ) as total_count,
                    COALESCE(SUM(total_amount), 0) as total_amount
                ", [
                    // Today count
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    // Today amount
                    $todayStart,
                    $todayEnd,
                    // Yesterday count
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    // Yesterday amount
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->first();

            /*
            |--------------------------------------------------------------------------
            | HELPER: FORMAT STATS ARRAY
            |--------------------------------------------------------------------------
            */
            $formatStats = fn($data) => [
                'today' => [
                    'count' => (int) ($data->today_count ?? 0),
                    'amount' => (float) round($data->today_amount ?? 0, 2)
                ],
                'yesterday' => [
                    'count' => (int) ($data->yesterday_count ?? 0),
                    'amount' => (float) round($data->yesterday_amount ?? 0, 2)
                ],
                'total' => [
                    'count' => (int) ($data->total_count ?? 0),
                    'amount' => (float) round($data->total_amount ?? 0, 2)
                ]
            ];

            /*
            |--------------------------------------------------------------------------
            | GATEWAY-WISE DATA
            |--------------------------------------------------------------------------
            */
            $gatewayWise = collect($gateways)->map(function ($gateway) use ($bookingStats, $formatStats) {
                $data = $bookingStats
                    ->where('booking_type', 'online')
                    ->where('gateway', $gateway)
                    ->first();

                $stats = $data ? $formatStats($data) : [
                    'today' => ['count' => 0, 'amount' => 0.0],
                    'yesterday' => ['count' => 0, 'amount' => 0.0],
                    'total' => ['count' => 0, 'amount' => 0.0]
                ];

                return ['label' => ucfirst($gateway), ...$stats];
            })->values();

            /*
            |--------------------------------------------------------------------------
            | CHANNEL TOTALS
            |--------------------------------------------------------------------------
            */

            // Aggregate by booking_type
            $aggregateByType = function ($type) use ($bookingStats) {
                $filtered = $bookingStats->where('booking_type', $type);

                if ($filtered->isEmpty()) {
                    return [
                        'today' => ['count' => 0, 'amount' => 0.0],
                        'yesterday' => ['count' => 0, 'amount' => 0.0],
                        'total' => ['count' => 0, 'amount' => 0.0]
                    ];
                }

                return [
                    'today' => [
                        'count' => (int) $filtered->sum('today_count'),
                        'amount' => (float) round($filtered->sum('today_amount'), 2)
                    ],
                    'yesterday' => [
                        'count' => (int) $filtered->sum('yesterday_count'),
                        'amount' => (float) round($filtered->sum('yesterday_amount'), 2)
                    ],
                    'total' => [
                        'count' => (int) $filtered->sum('total_count'),
                        'amount' => (float) round($filtered->sum('total_amount'), 2)
                    ]
                ];
            };

            $posTotals = $formatStats($posStats);
            $agentTotals = $aggregateByType('agent');
            $sponsorTotals = $aggregateByType('sponsor');
            $onlineTotals = $aggregateByType('online');

            // Offline = POS + Agent
            $offlineTotals = [
                'today' => [
                    'count' => $posTotals['today']['count'] + $agentTotals['today']['count'],
                    'amount' => round($posTotals['today']['amount'] + $agentTotals['today']['amount'], 2)
                ],
                'yesterday' => [
                    'count' => $posTotals['yesterday']['count'] + $agentTotals['yesterday']['count'],
                    'amount' => round($posTotals['yesterday']['amount'] + $agentTotals['yesterday']['amount'], 2)
                ],
                'total' => [
                    'count' => $posTotals['total']['count'] + $agentTotals['total']['count'],
                    'amount' => round($posTotals['total']['amount'] + $agentTotals['total']['amount'], 2)
                ]
            ];

            $channelTotals = [
                ['label' => 'POS', ...$posTotals],
                ['label' => 'Agent', ...$agentTotals],
                ['label' => 'Sponsor', ...$sponsorTotals],
                ['label' => 'Online', ...$onlineTotals],
                ['label' => 'Offline', ...$offlineTotals],
            ];

            return response()->json([
                'status' => true,
                'gatewayWise' => $gatewayWise,
                'channelTotals' => $channelTotals
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function organizerTotals()
    {
        try {
            $todayStart = Carbon::today()->startOfDay();
            $todayEnd = Carbon::today()->endOfDay();
            $yesterdayStart = Carbon::yesterday()->startOfDay();
            $yesterdayEnd = Carbon::yesterday()->endOfDay();


            $organizerRoleId = DB::table('roles')->where('name', 'Organizer')->value('id');

            if (!$organizerRoleId) {
                return response()->json([
                    'status' => true,
                    'data' => []
                ]);
            }

            $organizers = User::select('users.id', 'users.name', 'users.organisation')
                ->join('model_has_roles', function ($join) use ($organizerRoleId) {
                    $join->on('users.id', '=', 'model_has_roles.model_id')
                        ->where('model_has_roles.model_type', User::class)
                        ->where('model_has_roles.role_id', $organizerRoleId);
                })
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('events')
                        ->join('event_controls', 'events.id', '=', 'event_controls.event_id')
                        ->whereColumn('events.user_id', 'users.id')
                        ->where('event_controls.status', '1');
                })
                ->get();

            if ($organizers->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'data' => []
                ]);
            }

            $organizerIds = $organizers->pluck('id')->toArray();

            $bookingStats = Booking::whereNull('bookings.deleted_at')
                ->join('tickets', function ($join) {
                    $join->on(DB::raw('CAST(bookings.ticket_id AS BIGINT)'), '=', 'tickets.id');
                })
                ->join('events', 'tickets.event_id', '=', 'events.id')
                ->join('event_controls', 'events.id', '=', 'event_controls.event_id')
                ->where('event_controls.status', '1')
                ->whereIn('events.user_id', $organizerIds)
                ->selectRaw("
                events.user_id as organizer_id,
                bookings.booking_type,
                bookings.gateway,

                -- Today
                COALESCE(SUM(bookings.total_amount) FILTER (WHERE bookings.created_at BETWEEN ? AND ?), 0) as today_amount,
                COUNT(*) FILTER (WHERE bookings.created_at BETWEEN ? AND ?) as today_count,

                -- Yesterday
                COALESCE(SUM(bookings.total_amount) FILTER (WHERE bookings.created_at BETWEEN ? AND ?), 0) as yesterday_amount,
                COUNT(*) FILTER (WHERE bookings.created_at BETWEEN ? AND ?) as yesterday_count,

                -- Total
                COALESCE(SUM(bookings.total_amount), 0) as total_amount,
                COUNT(*) as total_count
            ", [
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->groupBy('events.user_id', 'bookings.booking_type', 'bookings.gateway')
                ->get();

            $posStats = PosBooking::whereNull('pos_bookings.deleted_at')
                ->join('tickets', function ($join) {
                    $join->on(DB::raw('CAST(pos_bookings.ticket_id AS BIGINT)'), '=', 'tickets.id');
                })
                ->join('events', 'tickets.event_id', '=', 'events.id')
                ->join('event_controls', 'events.id', '=', 'event_controls.event_id')
                ->where('event_controls.status', '1')
                ->whereIn('events.user_id', $organizerIds)
                ->selectRaw("
                events.user_id as organizer_id,

                -- Today Amount
                COALESCE(SUM(pos_bookings.total_amount) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ?), 0) as today_amount,
                
                -- Today Count (set_id logic)
                (
                    COUNT(DISTINCT pos_bookings.set_id) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ? AND pos_bookings.set_id IS NOT NULL) +
                    COUNT(*) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ? AND pos_bookings.set_id IS NULL)
                ) as today_count,

                -- Yesterday Amount
                COALESCE(SUM(pos_bookings.total_amount) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ?), 0) as yesterday_amount,
                
                -- Yesterday Count (set_id logic)
                (
                    COUNT(DISTINCT pos_bookings.set_id) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ? AND pos_bookings.set_id IS NOT NULL) +
                    COUNT(*) FILTER (WHERE pos_bookings.created_at BETWEEN ? AND ? AND pos_bookings.set_id IS NULL)
                ) as yesterday_count,

                -- Total Amount
                COALESCE(SUM(pos_bookings.total_amount), 0) as total_amount,
                
                -- Total Count (set_id logic)
                (
                    COUNT(DISTINCT pos_bookings.set_id) FILTER (WHERE pos_bookings.set_id IS NOT NULL) +
                    COUNT(*) FILTER (WHERE pos_bookings.set_id IS NULL)
                ) as total_count
            ", [
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->groupBy('events.user_id')
                ->get()
                ->keyBy('organizer_id');


            $getBookingStatsByType = function ($organizerId, $bookingType) use ($bookingStats) {
                $filtered = $bookingStats
                    ->where('organizer_id', $organizerId)
                    ->where('booking_type', $bookingType);

                return [
                    'today' => (float) $filtered->sum('today_amount'),
                    'yesterday' => (float) $filtered->sum('yesterday_amount'),
                    'total' => (float) $filtered->sum('total_amount')
                ];
            };

            $getGatewayStats = function ($organizerId, $period = null) use ($bookingStats) {
                $filtered = $bookingStats->where('organizer_id', $organizerId);

                $amountField = match ($period) {
                    'today' => 'today_amount',
                    'yesterday' => 'yesterday_amount',
                    default => 'total_amount'
                };

                $countField = match ($period) {
                    'today' => 'today_count',
                    'yesterday' => 'yesterday_count',
                    default => 'total_count'
                };

                return $filtered
                    ->groupBy('gateway')
                    ->map(fn($items, $gateway) => [
                        'name' => $gateway ?? 'unknown',
                        'total_amount' => (float) $items->sum($amountField),
                        'total_bookings' => (int) $items->sum($countField)
                    ])
                    ->values()
                    ->toArray();
            };

            /*
        |--------------------------------------------------------------------------
        | BUILD RESPONSE FOR EACH ORGANIZER
        |--------------------------------------------------------------------------
        */
            $response = $organizers->map(function ($org) use (
                $getBookingStatsByType,
                $getGatewayStats,
                $posStats
            ) {
                $organizerId = $org->id;

                // Get booking stats by type
                $onlineStats = $getBookingStatsByType($organizerId, 'online');
                $agentStats = $getBookingStatsByType($organizerId, 'agent');
                $sponsorStats = $getBookingStatsByType($organizerId, 'sponsor');
                $corporateStats = $getBookingStatsByType($organizerId, 'corporate');

                // Get POS stats
                $pos = $posStats->get($organizerId);
                $posData = [
                    'today' => (float) ($pos->today_amount ?? 0),
                    'yesterday' => (float) ($pos->yesterday_amount ?? 0),
                    'total' => (float) ($pos->total_amount ?? 0)
                ];

                // Calculate offline totals
                $todayOffline = $agentStats['today'] + $sponsorStats['today'] + $corporateStats['today'] + $posData['today'];
                $yesterdayOffline = $agentStats['yesterday'] + $sponsorStats['yesterday'] + $corporateStats['yesterday'] + $posData['yesterday'];
                $totalOffline = $agentStats['total'] + $sponsorStats['total'] + $corporateStats['total'] + $posData['total'];

                return [
                    'organizer_id' => $organizerId,
                    'organizer_name' => $org->name,
                    'organisation' => $org->organisation,

                    'today' => [
                        'online' => $onlineStats['today'],
                        'offline' => round($todayOffline, 2),
                        'gateway_wise' => $getGatewayStats($organizerId, 'today'),
                    ],

                    'yesterday' => [
                        'online' => $onlineStats['yesterday'],
                        'offline' => round($yesterdayOffline, 2),
                        'gateway_wise' => $getGatewayStats($organizerId, 'yesterday'),
                    ],

                    'online_overall_total' => $onlineStats['total'],
                    'offline_overall_total' => round($totalOffline, 2),
                    'overall_total' => round($onlineStats['total'] + $totalOffline, 2),
                    'gateway_wise_overall' => $getGatewayStats($organizerId),
                ];
            })->values()->toArray();

            return response()->json([
                'status' => true,
                'data' => $response
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getDashboardOrgTicket()
    {
        try {
            $loggedInUser = Auth::user();
            $isAdmin = $loggedInUser->hasRole('Admin');
            $isOrganizer = $loggedInUser->hasRole('Organizer');

            if (!$isAdmin && !$isOrganizer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized access',
                ], 403);
            }

            $todayStart = Carbon::today()->startOfDay();
            $todayEnd = Carbon::today()->endOfDay();
            $yesterdayStart = Carbon::yesterday()->startOfDay();
            $yesterdayEnd = Carbon::yesterday()->endOfDay();

            $eventsQuery = Event::select('events.id', 'events.name')
                ->join('event_controls', 'events.id', '=', 'event_controls.event_id')
                ->where('event_controls.status', '1');

            if ($isOrganizer) {
                $eventsQuery->where('events.user_id', $loggedInUser->id);
            }

            $events = $eventsQuery->with('tickets:id,event_id,name')->get();

            if ($events->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'data' => []
                ]);
            }

            $ticketIds = $events->pluck('tickets')->flatten()->pluck('id')->toArray();

            $bookingStats = Booking::whereNull('deleted_at')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw("
                ticket_id,
                booking_type,

                -- Today
                COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ?) as today_count,
                COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as today_amount,

                -- Yesterday
                COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ?) as yesterday_count,
                COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as yesterday_amount,

                -- Total
                COUNT(*) as total_count,
                COALESCE(SUM(total_amount), 0) as total_amount
            ", [
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->groupBy('ticket_id', 'booking_type')
                ->get();

            $posStats = PosBooking::whereNull('deleted_at')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw("
                ticket_id,

        -- Today
        COALESCE(SUM(quantity) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as today_count,
        COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as today_amount,

        -- Yesterday
        COALESCE(SUM(quantity) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as yesterday_count,
        COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) as yesterday_amount,

        -- Total
        COALESCE(SUM(quantity), 0) as total_count,
        COALESCE(SUM(total_amount), 0) as total_amount
    ", [
                    $todayStart,
                    $todayEnd,
                    $todayStart,
                    $todayEnd,
                    $yesterdayStart,
                    $yesterdayEnd,
                    $yesterdayStart,
                    $yesterdayEnd
                ])
                ->groupBy('ticket_id')
                ->get()
                ->keyBy('ticket_id');

            $getBookingStats = function ($ticketId, $bookingType) use ($bookingStats) {
                $stat = $bookingStats
                    ->where('ticket_id', $ticketId)
                    ->where('booking_type', $bookingType)
                    ->first();

                return [
                    'today_count' => (int) ($stat->today_count ?? 0),
                    'today_amount' => (float) ($stat->today_amount ?? 0),
                    'yesterday_count' => (int) ($stat->yesterday_count ?? 0),
                    'yesterday_amount' => (float) ($stat->yesterday_amount ?? 0),
                    'total_count' => (int) ($stat->total_count ?? 0),
                    'total_amount' => (float) ($stat->total_amount ?? 0),
                ];
            };

            $getPosStats = function ($ticketId) use ($posStats) {
                $stat = $posStats->get($ticketId);

                return [
                    'today_count' => (int) ($stat->today_count ?? 0),
                    'today_amount' => (float) ($stat->today_amount ?? 0),
                    'yesterday_count' => (int) ($stat->yesterday_count ?? 0),
                    'yesterday_amount' => (float) ($stat->yesterday_amount ?? 0),
                    'total_count' => (int) ($stat->total_count ?? 0),
                    'total_amount' => (float) ($stat->total_amount ?? 0),
                ];
            };

            $sumStats = function (...$statsArray) {
                $result = [
                    'today_count' => 0,
                    'today_amount' => 0,
                    'yesterday_count' => 0,
                    'yesterday_amount' => 0,
                    'total_count' => 0,
                    'total_amount' => 0,
                ];

                foreach ($statsArray as $stats) {
                    $result['today_count'] += $stats['today_count'];
                    $result['today_amount'] += $stats['today_amount'];
                    $result['yesterday_count'] += $stats['yesterday_count'];
                    $result['yesterday_amount'] += $stats['yesterday_amount'];
                    $result['total_count'] += $stats['total_count'];
                    $result['total_amount'] += $stats['total_amount'];
                }

                return $result;
            };

            $formatOutput = function ($stats) {
                return [
                    'today_count' => $stats['today_count'],
                    'yesterday_count' => $stats['yesterday_count'],
                    'overall_count' => $stats['total_count'],
                    'today_amount' => round($stats['today_amount'], 2),
                    'yesterday_amount' => round($stats['yesterday_amount'], 2),
                    'overall_amount' => round($stats['total_amount'], 2),
                ];
            };

            $response = $events->map(function ($event) use (
                $getBookingStats,
                $getPosStats,
                $sumStats,
                $formatOutput
            ) {
                // Event-level totals
                $eventOnline = [
                    'today_count' => 0,
                    'today_amount' => 0,
                    'yesterday_count' => 0,
                    'yesterday_amount' => 0,
                    'total_count' => 0,
                    'total_amount' => 0,
                ];
                $eventOffline = [
                    'today_count' => 0,
                    'today_amount' => 0,
                    'yesterday_count' => 0,
                    'yesterday_amount' => 0,
                    'total_count' => 0,
                    'total_amount' => 0,
                ];

                $ticketData = $event->tickets->map(function ($ticket) use (
                    $getBookingStats,
                    $getPosStats,
                    $sumStats,
                    $formatOutput,
                    &$eventOnline,
                    &$eventOffline
                ) {
                    $ticketId = $ticket->id;

                    // Online = booking_type 'online'
                    $onlineStats = $getBookingStats($ticketId, 'online');

                    // Offline = agent + sponsor + corporate + POS
                    $agentStats = $getBookingStats($ticketId, 'agent');
                    $sponsorStats = $getBookingStats($ticketId, 'sponsor');
                    $corporateStats = $getBookingStats($ticketId, 'corporate');
                    $posStatsData = $getPosStats($ticketId);

                    $offlineStats = $sumStats($agentStats, $sponsorStats, $corporateStats, $posStatsData);
                    $totalStats = $sumStats($onlineStats, $offlineStats);

                    // Accumulate event totals
                    $eventOnline = $sumStats($eventOnline, $onlineStats);
                    $eventOffline = $sumStats($eventOffline, $offlineStats);

                    return [
                        'name' => $ticket->name,
                        ...$formatOutput($totalStats),
                        'online' => $formatOutput($onlineStats),
                        'offline' => $formatOutput($offlineStats),
                    ];
                })->toArray();

                $eventTotal = $sumStats($eventOnline, $eventOffline);

                return [
                    'name' => $event->name,
                    ...$formatOutput($eventTotal),
                    'online' => $formatOutput($eventOnline),
                    'offline' => $formatOutput($eventOffline),
                    'tickets' => $ticketData,
                ];
            })->toArray();

            return response()->json([
                'status' => true,
                'data' => $response
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
