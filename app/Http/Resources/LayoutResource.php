<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LayoutResource extends JsonResource
{
    /**
     * Transform the layout into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'stage_config' => $this->stage_config,
            'event_id' => $this->event_id,
            'venue_id' => $this->venue_id,
            'total_section' => $this->total_section,
            'total_row' => $this->total_row,
            'total_seat' => $this->total_seat,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'venue' => $this->when(
                $this->relationLoaded('venue'),
                [
                    'id' => $this->venue->id ?? null,
                    'name' => $this->venue->name ?? null,
                ]
            ),
        ];
    }
}
