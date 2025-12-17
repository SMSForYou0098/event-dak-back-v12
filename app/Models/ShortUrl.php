<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShortUrl extends Model
{
    use HasFactory,SoftDeletes;

    protected $fillable = [
        'long_url',
        'short_url',
        'event_key'
    ];
}
