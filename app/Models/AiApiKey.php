<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiApiKey extends Model
{
    protected $fillable = ['model', 'apikey', 'status'];

    protected $casts = [
        'status' => 'boolean',
    ];
}
