<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class EventDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Complete event details for single event view.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Check if tickets should be included based on query parameter
        $includeTickets = $request->query('tickets') === 'true';

        return [
            'id' => $this->id,
            'event_key' => $this->event_key,
            'name' => $this->name,
            'category' => $this->category,
            'date_range' => $this->date_range,
            'venue_id' => $this->venue_id,
            'user_id' => $this->user_id,
            'description' => $this->description,
            'insta_whts_url' => $this->insta_whts_url,
            'ticket_terms' => $this->ticket_terms,
            'entry_time' => $this->entry_time,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'event_type' => $this->event_type,
            'whts_note' => $this->whts_note,
            'booking_notice' => $this->booking_notice,

            // Related data
            'Category' => $this->when(
                $this->relationLoaded('Category') && $this->Category,
                fn() => ['id' => $this->Category->id, 'title' => $this->Category->title]
            ),

            'venue' => $this->when(
                $this->relationLoaded('venue') && $this->venue,
                fn() => [
                    'id' => $this->venue->id,
                    'name' => $this->venue->name,
                    'address' => $this->venue->address,
                    'city' => $this->venue->city,
                    'state' => $this->venue->state,
                    'map_url' => $this->venue->map_url,
                    'aembeded_code' => $this->venue->aembeded_code,
                ]
            ),

            'eventMedia' => $this->when(
                $this->relationLoaded('eventMedia') && $this->eventMedia,
                fn() => [
                    'thumbnail' => $this->eventMedia->thumbnail,
                    'insta_thumbnail' => $this->eventMedia->insta_thumbnail,
                    'insta_url' => $this->eventMedia->insta_url,
                    'layout_image' => $this->eventMedia->layout_image,
                    'youtube_url' => $this->eventMedia->youtube_url,
                ]
            ),

            'eventControls' => $this->when(
                $this->relationLoaded('eventControls') && $this->eventControls,
                fn() => [
                    'agent_booking' => $this->eventControls->agent_booking,
                    'booking_by_seat' => $this->eventControls->booking_by_seat,
                    'house_full' => $this->eventControls->house_full,
                    'status' => $this->eventControls->status,
                    'ticket_system' => $this->eventControls->ticket_system,
                    'overnight_event' => $this->eventControls->overnight_event,
                ]
            ),

            'eventSeo' => $this->when(
                $this->relationLoaded('eventSeo') && $this->eventSeo,
                fn() => [
                    'category_name' => $this->eventSeo->category_name,
                    'meta_description' => $this->eventSeo->meta_description,
                    'meta_keyword' => $this->eventSeo->meta_keyword,
                    'meta_title' => $this->eventSeo->meta_title,
                    'meta_tag' => $this->eventSeo->meta_tag,
                ]
            ),

            'taxData' => $this->when(
                $this->relationLoaded('taxData') && $this->taxData,
                fn() => [
                    'id' => $this->taxData->id,
                    'user_id' => $this->taxData->user_id,
                    'convenience_fee' => $this->taxData->convenience_fee,
                    'type' => $this->taxData->type,
                ]
            ),

            'EventHasLayout' => $this->when(
                $this->relationLoaded('EventHasLayout') && $this->EventHasLayout,
                fn() => [
                    'id' => $this->EventHasLayout->id,
                    'event_id' => $this->EventHasLayout->event_id,
                    'layout_id' => $this->EventHasLayout->layout_id,
                ]
            ),

            'artists_list' => $this->when(
                isset($this->artists_list),
                $this->artists_list
            ),

            // Computed ticket fields
            'lowest_ticket_price' => $this->calculateLowestTicketPrice(),
            'lowest_sale_price' => $this->calculateLowestSalePrice(),
            'on_sale' => $this->isOnSale(),
            'booking_close' => $this->isBookingClosed(),
            'booking_not_start' => $this->hasBookingNotStarted(),

            // Include tickets if requested
            'tickets' => $this->when(
                $includeTickets && $this->relationLoaded('tickets'),
                TicketResource::collection($this->tickets)
            ),

            // Layout ID (if set)
            'layout_id' => $this->when(
                isset($this->layout_id),
                $this->layout_id
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

        return $this->tickets->every(
            fn($ticket) => $ticket->sold_out === true || $ticket->sold_out === 'true' || $ticket->sold_out === 1
        );
    }

    /**
     * Check if booking has not started for all tickets
     */
    protected function hasBookingNotStarted()
    {
        if (!$this->relationLoaded('tickets') || $this->tickets->isEmpty()) {
            return false;
        }

        return $this->tickets->every(
            fn($ticket) => $ticket->donation === true || $ticket->donation === 'true' || $ticket->donation === 1
        );
    }
}
