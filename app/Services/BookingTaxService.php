<?php

namespace App\Services;

use App\Models\BookingTax;

class BookingTaxService
{
    /**
     * Create a new BookingTax record.
     *
     * @param int $bookingId
     * @param string $type
     * @param array $ticketData
     * @param float $discount
     * @return BookingTax
     */
    public function createBookingTax(int $bookingId, string $type, array $ticketData, float $discount = 0): BookingTax
    {
        return BookingTax::create([
            'booking_id' => $bookingId,
            'type' => $type,
            'base_amount' => $ticketData['baseAmount'] ?? 0,
            'discount' => $discount,
            'central_gst' => $ticketData['centralGST'] ?? 0,
            'state_gst' => $ticketData['stateGST'] ?? 0,
            'total_tax' => $ticketData['totalTax'] ?? 0,
            'convenience_fee' => $ticketData['convenienceFee'] ?? 0,
            'final_amount' => $ticketData['finalAmount'] ?? 0,
            'total_final_amount' => $ticketData['totalFinalAmount'] ?? 0,
            'total_base_amount' => $ticketData['totalBaseAmount'] ?? 0,
            'total_central_GST' => $ticketData['totalCentralGST'] ?? 0,
            'total_state_GST' => $ticketData['totalStateGST'] ?? 0,
            'total_tax_total' => $ticketData['totalTaxTotal'] ?? 0,
            'total_convenience_fee' => $ticketData['totalConvenienceFee'] ?? 0,
        ]);
    }
}
