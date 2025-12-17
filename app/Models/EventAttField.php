<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventAttField extends Model
{
    use HasFactory, SoftDeletes;

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
