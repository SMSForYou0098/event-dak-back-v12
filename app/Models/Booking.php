<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class)->whereNull('deleted_at');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function userData()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function agentUser()
    {
        return $this->belongsTo(User::class, 'booking_by');
    }
    // public function agent()
    // {
    //     return $this->belongsTo(User::class, 'agent_id');
    // }
    public function promocode()
    {
        return $this->belongsTo(PromoCode::class);
    }
    public function attendee()
    {
        return $this->belongsTo(Attndy::class, 'attendee_id');
    }
    public function attendeess()
    {
        return $this->hasMany(Attndy::class, 'id', 'attendee_id');
    }
    public function paymentLog()
    {
        return $this->belongsTo(PaymentLog::class, 'session_id', 'session_id');
    }
    public function bookingTax()
    {
        return $this->hasOne(BookingTax::class, 'booking_id', 'id')
            ->where('type', $this->booking_type);
        // optional: you can filter by booking_type (online, agent, sponsor, corporate)
    }
    public function bookingsTax()
    {
        return $this->hasOne(BookingTax::class, 'booking_id', 'id');
    }

    public function LSection()
    {
        return $this->belongsTo(LSection::class, 'section_id', 'id');
    }

    /**
     * Scope to eager load common relationships for better performance
     */
    public function scopeWithRelations($query)
    {
        return $query->with([
            'ticket:id,event_id,name,price,background_image',
            'user:id,name,email,number',
            'attendee:id,name,email,number',
            'LSection:id,name',
            'bookingTax'
        ]);
    }

    public function getSectionIdAttribute($value)
    {
        // Convert "section_132" to 132
        if (is_string($value) && str_contains($value, 'section_')) {
            return (int) str_replace('section_', '', $value);
        }
        return $value;
    }
}
