<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class EventRepository
{
    /**
     * Get filtered events query with eager loading
     * Optimized for high-traffic scenarios
     * 
     * @param array $filters
     * @return Builder
     */
    public function getFilteredEventsQuery(array $filters = []): Builder
    {
        // Extract filters
        $controls = $filters['controls'] ?? ['status' => "1"];
        $category = $filters['category'] ?? null;
        $bookingType = $filters['booking_type'] ?? null;
        $userId = $filters['user_id'] ?? null;
        $city = $filters['city'] ?? null;

        // Base query with optimized eager loading
        $query = Event::query();

        // Filter by user_id if provided
        if ($userId) {
            $query->where('user_id', $userId);
        }

        // Filter by event controls
        $query->whereHas('eventControls', function ($q) use ($controls) {
            foreach ($controls as $key => $value) {
                $q->where($key, $value);
            }
        });

        // Filter by organizer
        $query->whereHas('organizer', function ($q) {
            $q->whereNotNull('organisation')
                ->where('organisation', '!=', '');
        });

        // Filter by city via venue relationship
        if ($city) {
            $query->whereHas('venue', function ($q) use ($city) {
                $q->where('city', $city);
            });
        }

        // Eager load with selective columns to reduce memory footprint
        $query->with([
            'tickets' => function ($query) {
                $query->select(
                    'id',
                    'event_id',
                    'price',
                    'sale_price',
                    'sale',
                    'sale_date',
                    'booking_not_open',
                    'sold_out',
                    'fast_filling',
                    'status'
                );
            },
            'venue:id,city',
            'eventMedia:id,event_id,thumbnail',
            'organizer:id,organisation',
            'eventControls:id,event_id,event_feature,status,show_on_home,house_full',
        ])
            ->select('id', 'event_key', 'name', 'category', 'date_range', 'user_id', 'venue_id');

        // Apply category filter
        if ($category) {
            $categoryId = $this->getCategoryIdByTitle($category);
            if ($categoryId) {
                $query->where('events.category', $categoryId);
            } else {
                // Return empty result set if category not found
                return Event::whereRaw('1 = 0');
            }
        }

        // Apply booking type filter
        if ($bookingType) {
            $this->applyBookingTypeFilter($query, $bookingType);
        }

        return $query;
    }

    /**
     * Get category ID by title with permanent caching
     * Cache never expires - invalidated when categories change
     * 
     * @param string $categoryTitle
     * @return int|null
     */
    protected function getCategoryIdByTitle(string $categoryTitle): ?int
    {
        return Cache::rememberForever(
            "category_id_{$categoryTitle}",
            fn() => Category::where('title', $categoryTitle)->value('id')
        );
    }

    /**
     * Apply booking type filter to query
     * 
     * @param Builder $query
     * @param string $bookingType
     * @return void
     */
    protected function applyBookingTypeFilter(Builder $query, string $bookingType): void
    {
        $bookingTypeFields = [
            'online' => 'online_booking',
            'agent' => 'agent_booking',
            'sponsor' => 'sponsor_booking',
            'pos' => 'pos_booking',
            'complimentary' => 'complimentary_booking',
            'exhibition' => 'exhibition_booking',
            'amusement' => 'amusement_booking',
        ];

        if (isset($bookingTypeFields[$bookingType])) {
            $query->where($bookingTypeFields[$bookingType], 1);
        }
    }

    /**
     * Get featured events
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getFeaturedEvents()
    {
        return $this->getFilteredEventsQuery([
            'controls' => ['status' => "1", 'event_feature' => true]
        ])->get();
    }

    /**
     * Get home page events
     * 
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getHomePageEvents(array $filters = [])
    {
        $filters['controls'] = ['status' => "1", 'show_on_home' => 1];
        return $this->getFilteredEventsQuery($filters)->get();
    }

    /**
     * Find event by key with relationships
     * 
     * @param string $eventKey
     * @param array $relations
     * @return Event|null
     */
    public function findByKey(string $eventKey, array $relations = [])
    {
        return Event::with($relations)
            ->where('event_key', $eventKey)
            ->first();
    }
}
