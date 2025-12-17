<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;

class Event extends Model
{
    use HasFactory, SoftDeletes, HasRoles;

    protected $fillable = [
        'user_id',
        'category',
        'name',
        'venue_id',
        'artist_id',
        'description',
        'insta_whts_url',
        'ticket_terms',
        'date_range',
        'entry_time',
        'start_time',
        'end_time',
        'event_type',
        'event_key',
        'short_url',
        'deleted_at'
    ];


    public static $contentFields = [
        'description',
        'insta_whts_url',
        'whts_note',
        'ticket_terms',
        'booking_notice',
    ];
    public function __call($method, $parameters)
    {
        // If method name matches a content field:
        if (in_array($method, self::$contentFields)) {
            return $this->belongsTo(ContentMaster::class, $method);
        }

        return parent::__call($method, $parameters);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function reportingUser()
    {
        return $this->belongsTo(User::class, 'reporting_user_id'); // or whatever the field is
    }


    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function CategoryData()
    {
        return $this->belongsTo(Category::class, 'id', 'category');
    }

    public function categoryDatanew()
    {
        return $this->belongsTo(Category::class, 'category', 'id');
    }


    public function Category()
    {
        return $this->belongsTo(Category::class, 'category');
    }

    public function organizer()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
    public function eventGate()
    {
        return $this->belongsTo(EventGate::class, 'event_id');
    }
    public function eventLayout()
    {
        return $this->belongsTo(CatLayout::class, 'event_key', 'category_id');
    }
    public function IDCardLayout()
    {
        return $this->hasOne(CatLayout::class, 'category_id', 'event_key');
    }

    // public function seatConfig()
    // {
    //     return $this->belongsTo(SeatConfig::class,'event_id','id');
    // }

    public function artists()
    {
        $ids = array_filter(explode(',', $this->artist_id ?? ''), fn($id) => $id !== '');

        if (empty($ids)) {
            return Artist::whereRaw('1 = 0'); // Returns empty result
        }

        return Artist::whereIn('id', $ids);
    }

    public function venueEvent()
    {
        return $this->belongsTo(Venue::class, 'user_id', 'org_id');
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class, 'venue_id', 'id');
    }

    public function taxData()
    {
        return $this->belongsTo(BookingTax::class, 'user_id', 'user_id');
    }

    public function eventControls()
    {
        return $this->hasOne(EventControl::class, 'event_id');
    }

    public function eventMedia()
    {
        return $this->hasOne(EventGallery::class, 'event_id');
    }

    public function eventMediaa()
    {
        return $this->hasMany(EventGallery::class, 'event_id');
    }

    public function eventSeo()
    {
        return $this->hasOne(SeoConfig::class, 'item_id');
    }

    public function layout()
    {
        return $this->hasMany(Layout::class, 'venue_id', 'venue_id');
    }

    /**
     * Scope to eager load common relationships for list views
     */
    public function scopeWithBasicRelations($query)
    {
        return $query->with([
            'tickets' => fn($q) => $q->where('status', 1)->select('id', 'event_id', 'name', 'price', 'sale_price', 'status'),
            'organizer:id,organisation,name',
            'venue:id,city,name',
            'eventMedia:id,event_id,thumbnail',
            'eventControls:id,event_id,event_feature,house_full'
        ]);
    }

    /**
     * Scope to eager load all relationships for detail views
     */
    public function scopeWithFullRelations($query)
    {
        return $query->with([
            'tickets',
            'organizer',
            'venue',
            'eventMedia',
            'eventControls',
            'Category',
            'eventGate',
            'eventSeo'
        ]);
    }

    public function EventHasLayout()
    {
        return $this->hasOne(EventHasLayout::class, 'event_id');
    }
}
