<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizerSignature extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'signatory_name',
        'signature_type',
        'signature_text',
        'signature_font',
        'signature_font_style',
        'signature_image',
        'signing_date',
    ];

    protected $casts = [
        'signing_date' => 'date',
    ];

    /**
     * Get the user that owns the signature.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
