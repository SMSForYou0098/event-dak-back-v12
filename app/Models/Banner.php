<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Banner extends Model
{
    use HasFactory, SoftDeletes;

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category'); // 'category' is the foreign key in banners table
    }
}
