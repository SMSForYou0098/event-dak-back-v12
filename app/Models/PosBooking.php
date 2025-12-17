<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosBooking extends Model
{
    use HasFactory, SoftDeletes;
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function ticketData()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function bookingTax()
    {
        return $this->hasOne(BookingTax::class, 'booking_id', 'id')
            ->where('type', 'pos');
    }
    public function bookingsTax()
    {
        return $this->hasOne(BookingTax::class, 'booking_id', 'id');
    }

    public function attendee()
    {
        return $this->belongsTo(Attndy::class, 'attendee_id', 'id');
    }

    public function eventSeatStatus()
    {
        return $this->hasMany(EventSeatStatus::class, 'booking_id', 'id')
            ->where('type', 'POS');
    }

    // add relation to get section name 


}
