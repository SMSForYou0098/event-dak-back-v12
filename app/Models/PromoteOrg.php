<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoteOrg extends Model
{
    use HasFactory, SoftDeletes;

    public function org()
    {
        return $this->belongsTo(User::class, 'org_id', 'id');
    }
}
