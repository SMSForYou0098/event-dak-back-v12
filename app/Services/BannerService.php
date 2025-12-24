<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class BannerService
{
    /**
     * Get banners with filters and caching
     * Uses permanent cache - invalidated by BannerObserver on changes
     * Supports Redis cache tags for efficient invalidation
     * 
     * @param string|null $type
     * @param string|null $id
     * @return Collection
     */
    public function getBannersWithCache(?string $type, ?string $id): Collection
    {
        try {
            // Create unique cache key based on type and id
            $cacheKey = 'banners_' . ($type ?? 'all') . '_' . ($id ?? 'none');

            // Use cache tags if Redis is available
            $cache = $this->getCacheDriver();

            return $cache->rememberForever($cacheKey, function () use ($type, $id) {
                return $this->fetchBanners($type, $id);
            });
        } catch (\Exception $e) {
            // Fallback: Fetch directly from DB if cache fails
            return $this->fetchBanners($type, $id);
        }
    }

    /**
     * Fetch banners from database with filters
     * 
     * @param string|null $type
     * @param string|null $id
     * @return Collection
     */
    protected function fetchBanners(?string $type, ?string $id): Collection
    {
        $query = Banner::query();

        if ($type) {
            $query->where('type', $type);
        }

        // Handle type-specific logic
        if ($type === 'category' && $id) {
            $category = Category::select('id', 'title')->find($id);

            if ($category) {
                $query->where('category', $category->id);
            } else {
                return collect();
            }
        }

        if ($type === 'organisation' && $id) {
            $query->whereHas('event.user', function ($q) use ($id) {
                $q->where('id', $id);
            });
        }

        $query->with([
            'event:id,name,user_id,venue_id',
            'event.user:id,organisation',
            'event.venue:id,city,name',
            'category:id,title',
        ]);

        return $query->get();
    }

    /**
     * Get cache driver with tags support (Redis) or without (file)
     * Automatically selects best caching strategy based on driver
     * 
     * @return \Illuminate\Cache\Repository|\Illuminate\Cache\TaggedCache
     */
    protected function getCacheDriver()
    {
        // Use cache tags if Redis/Memcached, otherwise use regular cache
        try {
            if (config('cache.default') !== 'file') {
                return Cache::tags(['banners']);
            }
        } catch (\Exception $e) {
            // Fallback to file cache
            return Cache::store('file');
        }

        return Cache::store();
    }
}
