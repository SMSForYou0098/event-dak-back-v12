<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'sale' => $this->sale,
            'sale_date' => $this->sale_date,
            'booking_not_open' => $this->booking_not_open,
            'sold_out' => $this->sold_out,
            'fast_filling' => $this->fast_filling,
            'status' => $this->status,

            // Conditionally include additional fields if loaded
            'allow_agent' => $this->when(isset($this->allow_agent), $this->allow_agent),
            'allow_pos' => $this->when(isset($this->allow_pos), $this->allow_pos),
        ];
    }
}
