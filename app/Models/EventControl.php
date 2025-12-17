<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventControl extends Model
{
    use HasFactory, SoftDeletes;

    protected $touches = ['event'];

    /**
     * Cast attributes to native types.
     * This ensures that tinyint(0/1) in DB are returned as boolean in API responses.
     */
    protected $casts = [
        'scan_detail' => 'boolean',
        'event_feature' => 'boolean',
        'house_full' => 'boolean',
        'online_att_sug' => 'boolean',
        'offline_att_sug' => 'boolean',
        'multi_scan' => 'boolean',
        'show_on_home' => 'boolean',
        'ticket_system' => 'boolean',
        'booking_by_seat' => 'boolean',
        'online_booking' => 'boolean',
        'agent_booking' => 'boolean',
        'pos_booking' => 'boolean',
        'complimentary_booking' => 'boolean',
        'exhibition_booking' => 'boolean',
        'sponsor_booking' => 'boolean',
        'overnight_event' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }
}
