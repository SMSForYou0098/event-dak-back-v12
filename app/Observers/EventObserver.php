<?php

namespace App\Observers;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     * Clear all event-related caches when new event is created
     */
    public function created(Event $event): void
    {
        $this->clearEventCaches($event);
    }

    /**
     * Handle the Event "updated" event.
     * Clear all event-related caches when event is updated
     */
    public function updated(Event $event): void
    {
        $this->clearEventCaches($event);
    }

    /**
     * Handle the Event "deleted" event.
     * Clear all event-related caches when event is deleted
     */
    public function deleted(Event $event): void
    {
        $this->clearEventCaches($event);
    }

    /**
     * Handle the Event "restored" event.
     */
    public function restored(Event $event): void
    {
        $this->clearEventCaches($event);
    }

    /**
     * Clear all event-related caches
     * Uses cache tags for efficient bulk invalidation
     */
    protected function clearEventCaches(Event $event): void
    {
        try {
            // Clear cache tags (if Redis/Memcached is used)
            if (config('cache.default') !== 'file') {
                Cache::tags(['events'])->flush();
            } else {
                // Fallback: Clear specific cache keys for file-based cache
                $this->clearSpecificCaches($event);
            }
        } catch (\Exception $e) {
            // If Redis is down, we can't clear the cache, but we shouldn't crash the app.
            // Ideally, we should log this.
            // \Log::error('Failed to clear event cache: ' . $e->getMessage());

            // Attempt to clear specific keys using the default store as a fallback
            try {
                $this->clearSpecificCaches($event);
            } catch (\Exception $ex) {
                // Suppress further errors
            }
        }
    }

    /**
     * Clear specific cache keys (for file-based cache)
     */
    protected function clearSpecificCaches(Event $event): void
    {
        // Clear featured events cache
        Cache::forget('events_featured');

        // Clear home page events cache
        Cache::forget('events_home');

        // Clear category-specific cache
        if ($event->category) {
            Cache::forget("events_category_{$event->category}");
        }

        // Clear user-specific cache
        if ($event->user_id) {
            Cache::forget("events_user_{$event->user_id}");
        }

        // Clear single event cache
        Cache::forget("event_{$event->event_key}");
    }
}
