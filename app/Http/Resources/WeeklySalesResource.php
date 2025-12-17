<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeeklySalesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'] ?? 'Sales',
            'data' => $this->resource['data'] ?? [],
            'type' => $this->resource['type'] ?? 'line', // chart type
        ];
    }
}
