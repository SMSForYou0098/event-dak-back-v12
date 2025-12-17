<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CardBooking extends Model
{
    use HasFactory,SoftDeletes;
     protected $fillable = [
        'event_id',      // 👈 add this line
        'token',
        'ticket_id',
        'status',
        'booking_type',
        'booking_id',
       
        // add other columns that you use for create/update
    ];
}
