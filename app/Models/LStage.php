<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LStage extends Model
{
    use HasFactory,SoftDeletes;
     protected $fillable = [
        'name',
        'layout_id',
        'position',
        'shape',
        'height',
        'width',
        'status',
        'meta_data',
        'x',
        'y'
    ];

     public function layout()
    {
        return $this->belongsTo(Layout::class, 'layout_id');
    }
}
