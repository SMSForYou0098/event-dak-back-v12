<?php

namespace App\Services;

use App\Repositories\DashboardRepository;
use App\Models\Booking;
use App\Models\PosBooking;
use Illuminate\Support\Carbon;
use App\Http\Resources\WeeklySalesResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\CorporateBooking;
use App\Models\PenddingBooking;
use App\Models\ExhibitionBooking;
use App\Models\ScanHistory;
use App\Models\Ticket;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DashboardStatisticsService
{
    protected DashboardRepository $repository;

    public function __construct(DashboardRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Calculate comprehensive sales data for dashboard
     */
    public function calculateSalesData($user, ?array $dateRange = null): array
    {
        $role = $this->getUserRole($user);

        // Get booking statistics based on role
        $stats = $this->repository->getBookingStatsByRole($role, $user->id, $dateRange);

        // Get gateway breakdown for online bookings
        $gatewayBreakdown = [];
        if (in_array($role, ['Admin', 'Organizer'])) {
            $gatewayBreakdown = $this->repository->getGatewayBreakdown('online', $dateRange);
        }

        // Get weekly sales data
        $weeklyData = $this->getWeeklySalesDataByRole($role, $user->id);

        return [
            'statistics' => $stats,
            'gateway_breakdown' => $gatewayBreakdown,
            'weekly_sales' => $weeklyData['sales'],
            'weekly_convenience_fees' => $weeklyData['convenience_fees'],
        ];
    }

    /**
     * Get weekly sales data based on user role
     */
    public function getWeeklySalesDataByRole(string $role, int $userId, int $days = 7): array
    {
        switch ($role) {
            case 'Admin':
                return $this->getAdminWeeklySales($days);

            case 'Organizer':
                return $this->getOrganizerWeeklySales($userId, $days);

            case 'Agent':
                return $this->repository->getWeeklySalesData('agent', $userId, $days);

            case 'Sponsor':
                return $this->repository->getWeeklySalesData('sponsor', $userId, $days);

            case 'POS':
                return $this->repository->getWeeklyPOSSalesData($userId, $days);

            default:
                return ['sales' => [], 'convenience_fees' => []];
        }
    }

    /**
     * Get admin weekly sales (all booking types combined)
     */
    private function getAdminWeeklySales(int $days = 7): array
    {
        $onlineData = $this->repository->getWeeklySalesData('online', null, $days);
        $agentData = $this->repository->getWeeklySalesData('agent', null, $days);
        $sponsorData = $this->repository->getWeeklySalesData('sponsor', null, $days);
        $posData = $this->repository->getWeeklyPOSSalesData(null, $days);

        // Combine all data
        $combinedSales = [];
        $combinedFees = [];

        for ($i = 0; $i < $days; $i++) {
            $combinedSales[] = ($onlineData['sales'][$i] ?? 0) +
                ($agentData['sales'][$i] ?? 0) +
                ($sponsorData['sales'][$i] ?? 0) +
                ($posData['sales'][$i] ?? 0);

            $combinedFees[] = ($onlineData['convenience_fees'][$i] ?? 0) +
                ($agentData['convenience_fees'][$i] ?? 0) +
                ($sponsorData['convenience_fees'][$i] ?? 0) +
                ($posData['convenience_fees'][$i] ?? 0);
        }

        return [
            'sales' => $combinedSales,
            'convenience_fees' => $combinedFees,
            'breakdown' => [
                'online' => $onlineData,
                'agent' => $agentData,
                'sponsor' => $sponsorData,
                'pos' => $posData,
            ],
        ];
    }

    /**
     * Get organizer weekly sales (from their events)
     */
    private function getOrganizerWeeklySales(int $organizerId, int $days = 7): array
    {
        $ticketIds = $this->repository->getOrganizerTicketIds($organizerId);

        $salesData = [];
        $convenienceFeeData = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();

            $bookings = Booking::whereIn('ticket_id', $ticketIds)
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->with('bookingTax')
                ->get();

            $salesData[] = (int) $bookings->sum('total_amount');
            $convenienceFeeData[] = (int) $bookings->sum(fn($b) => $b->bookingTax->convenience_fee ?? 0);
        }

        return [
            'sales' => $salesData,
            'convenience_fees' => $convenienceFeeData,
        ];
    }
    /**
     * Get user counts based on role
     */
    public function getUserCounts($user): array
    {
        $role = $this->getUserRole($user);

        return match ($role) {
            'Admin' => $this->getAdminUserCounts(),
            'Organizer' => $this->getOrganizerUserCounts($user->id),
            'Agent' => $this->getAgentUserCounts($user->id),
            default => ['status' => true, 'counts' => []],
        };
    }

    private function getAdminUserCounts(): array
    {
        $counts = User::join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->selectRaw('roles.name as role_name, count(*) as count')
            ->groupBy('roles.name')
            ->pluck('count', 'role_name');

        return [
            'status' => true,
            'users' => $counts['User'] ?? 0,
            'organizers' => $counts['Organizer'] ?? 0,
            'agents' => $counts['Agent'] ?? 0,
            'sponsors' => $counts['Sponsor'] ?? 0,
            'scanners' => $counts['Scanner'] ?? 0,
            'pos' => $counts['POS'] ?? 0,
        ];
    }

    private function getOrganizerUserCounts(int $userId): array
    {
        $counts = User::where('reporting_user', $userId)
            ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->selectRaw('roles.name as role_name, count(*) as count')
            ->groupBy('roles.name')
            ->pluck('count', 'role_name');

        return [
            'status' => true,
            'agents' => $counts['Agent'] ?? 0,
            'sponsors' => $counts['Sponsor'] ?? 0,
            'scanners' => $counts['Scanner'] ?? 0,
            'users' => $counts['User'] ?? 0,
            'pos' => $counts['POS'] ?? 0,
        ];
    }

    private function getAgentUserCounts(int $userId): array
    {
        $users = User::where('reporting_user', $userId)
            ->whereHas('roles', function ($q) {
                $q->where('name', 'User');
            })->count();

        return [
            'status' => true,
            'users' => $users
        ];
    }

    /**
     * Get booking counts by type
     */
    public function getBookingCounts($user): array
    {
        $userId = $user->id;
        $role = $this->getUserRole($user);

        return match ($role) {
            'Admin' => $this->getAdminCounts(),
            'Organizer' => $this->getOrganizerCounts($userId),
            'Agent' => $this->getSingleTypeCounts('agent', $userId),
            'Sponsor' => $this->getSingleTypeCounts('sponsor', $userId),
            'POS' => $this->getPosCounts($userId),
            default => $this->emptyCounts(),
        };
    }

    private function getAdminCounts(): array
    {
        $result = DB::selectOne("
        SELECT 
            -- Online
            COUNT(*) FILTER (WHERE booking_type = 'online') as online_tickets,
            COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'online') as online_bookings,
            -- Agent
            COUNT(*) FILTER (WHERE booking_type = 'agent') as agent_tickets,
            COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'agent') as agent_bookings,
            -- Sponsor
            COUNT(*) FILTER (WHERE booking_type = 'sponsor') as sponsor_tickets,
            COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'sponsor') as sponsor_bookings,
            -- POS
            (SELECT COALESCE(SUM(quantity), 0) FROM pos_bookings) as pos_tickets,
            (SELECT COUNT(*) FROM pos_bookings) as pos_bookings
        FROM bookings
    ");

        return $this->formatNestedCounts($result);
    }

    private function getOrganizerCounts(int $userId): array
    {
        $ticketIds = $this->repository->getOrganizerTicketIds($userId);

        if (empty($ticketIds)) {
            return $this->emptyCounts();
        }

        if ($ticketIds instanceof Collection) {
            $ticketIds = $ticketIds->toArray();
        }

        $ticketArray = '{' . implode(',', $ticketIds) . '}';

        $result = DB::selectOne("
            SELECT 
                -- Online
                COUNT(*) FILTER (WHERE booking_type = 'online') as online_tickets,
                COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'online') as online_bookings,
                -- Agent
                COUNT(*) FILTER (WHERE booking_type = 'agent') as agent_tickets,
                COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'agent') as agent_bookings,
                -- Sponsor
                COUNT(*) FILTER (WHERE booking_type = 'sponsor') as sponsor_tickets,
                COUNT(DISTINCT session_id) FILTER (WHERE booking_type = 'sponsor') as sponsor_bookings,
                -- POS
                (SELECT COALESCE(SUM(quantity), 0) FROM pos_bookings WHERE ticket_id = ANY(?)) as pos_tickets,
                (SELECT COUNT(*) FROM pos_bookings WHERE ticket_id = ANY(?)) as pos_bookings
            FROM bookings
            WHERE ticket_id = ANY(?)
        ", [$ticketArray, $ticketArray, $ticketArray]);

        return $this->formatNestedCounts($result);
    }

    private function getSingleTypeCounts(string $type, int $userId): array
    {
        $tickets = Booking::where('booking_type', $type)
            ->where('booking_by', $userId)
            ->count();

        $bookings = Booking::where('booking_type', $type)
            ->where('booking_by', $userId)
            ->distinct('session_id')
            ->count('session_id');

        $counts = $this->emptyCounts();
        $counts[$type] = ['tickets' => $tickets, 'bookings' => $bookings];

        // Recalculate total
        $counts['total'] = [
            'tickets' => $tickets,
            'bookings' => $bookings
        ];

        return $counts;
    }

    private function getPosCounts(int $userId): array
    {
        $tickets = PosBooking::where('user_id', $userId)->sum('quantity');
        $bookings = PosBooking::where('user_id', $userId)->count();

        $counts = $this->emptyCounts();
        $counts['pos'] = ['tickets' => (int)$tickets, 'bookings' => $bookings];

        $counts['total'] = [
            'tickets' => (int)$tickets,
            'bookings' => $bookings
        ];

        return $counts;
    }

    private function formatNestedCounts(object $result): array
    {
        $counts = [
            'online' => [
                'tickets' => (int) $result->online_tickets,
                'bookings' => (int) $result->online_bookings,
            ],
            'agent' => [
                'tickets' => (int) $result->agent_tickets,
                'bookings' => (int) $result->agent_bookings,
            ],
            'sponsor' => [
                'tickets' => (int) $result->sponsor_tickets,
                'bookings' => (int) $result->sponsor_bookings,
            ],
            'pos' => [
                'tickets' => (int) $result->pos_tickets,
                'bookings' => (int) $result->pos_bookings,
            ],
        ];

        $counts['total'] = [
            'tickets' => $counts['online']['tickets'] + $counts['agent']['tickets'] + $counts['sponsor']['tickets'] + $counts['pos']['tickets'],
            'bookings' => $counts['online']['bookings'] + $counts['agent']['bookings'] + $counts['sponsor']['bookings'] + $counts['pos']['bookings'],
        ];

        return $counts;
    }

    private function emptyCounts(): array
    {
        return [
            'online' => ['tickets' => 0, 'bookings' => 0],
            'agent' => ['tickets' => 0, 'bookings' => 0],
            'sponsor' => ['tickets' => 0, 'bookings' => 0],
            'pos' => ['tickets' => 0, 'bookings' => 0],
            'total' => ['tickets' => 0, 'bookings' => 0],
        ];
    }

    /**
     * Get payment method breakdown
     */
    public function getPaymentMethodBreakdown(string $bookingType, $userIds, ?array $dateRange = null): array
    {
        $methods = ['cash', 'upi', 'net banking', 'card'];
        $breakdown = [];

        foreach ($methods as $method) {
            $bookings = $this->repository->getBookingsByPaymentMethod($bookingType, $userIds, $method, $dateRange);
            $breakdown[$method] = [
                'count' => $bookings->count(),
                'total_amount' => $bookings->sum('total_amount'),
            ];
        }

        return $breakdown;
    }

    /**
     * Get user's primary role
     */
    private function getUserRole($user): string
    {
        if ($user->hasRole('Admin')) return 'Admin';
        if ($user->hasRole('Organizer')) return 'Organizer';
        if ($user->hasRole('Agent')) return 'Agent';
        if ($user->hasRole('Sponsor')) return 'Sponsor';
        if ($user->hasRole('POS')) return 'POS';
        if ($user->hasRole('Corporate')) return 'Corporate';

        return 'User';
    }

    /**
     * Get sales page data for the dashboard
     */
    public function getSalesPageData($user): array
    {
        $role = $this->getUserRole($user);

        if ($role === 'Admin') {
            return $this->getAdminSalesPageData();
        } elseif ($role === 'Organizer') {
            return $this->getOrganizerSalesPageData($user);
        } else {
            return $this->getOtherRoleSalesPageData($user, $role);
        }
    }

    private function getAdminSalesPageData(): array
    {
        $stats = Booking::selectRaw("
            -- Online
            SUM(CASE WHEN booking_type = 'online' THEN total_amount ELSE 0 END) as online_amount,
            SUM(CASE WHEN booking_type = 'online' THEN discount ELSE 0 END) as online_discount,
            SUM(CASE WHEN booking_type = 'online' THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as online_cnc,
            
            -- Agent
            COUNT(CASE WHEN booking_type = 'agent' THEN 1 END) as agent_count,
            SUM(CASE WHEN booking_type = 'agent' THEN total_amount ELSE 0 END) as agent_amount,
            SUM(CASE WHEN booking_type = 'agent' THEN discount ELSE 0 END) as agent_discount,
            SUM(CASE WHEN booking_type = 'agent' THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as agent_cnc,

            -- Sponsor
            COUNT(CASE WHEN booking_type = 'sponsor' THEN 1 END) as sponsor_count,
            SUM(CASE WHEN booking_type = 'sponsor' THEN total_amount ELSE 0 END) as sponsor_amount,
            SUM(CASE WHEN booking_type = 'sponsor' THEN discount ELSE 0 END) as sponsor_discount,
            SUM(CASE WHEN booking_type = 'sponsor' THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as sponsor_cnc
        ")
            ->leftJoin('booking_taxes as bt', 'bookings.id', '=', 'bt.booking_id')
            ->whereNull('bookings.deleted_at')
            ->whereIn('booking_type', ['online', 'agent', 'sponsor'])
            ->first();

        // Extract values from the single result row
        $onlineAmount = (float) ($stats->online_amount ?? 0);
        $onlineDiscount = (float) ($stats->online_discount ?? 0);
        $onlineCNC = (float) ($stats->online_cnc ?? 0);

        $agentCount = (int) ($stats->agent_count ?? 0);
        $agentAmount = (float) ($stats->agent_amount ?? 0);
        $agentDiscount = (float) ($stats->agent_discount ?? 0);
        $agentCNC = (float) ($stats->agent_cnc ?? 0);

        $sponsorCount = (int) ($stats->sponsor_count ?? 0);
        $sponsorAmount = (float) ($stats->sponsor_amount ?? 0);
        $sponsorDiscount = (float) ($stats->sponsor_discount ?? 0);
        $sponsorCNC = (float) ($stats->sponsor_cnc ?? 0);


        // POS Data (Admin sees all)
        $posStats = $this->repository->getPosBookingStats();

        // Gateway Breakdown
        $pgData = $this->repository->getDetailedGatewayBreakdown();

        // Weekly Data
        $weeklySales = $this->getAdminWeeklySales();

        // 2. Calculate Totals
        $totals = [
            'easebuzzTotalAmount' => $pgData['easebuzz']['all_total'],
            'instamojoTotalAmount' => $pgData['instamojo']['all_total'],
            'phonepeTotalAmount' => $pgData['phonepe']['all_total'],
            'cashfreeTotalAmount' => $pgData['cashfree']['all_total'],
            'razorpayTotalAmount' => $pgData['razorpay']['all_total'],

            'onlineAmount' => $onlineAmount,
            'onlineDiscount' => $onlineDiscount,
            'onlineCNC' => $onlineCNC,

            'posAmount' => round($posStats['total_amount'] ?? 0, 2),
            'posDiscount' => round($posStats['total_discount'] ?? 0, 2),
            'posCNC' => round($posStats['total_convenience_fee'] ?? 0, 2),
            'posBookingCount' => round($posStats['total_bookings'] ?? 0, 2),

            'agentBookingCount' => $agentCount,
            'agentCNC' => round($agentCNC, 2),
            'agentDiscount' => round($agentDiscount, 2),
            'agentAmount' => round($agentAmount, 2),

            'sponsorBookingCount' => $sponsorCount,
            'sponsorAmount' => round($sponsorAmount, 2),
            'sponsorDiscount' => round($sponsorDiscount, 2),
            'sponsorCNC' => round($sponsorCNC, 2),

            'corporateCNC' => 0,
            'offlineCNC' => round(
                $sponsorCNC +
                    $agentCNC +
                    ($posStats['total_convenience_fee'] ?? 0),
                2
            ),
        ];

        return array_merge(
            $totals,
            [
                'salesDataNew' => WeeklySalesResource::collection([['name' => 'Sales', 'data' => $weeklySales['sales']]]),
                'convenienceFee' => WeeklySalesResource::collection([['name' => 'Convenience Fee', 'data' => $weeklySales['convenience_fees']]]),
                'pgData' => $pgData,
                'status' => true
            ]
        );
    }

    private function getOrganizerSalesPageData($user): array
    {
        $userIds = $user->usersUnder()->pluck('id')->push($user->id);
        $ticketIds = $this->repository->getOrganizerTicketIds($user->id);

        if ($ticketIds instanceof Collection) {
            $ticketIds = $ticketIds->toArray();
        }

        $stats = Booking::selectRaw("
            -- Online
            SUM(CASE WHEN booking_type = 'online' AND ticket_id = ANY(?) THEN total_amount ELSE 0 END) as online_amount,
            SUM(CASE WHEN booking_type = 'online' AND ticket_id = ANY(?) THEN discount ELSE 0 END) as online_discount,
            SUM(CASE WHEN booking_type = 'online' AND ticket_id = ANY(?) THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as online_cnc,
            
            -- Agent
            COUNT(CASE WHEN booking_type = 'agent' AND booking_by = ANY(?) THEN 1 END) as agent_count,
            SUM(CASE WHEN booking_type = 'agent' AND booking_by = ANY(?) THEN total_amount ELSE 0 END) as agent_amount,
            SUM(CASE WHEN booking_type = 'agent' AND booking_by = ANY(?) THEN discount ELSE 0 END) as agent_discount,
            SUM(CASE WHEN booking_type = 'agent' AND booking_by = ANY(?) THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as agent_cnc,

            -- Sponsor
            COUNT(CASE WHEN booking_type = 'sponsor' AND booking_by = ANY(?) THEN 1 END) as sponsor_count,
            SUM(CASE WHEN booking_type = 'sponsor' AND booking_by = ANY(?) THEN total_amount ELSE 0 END) as sponsor_amount,
            SUM(CASE WHEN booking_type = 'sponsor' AND booking_by = ANY(?) THEN discount ELSE 0 END) as sponsor_discount,
            SUM(CASE WHEN booking_type = 'sponsor' AND booking_by = ANY(?) THEN CAST(COALESCE(bt.convenience_fee, '0') AS NUMERIC) ELSE 0 END) as sponsor_cnc
        ", [
            '{' . implode(',', $ticketIds) . '}',
            '{' . implode(',', $ticketIds) . '}',
            '{' . implode(',', $ticketIds) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}',
            '{' . implode(',', $userIds->toArray()) . '}'
        ])
            ->leftJoin('booking_taxes as bt', 'bookings.id', '=', 'bt.booking_id')
            ->whereNull('bookings.deleted_at')
            ->where(function ($q) use ($ticketIds, $userIds) {
                $q->where(function ($sub) use ($ticketIds) {
                    $sub->where('booking_type', 'online')
                        ->whereIn('ticket_id', $ticketIds);
                })->orWhere(function ($sub) use ($userIds) {
                    $sub->whereIn('booking_type', ['agent', 'sponsor'])
                        ->whereIn('booking_by', $userIds);
                });
            })
            ->first();

        $onlineAmount = (float) ($stats->online_amount ?? 0);
        $onlineDiscount = (float) ($stats->online_discount ?? 0);
        $onlineCNC = (float) ($stats->online_cnc ?? 0);

        $agentCount = (int) ($stats->agent_count ?? 0);
        $agentAmount = (float) ($stats->agent_amount ?? 0);
        $agentDiscount = (float) ($stats->agent_discount ?? 0);
        $agentCNC = (float) ($stats->agent_cnc ?? 0);

        $sponsorCount = (int) ($stats->sponsor_count ?? 0);
        $sponsorAmount = (float) ($stats->sponsor_amount ?? 0);
        $sponsorDiscount = (float) ($stats->sponsor_discount ?? 0);
        $sponsorCNC = (float) ($stats->sponsor_cnc ?? 0);

        // POS Data
        $posStatsQuery = PosBooking::whereIn('user_id', $userIds);
        $posTotalAmount = (clone $posStatsQuery)->sum('total_amount');
        $posTotalDiscount = (clone $posStatsQuery)->sum('discount');
        $posTotalBookings = (clone $posStatsQuery)->count();

        $posCNC = PosBooking::whereIn('user_id', $userIds)
            ->join('booking_taxes', 'pos_bookings.id', '=', 'booking_taxes.booking_id')
            ->where('booking_taxes.type', 'POS') // Assuming type is POS for pos bookings
            ->sum('booking_taxes.convenience_fee');

        $posStats = [
            'total_amount' => $posTotalAmount,
            'total_convenience_fee' => $posCNC,
            'total_discount' => $posTotalDiscount,
            'total_bookings' => $posTotalBookings,
        ];

        // Gateway Breakdown (Global for now, as per original logic structure)
        $pgData = $this->repository->getDetailedGatewayBreakdown();

        // Weekly Data
        $weeklySales = $this->getOrganizerWeeklySales($user->id);

        // Totals
        $totals = [
            'onlineAmount' => $onlineAmount,
            'onlineDiscount' => $onlineDiscount,
            'onlineCNC' => $onlineCNC,

            'posAmount' => round($posStats['total_amount'] ?? 0, 2),
            'posDiscount' => round($posStats['total_discount'] ?? 0, 2),
            'posCNC' => round($posStats['total_convenience_fee'] ?? 0, 2),
            'posBookingCount' => round($posStats['total_bookings'] ?? 0, 2),

            'agentBookingCount' => $agentCount,
            'agentCNC' => round($agentCNC, 2),
            'agentDiscount' => round($agentDiscount, 2),
            'agentAmount' => round($agentAmount, 2),

            'sponsorBookingCount' => $sponsorCount,
            'sponsorAmount' => round($sponsorAmount, 2),
            'sponsorDiscount' => round($sponsorDiscount, 2),
            'sponsorCNC' => round($sponsorCNC, 2),

            'offlineCNC' => round(
                $sponsorCNC +
                    $agentCNC +
                    ($posStats['total_convenience_fee'] ?? 0),
                2
            ),
        ];

        return array_merge(
            $totals,
            [
                'salesDataNew' => WeeklySalesResource::collection([['name' => 'Sales', 'data' => $weeklySales['sales']]]),
                'convenienceFee' => WeeklySalesResource::collection([['name' => 'Convenience Fee', 'data' => $weeklySales['convenience_fees']]]),
                'pgData' => $pgData,
                'status' => true
            ]
        );
    }

    private function getOtherRoleSalesPageData($user, $role): array
    {
        $query = null;

        if ($role === 'POS') {
            $query = PosBooking::where('user_id', $user->id);
        } else {
            $bookingTypes = [
                'Agent' => 'agent',
                'Sponsor' => 'sponsor',
                'Corporate' => 'corporate'
            ];

            if (isset($bookingTypes[$role])) {
                $query = Booking::where('booking_type', $bookingTypes[$role])
                    ->where('booking_by', $user->id);
            }
        }

        if (!$query) {
            return [
                'cashSales' => 0,
                'upiSales' => 0,
                'netBankingSales' => 0,
                'overallSales' => 0,
                'status' => true
            ];
        }

        // Use conditional aggregation for payment methods
        // Compatible with both MySQL and PostgreSQL
        $stats = $query->selectRaw("
            SUM(total_amount) as overall_sales,
            SUM(CASE WHEN LOWER(payment_method) = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
            SUM(CASE WHEN LOWER(payment_method) = 'upi' THEN total_amount ELSE 0 END) as upi_sales,
            SUM(CASE WHEN LOWER(payment_method) IN ('net_banking', 'net banking') THEN total_amount ELSE 0 END) as net_banking_sales
        ")->first();

        $totals = [
            'cashSales' => (float) ($stats->cash_sales ?? 0),
            'upiSales' => (float) ($stats->upi_sales ?? 0),
            'netBankingSales' => (float) ($stats->net_banking_sales ?? 0),
            'overallSales' => (float) ($stats->overall_sales ?? 0),
        ];

        return array_merge($totals, [
            'status' => true
        ]);
    }

    public function getSummaryData($user, string $type, $startDate, $endDate): array
    {
        $totalAmount = 0;
        $totalDiscount = 0;
        $totalBookings = 0;
        $totalTickets = 0;
        $easebuzzTotalAmount = 0;
        $instamojoTotalAmount = 0;
        $phonepeTotalAmount = 0;
        $cashfreeTotalAmount = 0;
        $razorpayTotalAmount = 0;
        $cashAmount = 0;
        $upiAmount = 0;
        $cardAmount = 0;
        $totalCountScanHistory = 0;
        $todayCountScanHistory = 0;

        $query = null;

        switch ($type) {
            case 'online':
                $query = Booking::where('booking_type', 'online')->whereBetween('created_at', [$startDate, $endDate]);
                $easebuzzTotalAmount = (clone $query)->where('gateway', 'easebuzz')->sum('total_amount');
                $instamojoTotalAmount = (clone $query)->where('gateway', 'instamojo')->sum('total_amount');
                $phonepeTotalAmount = (clone $query)->where('gateway', 'phonepe')->sum('total_amount');
                $cashfreeTotalAmount = (clone $query)->where('gateway', 'cashfree')->sum('total_amount');
                $razorpayTotalAmount = (clone $query)->where('gateway', 'razorpay')->sum('total_amount');
                break;

            case 'amusement-online':
                $query = AmusementBooking::whereBetween('created_at', [$startDate, $endDate]);
                break;

            case 'agent':
                $query = Booking::where('booking_type', 'agent')->whereBetween('created_at', [$startDate, $endDate]);
                if ($user->hasRole('Agent')) {
                    $query->where('booking_by', $user->id);
                } elseif ($user->hasRole('Organizer')) {
                    $eventIds = Event::where('user_id', $user->id)->pluck('id');
                    $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');
                    $query->whereIn('ticket_id', $ticketIds);
                }
                $agentBookings = $query->get();

                $cashAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'cash')->sum('total_amount');
                $upiAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'upi')->sum('total_amount');
                $cardAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'new banking')->sum('total_amount');
                break;

            case 'sponsor':
                $query = Booking::where('booking_type', 'sponsor')->whereBetween('created_at', [$startDate, $endDate]);
                if ($user->hasRole('Sponsor')) {
                    $query->where('booking_by', $user->id);
                } elseif ($user->hasRole('Organizer')) {
                    $eventIds = Event::where('user_id', $user->id)->pluck('id');
                    $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');
                    $query->whereIn('ticket_id', $ticketIds);
                }
                $agentBookings = $query->get();

                $cashAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'cash')->sum('total_amount');
                $upiAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'upi')->sum('total_amount');
                $cardAmount = $agentBookings->filter(fn($b) => strtolower($b->payment_method ?? '') === 'new banking')->sum('total_amount');
                break;

            case 'accreditation':
                $query = AccreditationBooking::whereBetween('created_at', [$startDate, $endDate]);
                if ($user->hasRole('Accreditation')) {
                    $query->where('accreditation_id', $user->id);
                }
                break;

            case 'amusement-agent':
                $query = AmusementAgentBooking::whereBetween('created_at', [$startDate, $endDate]);
                break;

            case 'pos':
                $query = PosBooking::whereBetween('created_at', [$startDate, $endDate]);

                if ($user->hasRole('POS')) {
                    $query->where('user_id', $user->id);
                }

                $posBookings = $query->with('user')->get();

                $isAdminOrOrganizer = $user->hasRole('Admin') || $user->hasRole('Organizer');

                $cashAmount = $posBookings->filter(function ($b) use ($isAdminOrOrganizer) {
                    $method = strtolower($isAdminOrOrganizer ? ($b->payment_method ?? '') : ($b->user->payment_method ?? ''));
                    return $method === 'cash';
                })->sum('total_amount');

                $upiAmount = $posBookings->filter(function ($b) use ($isAdminOrOrganizer) {
                    $method = strtolower($isAdminOrOrganizer ? ($b->payment_method ?? '') : ($b->user->payment_method ?? ''));
                    return $method === 'upi';
                })->sum('total_amount');

                $cardAmount = $posBookings->filter(function ($b) use ($isAdminOrOrganizer) {
                    $method = strtolower($isAdminOrOrganizer ? ($b->payment_method ?? '') : ($b->user->payment_method ?? ''));
                    return $method === 'net banking';
                })->sum('total_amount');
                break;

            case 'corporate':
                $query = CorporateBooking::whereBetween('created_at', [$startDate, $endDate]);
                if ($user->hasRole('Corporate')) {
                    $query->where('user_id', $user->id);
                }
                $posBookings = $query->with('user')->get();
                $cashAmount = $posBookings->filter(fn($b) => strtolower($b->user->payment_method ?? '') === 'cash')->sum('total_amount');
                $upiAmount = $posBookings->filter(fn($b) => strtolower($b->user->payment_method ?? '') === 'upi')->sum('total_amount');
                $cardAmount = $posBookings->filter(fn($b) => strtolower($b->user->payment_method ?? '') === 'net banking')->sum('total_amount');
                break;

            case 'amusement-pos':
                $query = AmusementPosBooking::whereBetween('created_at', [$startDate, $endDate]);
                break;

            case 'pending bookings':
                $query = PenddingBooking::whereBetween('created_at', [$startDate, $endDate]);
                break;

            case 'exhibition':
                $query = ExhibitionBooking::whereBetween('created_at', [$startDate, $endDate]);
                break;

            case 'scan history':
                $totalCountScanHistory = ScanHistory::count();
                $todayCountScanHistory = ScanHistory::whereBetween('created_at', [$startDate, $endDate])->count();
                $query = null;
                break;

            default:
                return ['error' => 'Invalid type provided. Use online, agent, pos, or pending.'];
        }

        if ($type !== 'agent' && $query !== null) {
            if ($user->hasRole('Admin')) {
                $ticketIds = Ticket::pluck('id');
                $query->whereIn('ticket_id', $ticketIds);
            } elseif ($user->hasRole('Organizer')) {
                $eventIds = Event::where('user_id', $user->id)->pluck('id');
                $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');
                $query->whereIn('ticket_id', $ticketIds);
            } elseif ($user->hasRole('Agent')) {
                $query->where('booking_by', $user->id);
            } elseif ($user->hasRole('Sponsor')) {
                $query->where('sponsor_id', $user->id);
            } elseif ($user->hasRole('Accreditation')) {
                $query->where('accreditation_id', $user->id);
            }
        }

        if ($query !== null) {
            $totalAmount = $query->sum('total_amount');
            $totalDiscount = $query->sum('discount');

            if (in_array($type, ['pos', 'corporate', 'amusement-pos', 'exhibition'])) {
                $totalTickets = $query->sum('quantity');
            } else {
                $totalTickets = $query->count('token');
            }

            if ($type == 'accreditation') {
                $totalBookings = $query->whereNotNull('total_amount')->count();
            } else {
                $totalBookings = $query->whereNotNull('total_amount')->distinct('set_id')->count('set_id');
            }
        }

        $summary = [];
        if ($user->hasRole('Organizer')) {
            $counts = User::where('reporting_user', $user->id)
                ->join('model_has_roles', 'users.id', '=', 'model_has_roles.model_id')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->whereIn('roles.name', ['Agent', 'Sponsor', 'User', 'POS'])
                ->selectRaw('roles.name as role_name, count(*) as count')
                ->groupBy('roles.name')
                ->pluck('count', 'role_name');

            $summary['total_agents'] = $counts['Agent'] ?? 0;
            $summary['total_sponsors'] = $counts['Sponsor'] ?? 0;
            $summary['total_users'] = $counts['User'] ?? 0;
            $summary['total_pos'] = $counts['POS'] ?? 0;
        }

        return [
            'summary' => $summary,
            'total_amount' => number_format((float)$totalAmount, 2, '.', ''),
            'total_discount' => number_format((float)$totalDiscount, 2, '.', ''),
            'total_bookings' => $totalBookings,
            'total_tickets' => $totalTickets,
            'gateway_breakdown' => [
                'easebuzz' => number_format((float)$easebuzzTotalAmount, 2, '.', ''),
                'instamojo' => number_format((float)$instamojoTotalAmount, 2, '.', ''),
                'phonepe' => number_format((float)$phonepeTotalAmount, 2, '.', ''),
                'cashfree' => number_format((float)$cashfreeTotalAmount, 2, '.', ''),
                'razorpay' => number_format((float)$razorpayTotalAmount, 2, '.', ''),
            ],
            'cash' => number_format((float)$cashAmount, 2, '.', ''),
            'upi' => number_format((float)$upiAmount, 2, '.', ''),
            'net_banking' => number_format((float)$cardAmount, 2, '.', ''),
            'card' => number_format(0, 2, '.', ''),
            'scan_history' => [
                'total' => $totalCountScanHistory,
                'today' => $todayCountScanHistory,
            ]
        ];
    }
    /**
     * Get event-wise ticket sales
     */
    public function getEventWiseTicketSales($user, $type = 'online', $startDate = null, $endDate = null)
    {
        $isAdmin = $user->hasRole('Admin');
        $isOrganizer = $user->hasRole('Organizer');

        // Build base query
        $query = Event::query();

        // Apply user filter for non-admin
        if (!$isAdmin) {
            if ($isOrganizer) {
                $query->where('user_id', $user->id);
            } else {
                $reportingUserId = $user->reporting_user;
                if (!$reportingUserId) {
                    return ['error' => 'No reporting user assigned'];
                }
                $query->where('user_id', $reportingUserId);
            }
        }

        // Determine relation and constraint
        $bookingRelation = 'bookings';
        $isPos = $type === 'pos';

        if ($isPos) {
            $bookingRelation = 'posBookings';
        }

        $filterBookingsByUser = !$isAdmin && !$isOrganizer;
        $bookingUserId = $user->id;

        $events = $query->with([
            'tickets' => function ($q) use ($startDate, $endDate, $bookingRelation, $type, $filterBookingsByUser, $bookingUserId, $isPos) {

                $q->select('tickets.*');

                $bookingTable = $isPos ? 'pos_bookings' : 'bookings';

                $bookingConstraint = function ($query) use ($startDate, $endDate, $filterBookingsByUser, $bookingUserId, $type, $isPos, $bookingTable) {
                    if ($startDate && $endDate) {
                        $query->whereBetween('created_at', [$startDate, $endDate]);
                    }

                    if ($filterBookingsByUser) {
                        if ($isPos) {
                            $query->where('user_id', $bookingUserId);
                        } else {
                            $query->where('booking_by', $bookingUserId);
                        }
                    }

                    if (!$isPos) {
                        $query->where('booking_type', $type);
                    }

                    // Manual soft delete check since we are querying tables directly
                    $query->whereNull('deleted_at');
                };

                // Count/Quantity Subquery
                if ($isPos) {
                    $alias = Str::snake($bookingRelation) . '_sum_quantity';
                    $q->selectSub(function ($sub) use ($bookingTable, $bookingConstraint) {
                        $sub->from($bookingTable)
                            ->selectRaw('COALESCE(SUM(CAST(quantity AS DECIMAL)), 0)')
                            ->whereRaw('CAST("' . $bookingTable . '"."ticket_id" AS BIGINT) = "tickets"."id"');
                        $bookingConstraint($sub);
                    }, $alias);
                } else {
                    $alias = Str::snake($bookingRelation) . '_count';
                    $q->selectSub(function ($sub) use ($bookingTable, $bookingConstraint) {
                        $sub->from($bookingTable)
                            ->selectRaw('count(*)')
                            ->whereRaw('CAST("' . $bookingTable . '"."ticket_id" AS BIGINT) = "tickets"."id"');
                        $bookingConstraint($sub);
                    }, $alias);
                }

                // Total Amount Subquery
                $amountAlias = Str::snake($bookingRelation) . '_sum_total_amount';
                $sumExpression = $isPos ? 'SUM(CAST(total_amount AS DECIMAL))' : 'SUM(total_amount)';

                $q->selectSub(function ($sub) use ($bookingTable, $bookingConstraint, $sumExpression) {
                    $sub->from($bookingTable)
                        ->selectRaw("COALESCE($sumExpression, 0)")
                        ->whereRaw('CAST("' . $bookingTable . '"."ticket_id" AS BIGINT) = "tickets"."id"');
                    $bookingConstraint($sub);
                }, $amountAlias);
            }
        ])->get();

        $eventSales = [];

        foreach ($events as $event) {
            $ticketsArr = [];

            foreach ($event->tickets as $ticket) {
                if ($isPos) {
                    $countField = Str::snake($bookingRelation) . '_sum_quantity';
                } else {
                    $countField = Str::snake($bookingRelation) . '_count';
                }

                $amountField = Str::snake($bookingRelation) . '_sum_total_amount';

                $bookingsCount = (int) ($ticket->$countField ?? 0);
                $totalAmount = (float) ($ticket->$amountField ?? 0);

                if ($bookingsCount > 0) {
                    $ticketsArr[] = [
                        'name' => $ticket->name,
                        'count' => $bookingsCount,
                        'total_amount' => $totalAmount,
                    ];
                }
            }

            if (!empty($ticketsArr)) {
                $eventSales[] = [
                    'name' => $event->name,
                    'tickets' => $ticketsArr,
                ];
            }
        }

        return $eventSales;
    }
}
