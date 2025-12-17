<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Layout extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'event_id',
        'venue_id',
        'event_key',
        'name',
        'stage_config',
        'total_section',
        'total_row',
        'total_set',
        'meta_data',
    ];

    public function stage()
    {
        return $this->hasOne(LStage::class, 'layout_id');
    }

    public function sections()
    {
        return $this->hasMany(LSection::class, 'layout_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
    public function venue()
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }
}
