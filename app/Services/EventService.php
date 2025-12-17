<?php

namespace App\Services;

use App\Repositories\EventRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EventService
{
    protected $eventRepository;

    public function __construct(EventRepository $eventRepository)
    {
        $this->eventRepository = $eventRepository;
    }

    /**
     * Get featured events sorted by date status
     * Uses permanent cache - invalidated by EventObserver on changes
     * Supports Redis cache tags for efficient invalidation
     * 
     * @return Collection
     */
    public function getFeaturedEvents(): Collection
    {
        try {
            // Use cache tags if Redis is available, otherwise use simple cache
            $cache = $this->getCacheDriver();

            return $cache->rememberForever('events_featured', function () {
                $events = $this->eventRepository->getFeaturedEvents();

                if ($events->isEmpty()) {
                    return collect();
                }

                return $this->sortEventsByDateStatus($events);
            });
        } catch (\Exception $e) {
            // Fallback: Fetch directly from DB if cache fails
            $events = $this->eventRepository->getFeaturedEvents();

            if ($events->isEmpty()) {
                return collect();
            }

            return $this->sortEventsByDateStatus($events);
        }
    }

    /**
     * Get home page events with filters
     * Uses permanent cache - invalidated by EventObserver on changes
     * Supports Redis cache tags for efficient invalidation
     * 
     * @param array $filters
     * @return Collection
     */
    public function getHomePageEvents(array $filters = []): Collection
    {
        try {
            // Create unique cache key based on filters
            $cacheKey = 'events_home_' . md5(json_encode($filters));

            // Use cache tags if Redis is available
            $cache = $this->getCacheDriver();

            return $cache->rememberForever($cacheKey, function () use ($filters) {
                $events = $this->eventRepository->getHomePageEvents($filters);

                if ($events->isEmpty()) {
                    return collect();
                }

                $sortOrder = $filters['sort_order'] ?? 'desc';
                return $this->sortEventsByDateStatus($events, $sortOrder);
            });
        } catch (\Exception $e) {
            // Fallback: Fetch directly from DB if cache fails
            $events = $this->eventRepository->getHomePageEvents($filters);

            if ($events->isEmpty()) {
                return collect();
            }

            $sortOrder = $filters['sort_order'] ?? 'desc';
            return $this->sortEventsByDateStatus($events, $sortOrder);
        }
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
                return Cache::tags(['events']);
            }
        } catch (\Exception $e) {
            // Log the error if needed: \Log::error('Redis cache connection failed: ' . $e->getMessage());
            // Fallback to standard cache store (which might also be redis, but let's be safe)
            // or return a dummy cache store that doesn't cache but allows method calls
            return Cache::store('file');
        }

        return Cache::store();
    }

    /**
     * Sort events by date status (ongoing first, then future)
     * Optimized for large collections
     * 
     * @param Collection $events
     * @param string $sortOrder 'asc' or 'desc'
     * @return Collection
     */
    public function sortEventsByDateStatus(Collection $events, string $sortOrder = 'asc'): Collection
    {
        $today = Carbon::today();
        $ongoingEvents = collect();
        $futureEvents = collect();

        foreach ($events as $event) {
            $dates = array_map('trim', explode(',', $event->date_range));

            if (count($dates) === 1) {
                $startDate = Carbon::parse($dates[0]);
                $endDate = $startDate;
            } else {
                [$startDate, $endDate] = array_map('trim', $dates);
                $startDate = Carbon::parse($startDate);
                $endDate = Carbon::parse($endDate);
            }

            if ($today->between($startDate, $endDate)) {
                $ongoingEvents->push($event);
            } elseif ($today->lt($startDate)) {
                $futureEvents->push($event);
            }
        }

        // Sort by date based on order parameter
        if ($sortOrder === 'desc') {
            $ongoingEvents = $ongoingEvents->sortByDesc(fn($e) => Carbon::parse(explode(',', $e->date_range)[0]));
            $futureEvents = $futureEvents->sortByDesc(fn($e) => Carbon::parse(explode(',', $e->date_range)[0]));
        } else {
            $ongoingEvents = $ongoingEvents->sortBy(fn($e) => Carbon::parse(explode(',', $e->date_range)[0]));
            $futureEvents = $futureEvents->sortBy(fn($e) => Carbon::parse(explode(',', $e->date_range)[0]));
        }

        return $ongoingEvents->merge($futureEvents);
    }

    /**
     * Check if event is expired
     * 
     * @param string $dateRange
     * @return bool
     */
    public function isEventExpired(string $dateRange): bool
    {
        $today = Carbon::today();
        $dateRange = explode(',', $dateRange);
        $endDate = count($dateRange) === 2
            ? Carbon::parse($dateRange[1])
            : Carbon::parse($dateRange[0]);

        return $today->gt($endDate);
    }
}
