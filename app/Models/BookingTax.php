<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingTax extends Model
{
    use HasFactory,SoftDeletes;

     protected $fillable = [
        'user_id',
        'booking_id',
        'type',
        'base_amount',
        'central_gst',
        'state_gst',
        'total_tax',
        'convenience_fee',
        'final_amount',
        'amount',
        'total_base_amount',
    ];

   public function AllBooking()
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'id');
    }

    public function booking()
    {
        return $this->belongsTo(PosBooking::class, 'booking_id', 'id');
    }
}
