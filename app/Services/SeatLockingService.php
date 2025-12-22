<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

readonly class SeatLockingService
{
    private const int LOCK_DURATION = 600;
    private const int MAX_SEATS_PER_USER = 10;
    private const int USER_LOCK_TTL_BUFFER = 60;

    /**
     * Acquire a lock for a seat
     */
    public function acquireSeatLock(
        int $eventId,
        int $seatId,
        string $sessionId,
        int $duration = self::LOCK_DURATION
    ): bool {
        $lockKey = $this->getSeatLockKey($eventId, $seatId);
        $userLocksKey = $this->getUserLocksKey($eventId, $sessionId);

        // Check if already our lock
        $currentHolder = Redis::get($lockKey);
        if ($currentHolder === $sessionId) {
            Redis::expire($lockKey, $duration);
            Redis::expire($userLocksKey, $duration + self::USER_LOCK_TTL_BUFFER);
            return true;
        }

        // Try to acquire if not exists
        $locked = Redis::set($lockKey, $sessionId, 'EX', $duration, 'NX');

        if ($locked) {
            Redis::sadd($userLocksKey, $seatId);
            Redis::expire($userLocksKey, $duration + self::USER_LOCK_TTL_BUFFER);
            return true;
        }

        return false;
    }

    /**
     * Acquire locks for multiple seats
     */
    public function acquireBatchSeatLocks(
        int $eventId,
        array $seatIds,
        string $sessionId,
        int $duration = self::LOCK_DURATION
    ): array {
        // Check max seats limit
        $currentLocks = $this->getUserLocks($eventId, $sessionId);
        $newSeats = array_diff($seatIds, $currentLocks);
        $totalAfterLock = count($currentLocks) + count($newSeats);

        if ($totalAfterLock > self::MAX_SEATS_PER_USER) {
            return [
                'success' => false,
                'locked_seats' => [],
                'failed_seats' => $seatIds,
                'error' => 'Maximum ' . self::MAX_SEATS_PER_USER . ' seats allowed'
            ];
        }

        $lockedSeats = [];
        $failedSeats = [];

        foreach ($seatIds as $seatId) {
            if ($this->acquireSeatLock($eventId, $seatId, $sessionId, $duration)) {
                $lockedSeats[] = $seatId;
            } else {
                $failedSeats[] = $seatId;
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
     * Get all seats locked by a user for an event
     */
    public function getUserLocks(int $eventId, string $sessionId): array
    {
        $userLocksKey = $this->getUserLocksKey($eventId, $sessionId);
        $seats = Redis::smembers($userLocksKey);

        if (empty($seats)) {
            return [];
        }

        $validSeats = [];
        $staleSeats = [];

        foreach ($seats as $seatId) {
            if ($this->getLockHolder($eventId, (int) $seatId) === $sessionId) {
                $validSeats[] = (int) $seatId;
            } else {
                $staleSeats[] = $seatId;
            }
        }

        // Cleanup stale entries
        if (!empty($staleSeats)) {
            Redis::srem($userLocksKey, ...$staleSeats);
        }

        return $validSeats;
    }

    /**
     * Get TTL of user's locks (minimum TTL)
     */
    public function getUserLocksTTL(int $eventId, string $sessionId): ?int
    {
        $seats = $this->getUserLocks($eventId, $sessionId);

        if (empty($seats)) {
            return null;
        }

        $minTTL = null;

        foreach ($seats as $seatId) {
            $ttl = Redis::ttl($this->getSeatLockKey($eventId, $seatId));

            if ($ttl > 0 && ($minTTL === null || $ttl < $minTTL)) {
                $minTTL = $ttl;
            }
        }

        return $minTTL;
    }

    /**
     * Extend TTL for multiple seats
     */
    public function extendBatchLocks(
        int $eventId,
        array $seatIds,
        string $sessionId,
        int $duration = self::LOCK_DURATION
    ): bool {
        $userLocksKey = $this->getUserLocksKey($eventId, $sessionId);

        foreach ($seatIds as $seatId) {
            $lockKey = $this->getSeatLockKey($eventId, $seatId);

            if (Redis::get($lockKey) === $sessionId) {
                Redis::expire($lockKey, $duration);
            }
        }

        Redis::expire($userLocksKey, $duration + self::USER_LOCK_TTL_BUFFER);

        return true;
    }

    /**
     * Check if a seat is currently locked
     */
    public function isSeatLocked(int $eventId, int $seatId): bool
    {
        return Redis::exists($this->getSeatLockKey($eventId, $seatId)) > 0;
    }

    /**
     * Get the session ID holding the lock
     */
    public function getLockHolder(int $eventId, int $seatId): ?string
    {
        return Redis::get($this->getSeatLockKey($eventId, $seatId));
    }

    /**
     * Release a seat lock (atomic with Lua)
     */
    public function releaseSeatLock(int $eventId, int $seatId, string $sessionId): bool
    {
        $lockKey = $this->getSeatLockKey($eventId, $seatId);
        $userLocksKey = $this->getUserLocksKey($eventId, $sessionId);

        $script = <<<'LUA'
            if redis.call('get', KEYS[1]) == ARGV[1] then
                redis.call('srem', KEYS[2], ARGV[2])
                return redis.call('del', KEYS[1])
            else
                return 0
            end
        LUA;

        return Redis::eval($script, 2, $lockKey, $userLocksKey, $sessionId, (string) $seatId) > 0;
    }

    /**
     * Release multiple seat locks
     */
    public function releaseBatchLocks(int $eventId, array $seatIds, string $sessionId): array
    {
        $released = 0;
        $failed = 0;

        foreach ($seatIds as $seatId) {
            if ($this->releaseSeatLock($eventId, (int) $seatId, $sessionId)) {
                $released++;
            } else {
                $failed++;
            }
        }

        return compact('released', 'failed');
    }

    /**
     * Release all locks for a user on an event
     */
    public function releaseAllUserLocks(int $eventId, string $sessionId): array
    {
        $seats = $this->getUserLocks($eventId, $sessionId);

        return empty($seats)
            ? ['released' => 0, 'failed' => 0]
            : $this->releaseBatchLocks($eventId, $seats, $sessionId);
    }

    /**
     * Force release (admin only)
     */
    public function forceReleaseLock(int $eventId, int $seatId): bool
    {
        return Redis::del($this->getSeatLockKey($eventId, $seatId)) > 0;
    }

    /**
     * Get all locked seats for an event
     */
    public function getLockedSeatsForEvent(int $eventId): array
    {
        $pattern = $this->getSeatLockKey($eventId, '*');
        $keys = Redis::keys($pattern);

        $lockedSeats = [];

        foreach ($keys as $key) {
            if (preg_match('/seat_lock:\d+:(\d+)/', $key, $matches)) {
                $seatId = $matches[1];
                $lockedSeats[$seatId] = Redis::get($this->getSeatLockKey($eventId, $seatId));
            }
        }

        return $lockedSeats;
    }

    /**
     * Clear all locks for an event (admin)
     */
    public function clearEventLocks(int $eventId): int
    {
        $keys = Redis::keys($this->getSeatLockKey($eventId, '*'));

        return empty($keys) ? 0 : Redis::del(...$keys);
    }

    private function getSeatLockKey(int $eventId, int|string $seatId): string
    {
        return "seat_lock:{$eventId}:{$seatId}";
    }

    private function getUserLocksKey(int $eventId, string $sessionId): string
    {
        return "user_locks:{$eventId}:{$sessionId}";
    }
}
