<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSeatStatus extends Model
{
    use HasFactory,SoftDeletes;
    protected $fillable = [
        'event_id','event_key',
        'seat_id','section_id','ticket_id',
        'booking_id',
        'status','seat_name','type'
    ];

    public function seat()
    {
        return $this->belongsTo(LSeat::class, 'seat_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

     public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function section(){
        return $this->belongsTo(LSection::class,'section_id');
    }
}
