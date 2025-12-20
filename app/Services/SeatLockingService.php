<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/**
 * Seat Locking Service
 * 
 * Implements Redis-based seat locking to prevent race conditions and double-bookings
 * during high-traffic scenarios. Uses Redis as a distributed lock mechanism with
 * configurable TTL (Time To Live).
 * 
 * Lock Key Format: seat_lock:{eventId}:{seatId}
 * Lock Value: Booking session identifier (prevents accidental unlock by other requests)
 * 
 * Usage:
 *   1. acquireSeatLock() - Attempt to lock a seat
 *   2. isSeatLocked() - Check if seat is currently locked
 *   3. releaseSeatLock() - Release the lock after successful booking
 *   4. releaseBatchLocks() - Release multiple locks at once
 */
class SeatLockingService
{
    /**
     * Lock duration in seconds
     * After this time, the lock automatically expires (prevents deadlocks)
     */
    const LOCK_DURATION = 300; // 5 minutes

    /**
     * Maximum lock acquisition retries
     */
    const MAX_LOCK_RETRIES = 3;

    /**
     * Retry delay in milliseconds
     */
    const RETRY_DELAY_MS = 100;

    /**
     * Acquire a lock for a seat
     * 
     * Uses Redis SET with NX (only if not exists) and EX (expiry) for atomic operation.
     * This prevents multiple concurrent bookings of the same seat.
     * 
     * @param int $eventId - Event ID
     * @param int $seatId - Seat ID
     * @param string $sessionId - Unique session/request identifier
     * @param int $duration - Lock duration in seconds (default: 300)
     * @return bool - True if lock acquired, false if seat already locked
     * @throws \Exception
     */
    public function acquireSeatLock(
        int $eventId,
        int $seatId,
        string $sessionId,
        int $duration = self::LOCK_DURATION
    ): bool {
        try {
            $lockKey = $this->getLockKey($eventId, $seatId);

            // 🔥 ATOMIC: SET only if key doesn't exist (NX) with expiry (EX)
            // This is the core of Redis distributed locking
            $locked = Redis::set(
                $lockKey,
                $sessionId,
                'EX',    // Expiry in seconds
                $duration,
                'NX'     // Only if not exists
            );

            if ($locked) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            throw new \Exception('Failed to acquire seat lock: ' . $e->getMessage());
        }
    }

    /**
     * Acquire locks for multiple seats
     * 
     * Efficiently locks multiple seats for a single booking.
     * If any seat lock fails, previously acquired locks are released.
     * 
     * @param int $eventId - Event ID
     * @param array $seatIds - Array of seat IDs to lock
     * @param string $sessionId - Unique session/request identifier
     * @param int $duration - Lock duration in seconds
     * @return array - ['success' => bool, 'locked_seats' => array, 'failed_seats' => array]
     */
    public function acquireBatchSeatLocks(
        int $eventId,
        array $seatIds,
        string $sessionId,
        int $duration = self::LOCK_DURATION
    ): array {
        $lockedSeats = [];
        $failedSeats = [];

        foreach ($seatIds as $seatId) {
            try {
                if ($this->acquireSeatLock($eventId, $seatId, $sessionId, $duration)) {
                    $lockedSeats[] = $seatId;
                } else {
                    $failedSeats[] = $seatId;
                    // If any seat lock fails, release all previously locked seats
                    $this->releaseBatchLocks($eventId, $lockedSeats, $sessionId);

                    return [
                        'success' => false,
                        'locked_seats' => [],
                        'failed_seats' => $failedSeats
                    ];
                }
            } catch (\Exception $e) {
                $failedSeats[] = $seatId;
                // Release already locked seats
                $this->releaseBatchLocks($eventId, $lockedSeats, $sessionId);

                return [
                    'success' => false,
                    'locked_seats' => [],
                    'failed_seats' => $failedSeats
                ];
            }
        }

        return [
            'success' => true,
            'locked_seats' => $lockedSeats,
            'failed_seats' => []
        ];
    }

