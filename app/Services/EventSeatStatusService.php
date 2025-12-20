<?php

namespace App\Services;

use App\Models\EventSeatStatus;
use Illuminate\Support\Facades\DB;

class EventSeatStatusService
{
    /**
     * Update or create EventSeatStatus for a booked seat
     * 
     * This is the centralized method for marking seats as booked.
     * Used by both Agent and POS booking flows.
     * 
     * ✅ NOW WITH SEAT LOCKING:
     * - Checks if seat is already locked by another session
     * - Checks if seat already has status=1 (booked)
     * - Prevents double-bookings during traffic spikes
     * - Uses Redis for distributed locking
     * 
     * @param array $seatData - Seat information from booking request
     * @param int $bookingId - Booking ID to associate with seat
     * @param int $ticketId - Ticket ID
     * @param int $eventId - Event ID
     * @param string|null $eventKey - Event key
     * @param string $bookingType - Type of booking (agent, pos, sponsor, etc.)
     * @param string|null $sessionId - Session/request identifier for locking (generated if null)
     * @return EventSeatStatus|null
     * @throws \Exception
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
        try {
            // ✅ Validation 1: Check if seat_id exists
            $seatId = $seatData['seat_id'] ?? null;
            if (empty($seatId)) {
                throw new \Exception('Seat ID is required');
            }

            // ✅ Validation 2: Extract numeric ID safely
            $numericSeatId = $this->extractNumericId($seatId);
            if ($numericSeatId === null) {
                throw new \Exception('Invalid seat ID format');
            }

            // 🔥 NEW: Generate session ID if not provided (for locking)
            if (!$sessionId) {
                $sessionId = uniqid('session_', true);
            }

            // 🔥 LOCKING: Find seat and lock it for update to prevent race conditions
            // We check both numeric and 'seat_' prefixed IDs to match existing logic
            $ess = EventSeatStatus::where('event_id', $eventId)
                ->where(function ($query) use ($numericSeatId) {
                    $query->where('seat_id', $numericSeatId)
                        ->orWhere('seat_id', 'seat_' . $numericSeatId);
                })
                ->lockForUpdate()
                ->first();

            // Check if seat is already booked
            if ($ess && (int) $ess->status === 1) {
                throw new \Exception("Seat {$numericSeatId} is already booked and cannot be assigned twice");
            }

            // ✅ Validation 3: Extract section ID safely
            $numericSectionId = $this->extractNumericId($seatData['section_id'] ?? null);

            // 🔥 UPDATE EventSeatStatus
            if ($ess) {
                $ess->update([
                    'event_key'  => $eventKey,
                    'section_id' => $numericSectionId,
                    'ticket_id'  => $ticketId,
                    'booking_id' => $bookingId,
                    'status'     => 1,  // 1 = booked
                    'type'       => $bookingType,
                    'seat_name'  => $seatData['seat_name'] ?? null,
                ]);
            }
            return $ess;
        } catch (\Exception $e) {
            throw new \Exception('EventSeatStatus Error: ' . $e->getMessage());
        }
    }

    /**
     * Process multiple seats for a single booking
     * 
     * Efficiently handles batch seat assignments for a booking.
     * 
     * @param array $seats - Array of seat data
     * @param int $bookingId - Booking ID
     * @param int $ticketId - Ticket ID
     * @param int $eventId - Event ID
     * @param string|null $eventKey - Event key
     * @param string $bookingType - Type of booking
     * @param string|null $sessionId - Session ID for locking
     * @return array - Array of created/updated EventSeatStatus instances
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
        $results = [];

        if (!$sessionId) {
            $sessionId = uniqid('session_', true);
        }

        foreach ($seats as $seat) {
            try {
                $ess = $this->markSeatAsBooked(
                    $seat,
                    $bookingId,
                    $ticketId,
                    $eventId,
                    $eventKey,
                    $bookingType,
                    $sessionId
                );

                if ($ess) {
                    $results[] = $ess;
                }
            } catch (\Exception $e) {
                throw new \Exception(
                    'EventSeatStatus Error: ' . $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Unbook a seat (restore to available status)
     * 
     * Removes booking association from a seat, marking it as available again.
     * 
     * @param int $seatId - Seat ID
     * @param int $eventId - Event ID
     * @return bool - Success status
     */
    public function unmarkSeat(int $seatId, int $eventId): bool
    {
        try {
            $numericSeatId = $this->extractNumericId($seatId);

            if ($numericSeatId === null) {
                throw new \Exception('Invalid seat ID');
            }

            $ess = EventSeatStatus::where('event_id', $eventId)
                ->where(function ($query) use ($numericSeatId) {
                    $query->where('seat_id', $numericSeatId)
                        ->orWhere('seat_id', 'seat_' . $numericSeatId);
                })
                ->first();

            if (!$ess) {
                return true; // Already doesn't exist
            }

            // Reset to available
            $ess->status = 0;
            $ess->booking_id = null;
            $ess->save();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a seat is available for booking
     * 
     * ✅ NEW: Comprehensive check for seat availability:
     * - Checks if seat exists with status=1 (already booked)
     * - Checks if seat is locked in Redis
     * - Returns false if seat is unavailable or locked
     * 
     * MUST be called BEFORE attempting to book a seat!
     * 
     * @param int $seatId - Seat ID
     * @param int $eventId - Event ID
     * @param SeatLockingService|null $lockingService - Optional locking service
     * @return bool - True if seat is available
     */
    /**
     * Get seat status
     * 
     * @param int $seatId - Seat ID
     * @param int $eventId - Event ID
     * @return EventSeatStatus|null
     */
    public function getSeatStatus(int $seatId, int $eventId): ?EventSeatStatus
    {
        $numericSeatId = $this->extractNumericId($seatId);

        if ($numericSeatId === null) {
            return null;
        }

        return EventSeatStatus::where('event_id', $eventId)
            ->where(function ($query) use ($numericSeatId) {
                $query->where('seat_id', $numericSeatId)
                    ->orWhere('seat_id', 'seat_' . $numericSeatId);
            })
            ->first();
    }

    /**
     * Batch create/update seats (for layout setup)
     * 
     * Used during event layout configuration to pre-populate seat statuses.
     * 
     * @param int $eventId - Event ID
     * @param array $seatsData - Array of seat configurations
     * @return int - Count of created/updated seats
     */
    public function batchCreateSeats(int $eventId, array $seatsData): int
    {
        $count = 0;

        try {
            foreach ($seatsData as $seatData) {
                $numericSeatId = $this->extractNumericId($seatData['seat_id'] ?? null);
                $numericSectionId = $this->extractNumericId($seatData['section_id'] ?? null);

                if ($numericSeatId !== null) {
                    EventSeatStatus::updateOrCreate(
                        [
                            'event_id' => $eventId,
                            'seat_id' => $numericSeatId,
                        ],
                        [
                            'section_id' => $numericSectionId,
                            'status' => 0,  // 0 = available
                            'seat_name' => $seatData['seat_name'] ?? null,
                        ]
                    );
                    $count++;
                }
            }
        } catch (\Exception $e) {
            return 0; // Return 0 on error as expected by return type hint
        }

        return $count;
    }

    /**
     * Helper: Extract numeric ID from various formats
     * 
     * Handles formats like:
     * - "123" → 123
     * - 123 → 123
     * - "seat_123" → 123
     * - "section_456" → 456
     * 
     * @param mixed $value
     * @return int|null
     */
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

    /**
     * Check if a specific seat is available
     * 
     * @param int $seatId
     * @param int $eventId
     * @param SeatLockingService|null $lockingService
     * @param string|null $currentSessionId - If provided, allows the seat if locked by THIS session
     * @return bool
     */
    public function isSeatAvailable(
        $seatId,
        int $eventId,
        ?SeatLockingService $lockingService = null,
        ?string $currentSessionId = null
    ): bool {
        try {
            $numericSeatId = $this->extractNumericId($seatId);
            if ($numericSeatId === null) {
                return false;
            }

            $ess = EventSeatStatus::where('event_id', $eventId)
                ->where(function ($query) use ($numericSeatId) {
                    $query->where('seat_id', $numericSeatId)
                        ->orWhere('seat_id', 'seat_' . $numericSeatId);
                })
                ->first();

            // If seat exists and status=1, it's already booked (Database check)
            if ($ess && (int) $ess->status === 1) {
                return false;
            }

            // Redis Lock Check
            if ($lockingService) {
                if ($lockingService->isSeatLocked($eventId, $numericSeatId)) {
                    // If a session ID is provided, check if WE hold the lock
                    if ($currentSessionId) {
                        $lockHolder = $lockingService->getLockHolder($eventId, $numericSeatId);
                        if ($lockHolder === $currentSessionId) {
                            return true; // Valid: We hold the lock
                        }
                    }
                    return false; // Invalid: Locked by someone else (or we didn't provide session ID)
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate seats before booking - Common method for Agent & POS
     * 
     * @param array $requestTickets - Array of ticket data from request
     * @param SeatLockingService|null $lockingService - Optional locking service
     * @param string|null $currentSessionId - Optional session ID to allow self-locked seats
     * @return array - ['valid' => bool, 'message' => string, 'unavailable_seats' => array]
     */
    public function validateSeatsBeforeBooking(
        array $requestTickets,
        ?SeatLockingService $lockingService = null,
        ?string $currentSessionId = null
    ): array {
        try {
            // Build seat validation map: eventId => [seatIds with names]
            $seatValidationMap = [];

            foreach ($requestTickets as $ticketData) {
                if (isset($ticketData['seats']) && is_array($ticketData['seats']) && count($ticketData['seats']) > 0) {
                    // Get the ticket to find event_id
                    $ticketId = $ticketData['id'] ?? null;
                    if (!$ticketId) {
                        continue;
                    }

                    // Use direct query to avoid relationship overhead
                    $eventId = DB::table('tickets')->where('id', $ticketId)->value('event_id');

                    if (!$eventId) {
                        continue;
                    }

                    if (!isset($seatValidationMap[$eventId])) {
                        $seatValidationMap[$eventId] = [];
                    }

                    // Add all seat IDs and names for this event
                    foreach ($ticketData['seats'] as $seat) {
                        if (isset($seat['seat_id'])) {
                            $seatValidationMap[$eventId][] = [
                                'seat_id' => $seat['seat_id'],
                                'seat_name' => $seat['seat_name'] ?? $seat['seat_id']  // Fallback to ID if name not provided
                            ];
                        }
                    }
                }
            }

            // 🔍 Validate all seats across all events
            $unavailableSeatNames = [];
            $unavailableSeatIds = [];

            foreach ($seatValidationMap as $eventId => $seats) {
                foreach ($seats as $seatInfo) {
                    $seatId = $seatInfo['seat_id'];
                    $seatName = $seatInfo['seat_name'];

                    // Convert seat_id to int if needed
                    $numericSeatId = $this->extractNumericId($seatId);

                    if ($numericSeatId === null) {
                        continue;
                    }

                    // Check both: already booked (status=1) AND locked in Redis
                    // Pass currentSessionId to allow self-locked seats
                    if (!$this->isSeatAvailable($numericSeatId, $eventId, $lockingService, $currentSessionId)) {
                        $unavailableSeatNames[] = $seatName;
                        $unavailableSeatIds[] = $seatId;
                    }
                }
            }

            // ❌ If ANY seat is unavailable, return error
            if (!empty($unavailableSeatNames)) {
                $count = count($unavailableSeatNames);
                $seatLabel = $count === 1 ? 'seat' : 'seats';
                $verb = $count === 1 ? 'is' : 'are';
                $seatList = implode(', ', $unavailableSeatNames);

                return [
                    'valid' => false,
                    'message' => "The {$seatLabel} {$seatList} {$verb} no longer available",
                    'unavailable_seat_ids' => $unavailableSeatIds
                ];
            }

            return [
                'valid' => true,
                'message' => 'All seats are available',
                'unavailable_seats' => []
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Seat validation error: ' . $e->getMessage(),
                'unavailable_seats' => []
            ];
        }
    }
}
