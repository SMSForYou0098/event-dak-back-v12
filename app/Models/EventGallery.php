<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventGallery extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = ['images', 'thumbnail', 'insta_thumbnail', 'layout_image', 'insta_url', 'youtube_url'];
    protected $casts = ['image' => 'array'];


    protected $touches = ['event'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
