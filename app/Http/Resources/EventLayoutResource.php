<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLayoutResource extends JsonResource
{
    /**
     * Transform the event layout seat assignment into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'seatId' => $this['seatId'],
            'sectionId' => $this['sectionId'],
            'ticketId' => $this['ticketId'],
            'status' => $this['status'],
        ];
    }
}
