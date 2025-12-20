<?php

namespace App\Services;

/**
 * Booking Service with Seat Locking
 * 
 * Orchestrates the booking flow with seat locking to ensure:
 * 1. Seats are available before locking
 * 2. Seats are locked for the booking duration
 * 3. Seats are released after booking completes or fails
 * 
 * This service bridges EventSeatStatusService and SeatLockingService
 * to provide a complete booking workflow with race condition prevention.
 * 
 * Usage in Controller:
 *   $bookingService->validateAndLockSeats($eventId, $seatIds, $sessionId)
 *   // Perform booking...
 *   $bookingService->releaseSeatLocks($eventId, $seatIds, $sessionId)
 */
class BookingWithLockingService
{
    protected $eventSeatStatusService;
    protected $seatLockingService;

    public function __construct(
        EventSeatStatusService $eventSeatStatusService,
        SeatLockingService $seatLockingService
    ) {
        $this->eventSeatStatusService = $eventSeatStatusService;
        $this->seatLockingService = $seatLockingService;
    }

    /**
     * Validate and lock multiple seats for booking
     * 
     * ✅ Complete pre-booking workflow:
     * 1. Check each seat is available (not booked, not locked)
     * 2. Acquire Redis locks for all seats (atomic - all or nothing)
     * 3. Return success/failure with details
     * 
     * MUST be called BEFORE making booking in database!
     * 
     * @param int $eventId - Event ID
     * @param array $seatIds - Array of seat IDs to book
     * @param string|null $sessionId - Session ID for locking (generated if null)
     * @return array
     * [
     *     'success' => bool,
     *     'session_id' => string,
     *     'locked_seats' => array,
     *     'failed_seats' => array,
     *     'reason' => string (if failed),
     * ]
     */
    public function validateAndLockSeats(
        int $eventId,
        array $seatIds,
        ?string $sessionId = null
    ): array {
        try {
            if (!$sessionId) {
                $sessionId = uniqid('booking_', true);
            }

            // Step 1: Validate all seats are available
            $unavailableSeats = [];
            foreach ($seatIds as $seatId) {
                if (!$this->eventSeatStatusService->isSeatAvailable($seatId, $eventId, $this->seatLockingService)) {
                    $unavailableSeats[] = $seatId;
                }
            }

            if (!empty($unavailableSeats)) {
                return [
                    'success' => false,
                    'session_id' => $sessionId,
                    'locked_seats' => [],
                    'failed_seats' => $unavailableSeats,
                    'reason' => 'Some seats are already booked or locked'
                ];
            }

            // Step 2: Acquire locks for all seats
            $lockResult = $this->seatLockingService->acquireBatchSeatLocks(
                $eventId,
                $seatIds,
                $sessionId
            );

            if (!$lockResult['success']) {

                return [
                    'success' => false,
                    'session_id' => $sessionId,
                    'locked_seats' => $lockResult['locked_seats'],
                    'failed_seats' => $lockResult['failed_seats'],
                    'reason' => 'Failed to acquire locks - seats may have been booked by another user'
                ];
            }

            return [
                'success' => true,
                'session_id' => $sessionId,
                'locked_seats' => $lockResult['locked_seats'],
                'failed_seats' => [],
                'reason' => null
            ];
        } catch (\Exception $e) {

            return [
                'success' => false,
                'session_id' => $sessionId ?? null,
                'locked_seats' => [],
                'failed_seats' => $seatIds,
                'reason' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Release seat locks after successful booking
     * 
     * Call this AFTER all booking database operations are complete.
     * 
     * @param int $eventId - Event ID
     * @param array $seatIds - Array of seat IDs that were locked
     * @param string $sessionId - Session ID that acquired the locks
     * @return array - ['released' => int, 'failed' => int]
     */
    public function releaseSeatLocks(
        int $eventId,
        array $seatIds,
        string $sessionId
    ): array {
        return $this->seatLockingService->releaseBatchLocks($eventId, $seatIds, $sessionId);
    }

    /**
     * Get current lock status for debugging
     * 
     * @param int $eventId - Event ID
     * @return array - ['locked_seats' => [...], 'total_locked' => int]
     */
    public function getLockedSeatsStatus(int $eventId): array
    {
        $lockedSeats = $this->seatLockingService->getLockedSeatsForEvent($eventId);

        return [
            'locked_seats' => $lockedSeats,
            'total_locked' => count($lockedSeats)
        ];
    }

    /**
     * Emergency unlock all seats for an event (admin only)
     * 
     * @param int $eventId - Event ID
     * @return int - Number of locks cleared
     */
    public function emergencyClearEventLocks(int $eventId): int
    {
        return $this->seatLockingService->clearEventLocks($eventId);
    }
}
