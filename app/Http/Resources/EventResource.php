<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * This is the base event resource with all computation logic.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'name' => $this->name,
            'category' => $this->category,
            'date_range' => $this->date_range,
            'venue_id' => $this->venue_id,
            'user_id' => $this->user_id,

            // Related data (only if loaded)
            'organisation' => $this->when(
                $this->relationLoaded('organizer'),
                $this->organizer->organisation ?? null
            ),
            'city' => $this->when(
                $this->relationLoaded('venue'),
                $this->venue->city ?? null
            ),
            'thumbnail' => $this->when(
                $this->relationLoaded('eventMedia'),
                $this->eventMedia->thumbnail ?? null
            ),

            // Event controls (only if loaded)
            'event_feature' => $this->when(
                $this->relationLoaded('eventControls'),
                $this->eventControls->event_feature ?? 0
            ),
            'house_full' => $this->when(
                $this->relationLoaded('eventControls'),
                $this->eventControls->house_full ?? 0
            ),

            // Computed ticket fields
            'lowest_ticket_price' => $this->calculateLowestTicketPrice(),
            'lowest_sale_price' => $this->calculateLowestSalePrice(),
            'on_sale' => $this->isOnSale(),
            'booking_close' => $this->isBookingClosed(),
            'booking_not_start' => $this->hasBookingNotStarted(),
            'fast_filling' => $this->when(
                $this->relationLoaded('tickets'),
                $this->tickets->contains('fast_filling', 1)
            ),

            // Conditionally include full tickets array if requested
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),

            // Event status for internal use
            'event_status' => $this->when(
                isset($this->event_status),
                $this->event_status
            ),

            // Additional fields that might be set
            'ticket_close' => $this->when(
                isset($this->ticket_close),
                $this->ticket_close
            ),
        ];
    }

    /**
     * Calculate the lowest ticket price from active tickets
     */
    protected function calculateLowestTicketPrice()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return null;
        }

        $activeTickets = $this->tickets->where('status', 1);
        $minPrice = $activeTickets->min('price');

        return $minPrice;
    }

    /**
     * Calculate the lowest sale price for currently valid sale tickets
     * Cached to avoid repeated date parsing
     */
    protected function calculateLowestSalePrice()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return 0;
        }

        // Cache key based on event and today's date
        $cacheKey = "event_{$this->id}_lowest_sale_price_" . now()->toDateString();

        return Cache::remember($cacheKey, 3600, function () {
            $today = Carbon::today();

            return $this->tickets
                ->filter(function ($ticket) use ($today) {
                    if ($ticket->sale != 1 || empty($ticket->sale_date)) {
                        return false;
                    }

                    $dates = explode(',', $ticket->sale_date);
                    $startDate = Carbon::parse(trim($dates[0]))->startOfDay();
                    $endDate = isset($dates[1])
                        ? Carbon::parse(trim($dates[1]))->endOfDay()
                        : $startDate->copy()->endOfDay();

                    return $today->between($startDate, $endDate);
                })
                ->min('sale_price') ?? 0;
        });
    }

    /**
     * Check if any tickets are currently on sale
     */
    protected function isOnSale()
    {
        return $this->calculateLowestSalePrice() > 0;
    }

    /**
     * Check if all tickets are sold out
     */
    protected function isBookingClosed()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return false;
        }

        return $this->tickets->every(fn($ticket) => $ticket->sold_out === 1);
    }

    /**
     * Check if booking has not started for all tickets
     */
    protected function hasBookingNotStarted()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return false;
        }

        return $this->tickets->every(fn($ticket) => $ticket->booking_not_open === 1);
    }
}
