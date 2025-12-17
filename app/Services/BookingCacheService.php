<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class BookingCacheService
{
    /**
     * Cache TTL in seconds (default: 60 seconds)
     */
    private int $ttl = 60;

    /**
     * Get cached booking data or execute callback
     * Octane-compatible: No closure serialization
     */
    public function remember(string $type, int $id, callable $callback): mixed
    {
        $cacheKey = $this->getCacheKey($type, $id);

        $data = Cache::get($cacheKey);

        if ($data === null) {
            $data = $callback();
            Cache::put($cacheKey, $data, $this->ttl);
        }

        return $data;
    }

    /**
     * Invalidate cache for a specific booking type and ID
     */
    public function invalidate(string $type, int $id): void
    {
        $cacheKey = $this->getCacheKey($type, $id);
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate all booking caches for a user
     */
    public function invalidateUserBookings(int $userId): void
    {
        $types = ['agent', 'sponsor', 'accreditation', 'user'];

        foreach ($types as $type) {
            $this->invalidate($type, $userId);
        }
    }

    /**
     * Generate consistent cache key
     */
    public function getCacheKey(string $type, int $id): string
    {
        return sprintf('booking_%s_%d', $type, $id);
    }

    /**
     * Set custom TTL
     */
    public function setTtl(int $seconds): self
    {
        $this->ttl = $seconds;
        return $this;
    }
}
