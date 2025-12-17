<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Models\CashfreeConfig;
use App\Models\EasebuzzConfig;
use App\Models\Event;
use App\Models\Instamojo;
use App\Models\PhonePe;
use App\Models\PosBooking;
use App\Models\Razorpay;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class DashboardRepository
{
    /**
     * Get booking statistics by role
     */
    public function getBookingStatsByRole(string $role, int $userId, ?array $dateRange = null): array
    {
        $query = Booking::query();

        // Apply date range if provided
        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        // Apply role-based filtering
        switch ($role) {
            case 'Admin':
                // Admin sees all bookings
                break;

            case 'Organizer':
                $eventIds = Event::where('user_id', $userId)->pluck('id');
                $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');
                $query->whereIn('ticket_id', $ticketIds);
                break;

            case 'Agent':
                $query->where('booking_type', 'agent')
                    ->where('booking_by', $userId);
                break;

            case 'Sponsor':
                $query->where('booking_type', 'sponsor')
                    ->where('booking_by', $userId);
                break;

            case 'POS':
                // POS bookings are in separate table
                return $this->getPosBookingStats($userId, $dateRange);
        }

        return [
            'total_bookings' => $query->count(),
            'total_amount' => $query->sum('total_amount'),
            'total_discount' => $query->sum('discount'),
            'bookings' => $query->latest()->get(),
        ];
    }

    /**
     * Get POS booking statistics
     */
    public function getPosBookingStats(?int $userId = null, ?array $dateRange = null): array
    {
        $query = PosBooking::with('bookingTax');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        $bookings = $query->latest()->get();

        return [
            'total_bookings' => $bookings->count(),
            'total_amount' => $bookings->sum('total_amount'),
            'total_discount' => $bookings->sum('discount'),
            'total_convenience_fee' => $bookings->sum(fn($b) => $b->bookingTax->convenience_fee ?? 0),
            'bookings' => $bookings,
        ];
    }

    /**
     * Get gateway-wise breakdown
     */
    public function getGatewayBreakdown(string $bookingType = 'online', ?array $dateRange = null): array
    {
        $query = Booking::where('booking_type', $bookingType);

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        $gateways = ['easebuzz', 'instamojo', 'phonepe', 'cashfree', 'razorpay'];
        $breakdown = [];

        foreach ($gateways as $gateway) {
            $breakdown[$gateway] = (clone $query)
                ->where('gateway', $gateway)
                ->sum('total_amount');
        }

        return $breakdown;
    }

    /**
     * Get detailed gateway breakdown with active status and totals
     */
    public function getDetailedGatewayBreakdown(): array
    {
        $gateways = [
            'easebuzz' => EasebuzzConfig::class,
            'instamojo' => Instamojo::class,
            'phonepe' => PhonePe::class,
            'cashfree' => CashfreeConfig::class,
            'razorpay' => Razorpay::class,
        ];

        // 1. Get active status for all gateways
        $activeStatus = [];
        foreach ($gateways as $key => $model) {
            $activeStatus[$key] = $model::where('status', '1')->exists();
        }

        // 2. Single query for all totals using conditional aggregation
        $stats = Booking::where('booking_type', 'online')
            ->whereIn('gateway', array_keys($gateways))
            ->selectRaw("
                gateway,
                COALESCE(SUM(total_amount) FILTER (WHERE created_at >= ?), 0) as today_total,
                COALESCE(SUM(total_amount), 0) as all_total
            ", [Carbon::today()])
            ->groupBy('gateway')
            ->get()
            ->keyBy('gateway');

        // 3. Merge results
        $data = [];
        foreach ($gateways as $key => $model) {
            $gatewayStats = $stats->get($key);
            $data[$key] = [
                'active' => $activeStatus[$key],
                'today_total' => (float) ($gatewayStats->today_total ?? 0),
                'all_total' => (float) ($gatewayStats->all_total ?? 0),
            ];
        }

        return $data;
    }

    /**
     * Get weekly sales data for a specific booking type
     */
    public function getWeeklySalesData(string $bookingType, $userIds = null, int $days = 7): array
    {
        $salesData = [];
        $convenienceFeeData = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();

            $query = Booking::where('booking_type', $bookingType)
                ->whereBetween('created_at', [$startOfDay, $endOfDay])
                ->whereNull('deleted_at');

            if ($userIds) {
                $userIds = is_array($userIds) ? $userIds : [$userIds];
                $query->whereIn('user_id', $userIds);
            }

            $salesData[] = (int) $query->sum('total_amount');

            // Get convenience fee from booking tax relationship
            $bookings = (clone $query)->with('bookingTax')->get();
            $convenienceFeeData[] = (int) $bookings->sum(fn($b) => $b->bookingTax->convenience_fee ?? 0);
        }

        return [
            'sales' => $salesData,
            'convenience_fees' => $convenienceFeeData,
        ];
    }

    /**
     * Get weekly sales data for POS bookings
     */
    public function getWeeklyPOSSalesData($userIds = null, int $days = 7): array
    {
        $salesData = [];
        $convenienceFeeData = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $startOfDay = Carbon::parse($date)->startOfDay();
            $endOfDay = Carbon::parse($date)->endOfDay();

            $query = PosBooking::whereBetween('created_at', [$startOfDay, $endOfDay])
                ->whereNull('deleted_at');

            if ($userIds) {
                $userIds = is_array($userIds) ? $userIds : [$userIds];
                $query->whereIn('user_id', $userIds);
            }

            $salesData[] = (int) $query->sum('total_amount');

            $bookings = (clone $query)->with('bookingTax')->get();
            $convenienceFeeData[] = (int) $bookings->sum(fn($b) => $b->bookingTax->convenience_fee ?? 0);
        }

        return [
            'sales' => $salesData,
            'convenience_fees' => $convenienceFeeData,
        ];
    }

    /**
     * Get bookings by payment method
     */
    public function getBookingsByPaymentMethod(string $bookingType, $userIds, string $paymentMethod, ?array $dateRange = null): Collection
    {
        $query = Booking::where('booking_type', $bookingType)
            ->where('payment_method', $paymentMethod);

        if ($userIds) {
            $userIds = is_array($userIds) ? $userIds : [$userIds];
            $query->whereIn('user_id', $userIds);
        }

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        return $query->get();
    }

    /**
     * Get ticket IDs for organizer's events
     */
    public function getOrganizerTicketIds(int $userId): array
    {
        return Ticket::where('user_id', $userId)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Get bookings for specific tickets
     */
    public function getBookingsByTickets(Collection $ticketIds, ?array $dateRange = null): Collection
    {
        $query = Booking::whereIn('ticket_id', $ticketIds);

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange);
        }

        return $query->get();
    }
}