    /**
     * Check if a seat is currently locked
     * 
     * @param int $eventId - Event ID
     * @param int $seatId - Seat ID
     * @return bool
     */
    public function isSeatLocked(int $eventId, int $seatId): bool
    {
        try {
            $lockKey = $this->getLockKey($eventId, $seatId);
            return Redis::exists($lockKey) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the session ID holding the lock (if any)
     * 
     * Useful for debugging and monitoring which request/session has the lock.
     * 
     * @param int $eventId - Event ID
     * @param int $seatId - Seat ID
     * @return string|null - Session ID or null if not locked
     */
    public function getLockHolder(int $eventId, int $seatId): ?string
    {
        try {
            $lockKey = $this->getLockKey($eventId, $seatId);
            return Redis::get($lockKey);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Release a seat lock
     * 
     * Only allows release if the sessionId matches (prevents accidental/malicious unlock).
     * Uses Lua script to ensure atomic check-and-delete operation.
     * 
     * @param int $eventId - Event ID
     * @param int $seatId - Seat ID
     * @param string $sessionId - Session ID that acquired the lock
     * @return bool - True if lock was released
     */
    public function releaseSeatLock(
        int $eventId,
        int $seatId,
        string $sessionId
    ): bool {
        try {
            $lockKey = $this->getLockKey($eventId, $seatId);

            // 🔥 ATOMIC: Only delete if value matches our sessionId (Lua script)
            // This prevents other sessions from releasing our locks
            $result = Redis::eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                1,
                $lockKey,
                $sessionId
            );

            if ($result) {
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Release multiple seat locks at once
     * 
     * @param int $eventId - Event ID
     * @param array $seatIds - Array of seat IDs to unlock
     * @param string $sessionId - Session ID that acquired the locks
     * @return array - ['released' => count, 'failed' => count]
     */
    public function releaseBatchLocks(
        int $eventId,
        array $seatIds,
        string $sessionId
    ): array {
        $released = 0;
        $failed = 0;

        foreach ($seatIds as $seatId) {
            if ($this->releaseSeatLock($eventId, $seatId, $sessionId)) {
                $released++;
            } else {
                $failed++;
            }
        }

        return [
            'released' => $released,
            'failed' => $failed
        ];
    }

    /**
     * Force release a lock (admin/emergency use only)
     * 
     * Warning: This bypasses session ID validation and should only be used
     * in admin interfaces for emergency unlock or cleanup operations.
     * 
     * @param int $eventId - Event ID
     * @param int $seatId - Seat ID
     * @return bool
     */
    public function forceReleaseLock(int $eventId, int $seatId): bool
    {
        try {
            $lockKey = $this->getLockKey($eventId, $seatId);
            $result = Redis::del($lockKey);

            return $result > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all locked seats for an event
     * 
     * Useful for monitoring and debugging. Returns all seat locks currently
     * held for a specific event.
     * 
     * @param int $eventId - Event ID
     * @return array - ['seat_id' => 'session_id', ...]
     */
    public function getLockedSeatsForEvent(int $eventId): array
    {
        try {
            $pattern = $this->getLockKey($eventId, '*');
            $keys = Redis::keys($pattern);

            $lockedSeats = [];
            foreach ($keys as $key) {
                // Extract seat_id from key format: seat_lock:eventId:seatId
                preg_match('/seat_lock:\d+:(\d+)/', $key, $matches);
                if (isset($matches[1])) {
                    $seatId = $matches[1];
                    $sessionId = Redis::get($key);
                    $lockedSeats[$seatId] = $sessionId;
                }
            }

            return $lockedSeats;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Clear all locks for an event (admin use)
     * 
     * Emergency cleanup function. Use with caution!
     * 
     * @param int $eventId - Event ID
     * @return int - Number of locks cleared
     */
    public function clearEventLocks(int $eventId): int
    {
        try {
            $pattern = $this->getLockKey($eventId, '*');
            $keys = Redis::keys($pattern);

            if (empty($keys)) {
                return 0;
            }

            $count = Redis::del(...$keys);
            return $count;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Generate Redis lock key
     * 
     * Key format: seat_lock:{eventId}:{seatId}
     * 
     * @param int $eventId
     * @param int|string $seatId
     * @return string
     */
    private function getLockKey(int $eventId, $seatId): string
    {
        return "seat_lock:{$eventId}:{$seatId}";
    }
}
