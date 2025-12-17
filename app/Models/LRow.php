<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LRow extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'section_id',
        'label',
        'seats',
        'is_blocked',
        'row_shape',
        'curve_amount',
        'spacing',
        'ticket_id',
        'display_order',
        'meta_data'
    ];

    public function section()
    {
        return $this->belongsTo(LSection::class, 'section_id');
    }
    public function seatList()
    {
        return $this->hasMany(LSeat::class, 'row_id');
    }
    public function seats()
    {
        return $this->hasMany(LSeat::class, 'row_id');
    }
}
