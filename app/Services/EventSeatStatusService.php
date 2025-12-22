<?php

namespace App\Services;

use App\Models\EventSeatStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventSeatStatusService
{
    /**
     * Mark a single seat as booked
     */
    public function markSeatAsBooked(
        array $seatData,
        int $bookingId,
        int $ticketId,
        int $eventId,
        ?string $eventKey = null,
        string $bookingType = 'agent',
        ?string $sessionId = null
    ): ?EventSeatStatus {
        $seatId = $seatData['seat_id'] ?? throw new \InvalidArgumentException('Seat ID is required');

        $numericSeatId = $this->extractNumericId($seatId)
            ?? throw new \InvalidArgumentException('Invalid seat ID format');

        $numericSectionId = $this->extractNumericId($seatData['section_id'] ?? null);

        $ess = EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where(fn($q) => $q->where('seat_id', $numericSeatId)->orWhere('seat_id', "seat_{$numericSeatId}"))
            ->lockForUpdate()
            ->first();

        if ($ess?->status === 1) {
            throw new \RuntimeException("Seat {$numericSeatId} is already booked");
        }

        $ess?->update([
            'event_key' => $eventKey,
            'section_id' => $numericSectionId,
            'ticket_id' => $ticketId,
            'booking_id' => $bookingId,
            'status' => 1,
            'type' => $bookingType,
            'seat_name' => $seatData['seat_name'] ?? null,
        ]);

        return $ess;
    }

    /**
     * Mark multiple seats as booked (with transaction)
     */
    public function markMultipleSeatsAsBooked(
        array $seats,
        int $bookingId,
        int $ticketId,
        int $eventId,
        ?string $eventKey = null,
        string $bookingType = 'agent',
        ?string $sessionId = null
    ): array {
        return DB::transaction(fn() => array_filter(
            array_map(
                fn($seat) => $this->markSeatAsBooked($seat, $bookingId, $ticketId, $eventId, $eventKey, $bookingType, $sessionId),
                $seats
            )
        ));
    }

    /**
     * Unbook a seat
     */
    public function unmarkSeat(int $seatId, int $eventId): bool
    {
        $numericSeatId = $this->extractNumericId($seatId);

        if ($numericSeatId === null) {
            return false;
        }

        return EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where(fn($q) => $q->where('seat_id', $numericSeatId)->orWhere('seat_id', "seat_{$numericSeatId}"))
            ->update(['status' => 0, 'booking_id' => null]) > 0;
    }

    /**
     * Unbook multiple seats
     */
    public function unmarkMultipleSeats(array $seatIds, int $eventId): int
    {
        return collect($seatIds)
            ->filter(fn($id) => $this->unmarkSeat($id, $eventId))
            ->count();
    }

    /**
     * Check if a single seat is available
     */
    public function isSeatAvailable(
        mixed $seatId,
        int $eventId,
        ?SeatLockingService $lockingService = null,
        ?string $currentSessionId = null
    ): bool {
        $numericSeatId = $this->extractNumericId($seatId);

        if ($numericSeatId === null) {
            return false;
        }

        // Check database status
        $isBooked = EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where(fn($q) => $q->where('seat_id', $numericSeatId)->orWhere('seat_id', "seat_{$numericSeatId}"))
            ->where('status', 1)
            ->exists();

        if ($isBooked) {
            return false;
        }

        // Check Redis lock
        if ($lockingService?->isSeatLocked($eventId, $numericSeatId)) {
            return $currentSessionId !== null
                && $lockingService->getLockHolder($eventId, $numericSeatId) === $currentSessionId;
        }

        return true;
    }

    /**
     * Check availability for multiple seats (optimized - single DB query)
     */
    public function areSeatsAvailable(
        array $seatIds,
        int $eventId,
        ?SeatLockingService $lockingService = null,
        ?string $currentSessionId = null
    ): array {
        $result = [
            'all_available' => true,
            'available' => [],
            'unavailable' => [],
            'booked' => [],
            'locked_by_others' => []
        ];

        if (empty($seatIds)) {
            return $result;
        }

        // Normalize all seat IDs
        $seatIdMap = collect($seatIds)
            ->mapWithKeys(fn($id) => [$this->extractNumericId($id) => $id])
            ->filter(fn($original, $numeric) => $numeric !== null);

        if ($seatIdMap->isEmpty()) {
            $result['all_available'] = false;
            $result['unavailable'] = $seatIds;
            return $result;
        }

        $numericSeatIds = $seatIdMap->keys()->all();

        // Single query to get all booked seats
        $bookedSeats = EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where('status', 1)
            ->where(
                fn($q) => $q
                    ->whereIn('seat_id', $numericSeatIds)
                    ->orWhereIn('seat_id', array_map(fn($id) => "seat_{$id}", $numericSeatIds))
            )
            ->pluck('seat_id')
            ->map(fn($id) => $this->extractNumericId($id))
            ->filter()
            ->all();

        // Check each seat
        foreach ($numericSeatIds as $numericId) {
            $originalId = $seatIdMap[$numericId];

            // Check if booked in DB
            if (in_array($numericId, $bookedSeats)) {
                $result['all_available'] = false;
                $result['unavailable'][] = $originalId;
                $result['booked'][] = $originalId;
                continue;
            }

            // Check Redis lock
            if ($lockingService?->isSeatLocked($eventId, $numericId)) {
                $lockHolder = $lockingService->getLockHolder($eventId, $numericId);

                if ($currentSessionId !== null && $lockHolder === $currentSessionId) {
                    $result['available'][] = $originalId;
                    continue;
                }

                $result['all_available'] = false;
                $result['unavailable'][] = $originalId;
                $result['locked_by_others'][] = $originalId;
                continue;
            }

            $result['available'][] = $originalId;
        }

        return $result;
    }

    /**
     * Get seat status
     */
    public function getSeatStatus(int $seatId, int $eventId): ?EventSeatStatus
    {
        $numericSeatId = $this->extractNumericId($seatId);

        return $numericSeatId === null ? null : EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where(fn($q) => $q->where('seat_id', $numericSeatId)->orWhere('seat_id', "seat_{$numericSeatId}"))
            ->first();
    }

    /**
     * Get multiple seat statuses (optimized)
     */
    public function getMultipleSeatStatuses(array $seatIds, int $eventId): Collection
    {
        $numericSeatIds = collect($seatIds)
            ->map(fn($id) => $this->extractNumericId($id))
            ->filter()
            ->all();

        if (empty($numericSeatIds)) {
            return collect();
        }

        return EventSeatStatus::query()
            ->where('event_id', $eventId)
            ->where(
                fn($q) => $q
                    ->whereIn('seat_id', $numericSeatIds)
                    ->orWhereIn('seat_id', array_map(fn($id) => "seat_{$id}", $numericSeatIds))
            )
            ->get()
            ->keyBy(fn($ess) => $this->extractNumericId($ess->seat_id));
    }

    /**
     * Batch create seats (for layout setup)
     */
    public function batchCreateSeats(int $eventId, array $seatsData): int
    {
        return DB::transaction(function () use ($eventId, $seatsData) {
            $count = 0;

            foreach ($seatsData as $seatData) {
                $numericSeatId = $this->extractNumericId($seatData['seat_id'] ?? null);

                if ($numericSeatId === null) {
                    continue;
                }

                EventSeatStatus::updateOrCreate(
                    ['event_id' => $eventId, 'seat_id' => $numericSeatId],
                    [
                        'section_id' => $this->extractNumericId($seatData['section_id'] ?? null),
                        'status' => 0,
                        'seat_name' => $seatData['seat_name'] ?? null,
                    ]
                );
                $count++;
            }

            return $count;
        });
    }

    /**
     * Validate seats before booking
     */
    public function validateSeatsBeforeBooking(
        array $requestTickets,
        ?SeatLockingService $lockingService = null,
        ?string $currentSessionId = null
    ): array {
        $seatsByEvent = [];

        foreach ($requestTickets as $ticketData) {
            $seats = $ticketData['seats'] ?? [];
            $ticketId = $ticketData['id'] ?? null;

            if (empty($seats) || !$ticketId) {
                continue;
            }

            $eventId = DB::table('tickets')->where('id', $ticketId)->value('event_id');

            if (!$eventId) {
                continue;
            }

            $seatsByEvent[$eventId] ??= [];

            foreach ($seats as $seat) {
                if (isset($seat['seat_id'])) {
                    $seatsByEvent[$eventId][] = [
                        'seat_id' => $seat['seat_id'],
                        'seat_name' => $seat['seat_name'] ?? $seat['seat_id']
                    ];
                }
            }
        }

        $unavailableSeatNames = [];
        $unavailableSeatIds = [];

        foreach ($seatsByEvent as $eventId => $seats) {
            $seatIds = array_column($seats, 'seat_id');
            $seatNameMap = array_combine($seatIds, array_column($seats, 'seat_name'));

            $availability = $this->areSeatsAvailable($seatIds, $eventId, $lockingService, $currentSessionId);

            foreach ($availability['unavailable'] as $seatId) {
                $unavailableSeatIds[] = $seatId;
                $unavailableSeatNames[] = $seatNameMap[$seatId] ?? $seatId;
            }
        }

        if (!empty($unavailableSeatNames)) {
            $count = count($unavailableSeatNames);

            return [
                'valid' => false,
                'message' => sprintf(
                    'The %s %s %s no longer available',
                    $count === 1 ? 'seat' : 'seats',
                    implode(', ', $unavailableSeatNames),
                    $count === 1 ? 'is' : 'are'
                ),
                'unavailable_seat_ids' => $unavailableSeatIds,
                'unavailable_seat_names' => $unavailableSeatNames
            ];
        }

        return [
            'valid' => true,
            'message' => 'All seats are available',
            'unavailable_seat_ids' => [],
            'unavailable_seat_names' => []
        ];
    }

    /**
     * Extract numeric ID from various formats
     */
    protected function extractNumericId(mixed $value): ?int
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_numeric($value) => (int) $value,
            is_string($value) && preg_match('/(\d+)/', $value, $m) => (int) $m[1],
            default => null
        };
    }
}
