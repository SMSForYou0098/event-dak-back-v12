<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Lighter version for list/card views - only essential fields.
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

            // Essential related data
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

            // Event feature flags
            'event_feature' => $this->when(
                $this->relationLoaded('eventControls'),
                $this->eventControls->event_feature ?? 0
            ),
            'house_full' => $this->when(
                $this->relationLoaded('eventControls'),
                $this->eventControls->house_full ?? 0
            ),

            // Ticket summary (no full ticket array)
            'lowest_ticket_price' => $this->calculateLowestTicketPrice(),
            'lowest_sale_price' => $this->calculateLowestSalePrice(),
            'on_sale' => $this->isOnSale(),
            'booking_close' => $this->isBookingClosed(),
            'booking_not_start' => $this->hasBookingNotStarted(),
            'fast_filling' => $this->when(
                $this->relationLoaded('tickets'),
                $this->tickets->contains('fast_filling', 1)
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
        return $activeTickets->min('price');
    }

    /**
     * Calculate the lowest sale price for currently valid sale tickets
     */
    protected function calculateLowestSalePrice()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return 0;
        }

        $today = Carbon::today();
        $validSaleTickets = collect();

        foreach ($this->tickets as $ticket) {
            if ($ticket->sale == 1 && !empty($ticket->sale_date)) {
                $dates = array_map('trim', explode(',', $ticket->sale_date));

                if (count($dates) === 1) {
                    $startDate = Carbon::parse($dates[0])->startOfDay();
                    $endDate = Carbon::parse($dates[0])->endOfDay();
                } else {
                    $startDate = Carbon::parse($dates[0])->startOfDay();
                    $endDate = Carbon::parse($dates[1])->endOfDay();
                }

                // Check if today's date falls in sale range
                if (
                    $today->toDateString() == $startDate->toDateString() ||
                    ($today->greaterThanOrEqualTo($startDate) && $today->lessThanOrEqualTo($endDate))
                ) {
                    $validSaleTickets->push($ticket);
                }
            }
        }

        return $validSaleTickets->isNotEmpty() ? $validSaleTickets->min('sale_price') : 0;
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
