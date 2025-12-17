<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\SeoGeneratorService;
use Illuminate\Http\Request;

class AIDataGenerator extends Controller
{
    public function __construct(
        private SeoGeneratorService $seoGenerator
    ) {}

    /**
     * Generate SEO data for an event
     */
    public function generateSeo(Request $request, $eventKey)
    {
        $event = Event::where('event_key', $eventKey)
            ->with(['venue:id,address,city', 'user:id,organisation', 'Category:id,title'])
            ->select('id', 'name', 'description', 'date_range', 'venue_id', 'user_id', 'category')
            ->first();

        if (!$event) {
            return response()->json([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        $eventData = [
            'name' => $event->name,
            'description' => $event->description,
            'date' => $event->date_range,
            'location' => $event->venue?->address ?? '',
            'city' => $event->venue?->city ?? '',
            'organisation' => $event->user?->organisation ?? '',
            'category' => $event->Category?->title ?? '',
        ];

        return response()->json([
            'status' => true,
            'seo' => $this->seoGenerator->generateEventSeo($eventData)
        ]);
    }
}
