<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'total_amount' => $this->resource['total_amount'] ?? 0,
            'total_discount' => $this->resource['total_discount'] ?? 0,
            'total_bookings' => $this->resource['total_bookings'] ?? 0,
            'total_tickets' => $this->resource['total_tickets'] ?? 0,

            // Gateway breakdown (for online bookings)
            'gateway_breakdown' => $this->when(
                isset($this->resource['gateway_breakdown']),
                $this->resource['gateway_breakdown'] ?? []
            ),

            // Payment method breakdown
            'payment_methods' => [
                'cash' => $this->resource['cash'] ?? 0,
                'upi' => $this->resource['upi'] ?? 0,
                'net_banking' => $this->resource['net_banking'] ?? 0,
                'card' => $this->resource['card'] ?? 0,
            ],

            // Weekly data
            'weekly_sales' => $this->when(
                isset($this->resource['weekly_sales']),
                WeeklySalesResource::collection($this->resource['weekly_sales'] ?? [])
            ),

            // Convenience fees
            'convenience_fees' => [
                'total' => $this->resource['total_convenience_fee'] ?? 0,
                'weekly' => $this->resource['weekly_convenience_fees'] ?? [],
            ],

            // Scan history
            'scan_history' => $this->when(
                isset($this->resource['scan_history']),
                $this->resource['scan_history'] ?? []
            ),

            // Booking counts by type

            'booking_counts' => $this->when(
                isset($this->resource['booking_counts']),
                $this->resource['booking_counts'] ?? []
            ),
        ];
    }
}
