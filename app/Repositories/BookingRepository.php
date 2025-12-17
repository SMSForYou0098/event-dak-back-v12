<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Models\MasterBooking;
use App\Models\PenddingBooking;
use App\Models\PenddingBookingsMaster;
use App\Models\ComplimentaryBookings;
use App\Models\PosBooking;
use Illuminate\Support\Collection;

class BookingRepository
{
    /**
     * Get bookings by type with date range and role-based filtering
     */
    public function getBookingsByType(
        string $type,
        array $dateRange,
        ?int $userId = null,
        ?string $userRole = null,
        ?array $ticketIds = null
    ): Collection {
        $query = Booking::withTrashed()
            ->select([
                'id',
                'set_id',
                'booking_by',
                'user_id',
                'ticket_id',
                'status',
                'total_amount',
                'discount',
                'quantity',
                'booking_type',
                'created_at',
                'attendee_id',
                'token',
                'master_token',
                'email',
                'name',
                'number',
                'payment_method',
                'seat_name',
                'section_id',
                'batch_id',
                'deleted_at'
            ])
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('booking_type', $type);

        // Apply role-based filtering
        if ($userRole === 'Agent' || $userRole === 'Sponsor') {
            $query->where('booking_by', $userId);
        } elseif ($userRole === 'Organizer' && $ticketIds) {
            $query->whereIn('ticket_id', $ticketIds);
        }

        return $query->withRelations()->get();
    }

    /**
     * Get master bookings by type with date range
     */
    public function getMasterBookingsByType(
        string $type,
        array $dateRange,
        ?int $userId = null,
        ?string $userRole = null
    ): Collection {
        $query = MasterBooking::withTrashed()
            ->select([
                'id',
                'booking_id',
                'order_id',
                'set_id',
                'booking_by',
                'booking_type',
                'total_amount',
                'discount',
                'created_at',
                'payment_method',
                'deleted_at'
            ])
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('booking_type', $type);

        // Apply role-based filtering
        if ($userRole === 'Agent' || $userRole === 'Sponsor') {
            $query->where('booking_by', $userId);
        }

        return $query->get();
    }

