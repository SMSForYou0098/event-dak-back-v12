<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    private const LIVE_KEYWORDS = ['live', 'liveevent', 'live-event', 'today'];
    private const OFFER_KEYWORDS = ['offer', 'offers', 'sale', 'sales'];
    private const FREE_KEYWORDS = ['free'];
    private const STOPWORDS = ['in', 'the', 'a', 'an', 'of', 'and', 'for', 'on', 'at'];

    public function search(Request $request)
    {
        $query = Event::query()
            ->select([
                'id',
                'name',
                'category',
                'event_type',
                'user_id',
                'event_key',
                'venue_id',
                'start_date',
                'end_date',
                'entry_time',
                'start_time',
                'end_time'
            ])
            ->with([
                'eventMedia:event_id,thumbnail',
                'venueEvent:org_id,city,state,address',
                'organizer:id,name,organisation',
                'categoryDatanew:id,title',
                'eventControls:id,event_id,status',
                'tickets:id,event_id,price,sale,sale_start_date,sale_end_date'
            ]);

        $this->applyFilters($query, $request);

        // ✅ Laravel 12.8+: Automatic Relationship Autoloading
        $events = $query->get()->withRelationshipAutoloading();

        return response()->json([
            'status' => true,
            'data'   => $events
        ]);
    }

    private function applyFilters($query, Request $request): void
    {
        $hasCategory = $request->filled('event_category');
        $hasSearch = $request->filled('search');

        if (!$hasCategory && !$hasSearch) {
            return;
        }

        $query->where(function ($q) use ($request, $hasCategory, $hasSearch) {
            if ($hasCategory) {
                $this->applyCategoryFilter($q, $request->event_category);
            }

            if ($hasSearch) {
                $this->applySearchFilter($q, $request->search);
            }
        });
    }

    private function applyCategoryFilter($query, string $categoryParam): void
    {
        $categoryNames = array_map('trim', explode(',', $categoryParam));
        $categoryIds = Category::whereIn('title', $categoryNames)->pluck('id');

        if ($categoryIds->isNotEmpty()) {
            $query->orWhereIn('category', $categoryIds);
        }
    }

    private function applySearchFilter($query, string $search): void
    {
        $keywords = array_filter(
            explode(' ', strtolower(trim($search))),
            fn($word) => $word !== '' && !in_array($word, self::STOPWORDS)
        );

        if (empty($keywords)) {
            return;
        }

        $query->orWhere(function ($sq) use ($keywords, $search) {
            foreach ($keywords as $word) {
                if ($this->applySpecialFilters($sq, $word)) {
                    continue;
                }
            }

            $this->applyTextSearch($sq, $search);
        });
    }

    private function applySpecialFilters($query, string $word): bool
    {
        if (in_array($word, self::LIVE_KEYWORDS)) {
            $this->applyLiveEventFilter($query);
            return true;
        }

        if (in_array($word, self::OFFER_KEYWORDS)) {
            $this->applyOfferFilter($query);
            return true;
        }

        if (in_array($word, self::FREE_KEYWORDS)) {
            $this->applyFreeEventFilter($query);
            return true;
        }

        return false;
    }

    /**
     * ✅ NORMALIZED: Clean date column queries
     * No more SPLIT_PART or CASE statements!
     */
    private function applyLiveEventFilter($query): void
    {
        $today = now()->toDateString();

        $query->orWhere(function ($liveQ) use ($today) {
            $liveQ->where('start_date', '<=', $today)
                ->where('end_date', '>=', $today)
                ->whereHas('eventControls', fn($q) => $q->where('status', '1'));
        });
    }

    /**
     * ✅ NORMALIZED: Clean offer filter
     */
    private function applyOfferFilter($query): void
    {
        $today = now()->toDateString();

        $query->orWhere(function ($offerQ) use ($today) {
            $offerQ->where('end_date', '>=', $today)
                ->whereHas('tickets', function ($ticketQ) use ($today) {
                    $ticketQ->where('sale', 1)
                        ->where('sale_end_date', '>=', $today);
                });
        });
    }

    /**
     * ✅ NORMALIZED: Clean free events filter
     */
    private function applyFreeEventFilter($query): void
    {
        $today = now()->toDateString();

        $query->orWhere(function ($freeQ) use ($today) {
            $freeQ->whereHas('eventControls', fn($q) => $q->where('status', 1))
                ->where('end_date', '>=', $today)
                ->whereHas('tickets', fn($q) => $q->where('price', 0));
        });
    }

    /**
     * ✅ Laravel 12: whereLike() for case-insensitive search
     * ✅ PostgreSQL: Full-text search with GIN index
     */
    private function applyTextSearch($query, string $search): void
    {
        $searchTerm = trim($search);

        // PostgreSQL Full-Text Search (requires GIN index for performance)
        $query->orWhereRaw(
            "to_tsvector('english', COALESCE(name, '') || ' ' || COALESCE(description, '')) 
             @@ websearch_to_tsquery('english', ?)",
            [$searchTerm]
        );

        // Laravel 12: whereLike() - automatically case-insensitive
        $query->orWhereLike('name', "%{$searchTerm}%");

        // Search in venue (with Laravel 12 whereLike)
        $query->orWhereHas('venueEvent', function ($venueQ) use ($searchTerm) {
            $venueQ->whereLike('city', "%{$searchTerm}%")
                ->orWhereLike('state', "%{$searchTerm}%")
                ->orWhereLike('address', "%{$searchTerm}%");
        });

        // Search by organizer
        $userIds = User::query()
            ->whereLike('name', "%{$searchTerm}%")
            ->orWhereLike('organisation', "%{$searchTerm}%")
            ->pluck('id');

        if ($userIds->isNotEmpty()) {
            $query->orWhereIn('user_id', $userIds);
        }
    }
}
