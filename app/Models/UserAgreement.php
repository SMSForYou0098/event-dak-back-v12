<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAgreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agreement_id',
        'content',
        'status',
        'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    /**
     * Get the user that owns this agreement
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the agreement template
     */
    public function agreement()
    {
        return $this->belongsTo(Agreement::class);
    }

    /**
     * Generate the agreement URL
     */
    public function getAgreementUrlAttribute()
    {
        $baseUrl = rtrim(env('ALLOWED_DOMAIN', 'http://192.168.0.145:3000'), '/');
        return $baseUrl . '/agreement/' . $this->id;
    }
}