    /**
     * Get pending bookings with optimized eager loading
     */
    public function getPendingBookings(
        array $dateRange,
        ?array $ticketIds = null,
        ?string $searchTerm = null
    ): Collection {
        $query = PenddingBooking::withTrashed()
            ->with([
                'ticket:id,name,event_id,price',
                'ticket.event:id,name,user_id',
                'ticket.event.user:id,name,organisation',
                'user:id,name,number,email,photo,company_name',
                'attendee:id,booking_id,Name,Mo,Email',
                'paymentLog'
            ])
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->whereNull('deleted_at');

        // Apply search filter
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('pendding_bookings.name', 'ILIKE', $searchTerm)
                    ->orWhereRaw('pendding_bookings.number::text ILIKE ?', [$searchTerm])
                    ->orWhere('pendding_bookings.email', 'ILIKE', $searchTerm)
                    ->orWhere('pendding_bookings.session_id', 'ILIKE', $searchTerm)
                    ->orWhereRaw('pendding_bookings.ticket_id::bigint IN (
                        SELECT t.id FROM tickets t
                        LEFT JOIN events e ON t.event_id = e.id
                        WHERE (t.name ILIKE ? OR e.name ILIKE ?)
                        AND t.deleted_at IS NULL
                    )', [$searchTerm, $searchTerm]);
            });
        }

        // Filter by organizer's tickets
        if ($ticketIds !== null && !empty($ticketIds)) {
            $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
            $query->whereRaw("pendding_bookings.ticket_id::bigint IN ({$placeholders})", $ticketIds);
        }

        return $query->get();
    }

    /**
     * Get pending master bookings
     */
    public function getPendingMasterBookings(
        array $dateRange,
        ?string $searchTerm = null
    ): Collection {
        $query = PenddingBookingsMaster::withTrashed()
            ->with(['paymentLog', 'user:id,name,number,email'])
            ->select([
                'id',
                'user_id',
                'booking_id',
                'session_id',
                'order_id',
                'payment_id',
                'amount',
                'discount',
                'deleted_at',
                'created_at'
            ])
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->whereNull('deleted_at');

        // Apply search to master bookings
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('session_id', 'ILIKE', $searchTerm)
                    ->orWhere('order_id', 'ILIKE', $searchTerm)
                    ->orWhereRaw('user_id IN (
                        SELECT id FROM users 
                        WHERE name ILIKE ? OR number::text ILIKE ? OR email ILIKE ?
                    )', [$searchTerm, $searchTerm, $searchTerm]);
            });
        }

        return $query->latest()->get();
    }

    /**
     * Get bookings by user ID across all booking types
     */
    public function getUserBookings(int $userId): Collection
    {
        // Get all master bookings
        $masterBookings = MasterBooking::where('user_id', $userId)
            ->latest()
            ->get();

        $allBookingIds = [];
        $masterBookings->each(function ($masterBooking) use (&$allBookingIds) {
            $bookingIds = $masterBooking->booking_id;
            if (is_array($bookingIds)) {
                $allBookingIds = array_merge($allBookingIds, $bookingIds);
                $masterBooking->bookings = Booking::whereIn('id', $bookingIds)
                    ->whereNull('deleted_at')
                    ->with([
                        'ticket',
                        'ticket.event.user',
                        'ticket.event.Category',
                        'ticket.event.eventMedia:event_id,thumbnail',
                        'user',
                        'attendee'
                    ])
                    ->latest()
                    ->get();
            } else {
                $masterBooking->bookings = collect();
            }
        })->map(function ($booking) {
            $booking->is_deleted = $booking->trashed();
            $booking->type = 'MasterBooking';
            return $booking;
        });

        // Get normal bookings
        $normalBookings = Booking::where('user_id', $userId)
            ->with([
                'ticket.event.user',
                'ticket.event.Category',
                'ticket.event.eventMedia:event_id,thumbnail',
                'user',
                'attendee'
            ])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                $booking->type = 'Booking';
                return $booking;
            });

        // Filter out bookings that are part of master bookings
        $normalBookings = $normalBookings->filter(function ($booking) use ($allBookingIds) {
            return !in_array($booking->id, $allBookingIds);
        });

        return $masterBookings->concat($normalBookings)->sortByDesc('created_at')->values();
    }

    /**
     * Generic method to get bookings by specific booking type (agent, sponsor, accreditation)
     * This replaces the duplicate logic in agentBooking, sponsorBooking, accreditationBooking
     */
    public function getBookingsBySpecificType(
        string $modelClass,
        string $masterModelClass,
        string $filterColumn,
        int $id,
        bool $isAdmin = false,
        ?array $agentIds = null
    ): array {
        // Build query based on role
        if ($isAdmin) {
            $allBookings = $modelClass::withTrashed()
                ->latest()
                ->with('ticket')
                ->get();
            $masterBookings = $masterModelClass::withTrashed()
                ->latest()
                ->get();
        } elseif ($agentIds !== null) {
            $allBookings = $modelClass::withTrashed()
                ->latest()
                ->whereIn($filterColumn, $agentIds)
                ->with('ticket')
                ->get();
            $masterBookings = $masterModelClass::withTrashed()
                ->whereIn($filterColumn, $agentIds)
                ->latest()
                ->get();
        } else {
            $allBookings = $modelClass::withTrashed()
                ->latest()
                ->where($filterColumn, $id)
                ->with('ticket')
                ->get();
            $masterBookings = $masterModelClass::withTrashed()
                ->where($filterColumn, $id)
                ->latest()
                ->get();
        }

        return $this->processMasterBookingData($allBookings, $masterBookings);
    }

    /**
     * Process master booking data - extract IDs and calculate totals
     * Octane-compatible (no closures)
     */
    public function processMasterBookingData(Collection $allBookings, Collection $masterBookings): array
    {
        $masterIds = $masterBookings->pluck('booking_id');

        $idsGroup = $masterIds->map(function ($item) {
            return $item;
        });

        $firstIds = $idsGroup->map(function ($ids) {
            return $ids[0] ?? null;
        })->filter();

        $firstIdsArray = $firstIds->toArray();

        $decodedMasterIds = $masterIds->flatMap(function ($item) {
            if (is_string($item)) {
                return json_decode($item, true) ?: [];
            }
            return $item;
        })->toArray();

        $filteredMainBookings = $allBookings->filter(function ($booking) use ($decodedMasterIds) {
            return !in_array($booking->id, $decodedMasterIds);
        });

        $filteredMasterBookings = $allBookings->filter(function ($booking) use ($firstIdsArray) {
            return in_array($booking->id, $firstIdsArray);
        });

        $combinedBookings = $filteredMainBookings->merge($filteredMasterBookings)->unique('id');
        $amount = $combinedBookings->sum('amount');
        $discount = $combinedBookings->sum('discount');

        return [
            'allBookings' => $allBookings,
            'bookings' => $combinedBookings,
            'amount' => $amount,
            'discount' => $discount
        ];
    }

    /**
     * Get bookings by session ID
     */
    public function getBookingsBySessionId(string $sessionId): Collection
    {
        return Booking::where('session_id', $sessionId)
            ->with(['ticket.event.user', 'attendee', 'user'])
            ->get();
    }

    /**
     * Get master booking by session ID
     */
    public function getMasterBookingBySessionId(string $sessionId): ?MasterBooking
    {
        return MasterBooking::where('session_id', $sessionId)->first();
    }

    /**
     * Get bookings by phone number for box office
     */
    public function getBookingsByPhoneNumber(string $number): array
    {
        // 1. Fetch all bookings (normal + agent) in one query
        $allBookings = Booking::where('number', $number)
            ->withTrashed() // Include soft deleted records to check 'trashed()' later
            ->with(['ticket.event.user', 'ticket.event.Category', 'user', 'attendee'])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                return $booking;
            });

        // 2. Separate them in memory
        $bookings = $allBookings->filter(fn($b) => $b->booking_type !== 'agent')->values();
        $agentBookings = $allBookings->filter(fn($b) => $b->booking_type === 'agent')->values();

        // 3. Get Master Bookings (derived from session_ids of fetched bookings)
        $masterBookingIds = $allBookings->pluck('session_id')->filter()->unique();
        $masterBookings = $masterBookingIds->isNotEmpty()
            ? MasterBooking::whereIn('session_id', $masterBookingIds)->get()
            : collect();

        // 4. Fetch Complimentary Bookings (different table, so separate query is needed)
        $complimentaryBookings = ComplimentaryBookings::where('number', $number)
            ->withTrashed()
            ->with(['ticket.event.user', 'ticket.event.Category', 'user'])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                return $booking;
            });

        return [
            'bookings' => $bookings,
            'master_bookings' => $masterBookings,
            'complimentary_bookings' => $complimentaryBookings,
            'agent_bookings' => $agentBookings,
        ];
    }

    /**
     * Get booking statistics by type
     */
    public function getBookingStats(string $type, int $id, bool $isAdmin = false): array
    {
        if ($type === 'agent') {
            $query = $isAdmin
                ? Booking::where('booking_type', 'agent')
                : Booking::where('booking_type', 'agent')->where('booking_by', $id);

            return [
                'total_bookings' => $query->count(),
                'total_amount' => $query->sum('total_amount'),
                'total_discount' => $query->sum('discount'),
            ];
        } elseif ($type === 'pos') {
            $query = $isAdmin
                ? PosBooking::query()
                : PosBooking::where('user_id', $id);

            return [
                'total_bookings' => $query->count(),
                'total_amount' => $query->sum('amount'),
                'total_discount' => $query->sum('discount'),
            ];
        }

        return [
            'total_bookings' => 0,
            'total_amount' => 0,
            'total_discount' => 0,
        ];
    }

    /**
     * Extract all booking IDs from master bookings
     */
    public function extractBookingIds(Collection $masterBookings): array
    {
        return $masterBookings->flatMap(function ($master) {
            return is_array($master->booking_id) ? $master->booking_id : [];
        })->unique()->values()->toArray();
    }
}
