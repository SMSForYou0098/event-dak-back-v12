<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventHasLayout extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'event_id',
        'event_key',
        'layout_id',
    ];

    protected $touches = ['event'];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
    public function layout()
    {
        return $this->belongsTo(Layout::class, 'layout_id');
    }
}
