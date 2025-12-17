<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\SuccessfulEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SuccessfulEventController extends Controller
{

    public function index(Request $request)
    {
        try {
            $type = $request->type;

            if (!empty($type)) {
                $eventData = SuccessfulEvent::where('type', $type)->get();
            } else {
                $eventData = SuccessfulEvent::all();
            }

            return response()->json([
                'status' => true,
                'message' => 'Event data fetched successfully',
                'eventData' => $eventData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch event data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function store(Request $request)
    {
        try {
            $event = new SuccessfulEvent();

            $eventDirectory = "system/SuccessfulEvent";

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                $eventDirectory = 'event_thumbnails';
                $fileName = 'get-your-ticket-' . uniqid() . '_' . $file->getClientOriginalName();
                $storedPath = $file->storeAs("uploads/$eventDirectory", $fileName, 'public');
                $event->thumbnail = Storage::disk('public')->url($storedPath);
            }

            $event->user_id = Auth()->id();
            $event->url = $request->url;
            $event->title = $request->title;
            $event->type = $request->type;

            $event->save();

            return response()->json([
                'status' => true,
                'message' => 'Event Updated Successfully',
                'event' => $event
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update event',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function show(string $id)
    {
        //
    }


    public function edit(string $id)
    {
        //
    }


    public function update(Request $request, $id)
    {
        try {
            // ✅ Find event by ID
            $event = SuccessfulEvent::find($id);

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event not found'
                ], 404);
            }

            // ✅ Update basic fields
            $event->url = $request->url ?? $event->url;
            $event->title = $request->title ?? $event->title;
            $event->type = $request->type ?? $event->type;

            // ✅ Handle thumbnail update
            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                $eventDirectory = 'event_thumbnails';
                $fileName = 'get-your-ticket-' . uniqid() . '_' . $file->getClientOriginalName();
                $storedPath = $file->storeAs("uploads/$eventDirectory", $fileName, 'public');
                $event->thumbnail = Storage::disk('public')->url($storedPath);
            }

            // ✅ Update user_id (who updated it)
            $event->user_id = Auth()->id();

            $event->save();

            return response()->json([
                'status' => true,
                'message' => 'Event updated successfully',
                'event' => $event
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update event',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy(string $id)
    {
        $SuccessfulEvent = SuccessfulEvent::where('id', $id)->firstOrFail();
        if (!$SuccessfulEvent) {
            return response()->json(['status' => false, 'message' => 'SuccessfulEvent not found'], 404);
        }

        $SuccessfulEvent->delete();
        return response()->json(['status' => true, 'message' => 'SuccessfulEvent deleted successfully'], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }

    public function getExpiredEvents(Request $request)
    {
        $today = Carbon::today();

        // Get all events (don't limit yet)
        $events = Event::with(['user:id,name', 'eventMedia:event_id,thumbnail', 'venue:id,city'])
            ->select('id', 'name', 'date_range', 'user_id', 'event_key')
            ->whereHas('eventControls', function ($q) {
                $q->where('status', 1);
            })
            ->orderBy('id', 'desc')
            ->get();

        $pastEvents = [];

        foreach ($events as $event) {
            $dateRange = explode(',', $event->date_range);
            $endDate = count($dateRange) > 1
                ? Carbon::parse(trim($dateRange[1]))
                : Carbon::parse(trim($dateRange[0]));

            if ($today->greaterThan($endDate)) {
                $pastEvents[] = [
                    'id'         => $event->id,
                    'name'       => $event->name,
                    'thumbnail'  => optional($event->eventMedia)->thumbnail,
                    'date_range' => $event->date_range,
                    'event_key'  => $event->event_key,
                    'city'       => optional($event->venue)->city,
                    'user'       => $event->user, // only id & name
                    'end_date'   => $endDate->toDateString(), // for sorting
                ];
            }
        }

        // Sort by end_date descending (latest expired first)
        usort($pastEvents, function ($a, $b) {
            return strtotime($b['end_date']) - strtotime($a['end_date']);
        });

        // Take only latest 6 expired events
        $pastEvents = array_slice($pastEvents, 0, 6);

        if (empty($pastEvents)) {
            return response()->json([
                'status'  => false,
                'message' => 'No past events found',
            ], 200);
        }

        return response()->json([
            'status' => true,
            'events' => $pastEvents,
        ], 200);
    }


    // public function getExpiredEvents(Request $request)
    // {
    //     $today = Carbon::today()->toDateString();

    //     // Eager load user relation with only id & name
    //     $events = Event::with(['user:id,name', 'eventMedia:event_id,thumbnail', 'venue:id,city'])
    //         ->select('id', 'name', 'date_range', 'user_id', 'event_key')
    //         ->whereHas('eventControls', function ($q) {
    //             $q->where('status', 1); 
    //         })
    //         ->orderBy('id', 'desc') // latest events
    //         ->limit(6)
    //         ->get();

    //     $pastEvents = [];

    //     foreach ($events as $event) {
    //         $dateRange = explode(',', $event->date_range);

    //         if (count($dateRange) == 1) {
    //             // Single-day event
    //             $eventDate = Carbon::parse(trim($dateRange[0]));
    //             $isPast = $today > $eventDate->toDateString();
    //         } else {
    //             // Multi-day event
    //             $endDate = Carbon::parse(trim($dateRange[1]));
    //             $isPast = $today > $endDate->toDateString();
    //         }

    //         if ($isPast) {
    //             $pastEvents[] = [
    //                 'id'         => $event->id,
    //                 'name'       => $event->name,
    //                 'thumbnail'  => $event->eventMedia->thumbnail,
    //                 'date_range' => $event->date_range,
    //                 'event_key'  => $event->event_key,
    //                 'city'       => $event->venue->city,
    //                 'user'       => $event->user, // only id and name
    //             ];
    //         }
    //     }

    //     if (empty($pastEvents)) {
    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'No past events found'
    //         ], 200);
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'events' => $pastEvents
    //     ], 200);
    // }
}
