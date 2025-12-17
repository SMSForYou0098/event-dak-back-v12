<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LSeat extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'row_id',
        'section_id',
        'seat_no',
        'status',
        'is_booked',
        'price',
        'label',
        'position',
        'seat_reading',
        'seat_icon',
        'ticket_id',
        'meta_data','type'
    ];


    public function row()
    {
        return $this->belongsTo(LRow::class, 'row_id');
    }
    public function section()
    {
        return $this->belongsTo(LSection::class, 'section_id');
    }

    public function eventSeatStatus()
    {
        return $this->hasOne(EventSeatStatus::class, 'seat_id');
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
