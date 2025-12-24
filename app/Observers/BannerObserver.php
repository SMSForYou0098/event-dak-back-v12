<?php

namespace App\Observers;

use App\Models\Banner;
use Illuminate\Support\Facades\Cache;

class BannerObserver
{
    /**
     * Handle the Banner "created" event.
     * Clear all banner-related caches when new banner is created
     */
    public function created(Banner $banner): void
    {
        $this->clearBannerCaches($banner);
    }

    /**
     * Handle the Banner "updated" event.
     * Clear all banner-related caches when banner is updated
     */
    public function updated(Banner $banner): void
    {
        $this->clearBannerCaches($banner);
    }

    /**
     * Handle the Banner "deleted" event.
     * Clear all banner-related caches when banner is deleted
     */
    public function deleted(Banner $banner): void
    {
        $this->clearBannerCaches($banner);
    }

    /**
     * Handle the Banner "restored" event.
     */
    public function restored(Banner $banner): void
    {
        $this->clearBannerCaches($banner);
    }

    /**
     * Clear all banner-related caches
     * Uses cache tags for efficient bulk invalidation
     */
    protected function clearBannerCaches(Banner $banner): void
    {
        try {
            // Clear cache tags (if Redis/Memcached is used)
            if (config('cache.default') !== 'file') {
                Cache::tags(['banners'])->flush();
            } else {
                // Fallback: Clear specific cache keys for file-based cache
                $this->clearSpecificCaches($banner);
            }
        } catch (\Exception $e) {
            // If Redis is down, we can't clear the cache, but we shouldn't crash the app.
            // Attempt to clear specific keys using the default store as a fallback
            try {
                $this->clearSpecificCaches($banner);
            } catch (\Exception $ex) {
                // Suppress further errors
            }
        }
    }

    /**
     * Clear specific cache keys (for file-based cache)
     */
    protected function clearSpecificCaches(Banner $banner): void
    {
        // Clear all banners cache
        Cache::forget('banners_all_none');

        // Clear type-specific cache
        if ($banner->type) {
            Cache::forget("banners_{$banner->type}_none");
        }

        // Clear category-specific cache
        if ($banner->category) {
            Cache::forget("banners_category_{$banner->category}");
        }

        // Clear organization-specific cache
        if ($banner->org_id) {
            Cache::forget("banners_organisation_{$banner->org_id}");
        }

        // Clear single banner cache
        Cache::forget("banner_{$banner->id}");
    }
}
