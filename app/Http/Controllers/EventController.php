<?php

namespace App\Http\Controllers;

use App\Exports\EventExport;
use App\Models\AgentEvent;
use App\Models\Artist;
use App\Models\Booking;
use App\Models\Category;
use App\Models\CatLayout;
use App\Models\ComplimentaryBookings;
use App\Models\Event;
use App\Models\EventControl;
use App\Models\EventGallery;
use App\Models\MasterBooking;
use App\Models\PosBooking;
use App\Models\SeoConfig;
use App\Models\User;
use App\Services\EventKeyGeneratorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\ShortUrl;
use App\Models\Ticket;
// use Storage;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\EventCardResource;
use App\Http\Resources\EventDetailResource;
use App\Repositories\EventRepository;
use App\Services\EventService;

class EventController extends Controller
{
    protected $keyGenerator;
    protected $eventRepository;
    protected $eventService;

    public function __construct(
        EventKeyGeneratorService $keyGenerator,
        EventRepository $eventRepository,
        EventService $eventService
    ) {
        $this->keyGenerator = $keyGenerator;
        $this->eventRepository = $eventRepository;
        $this->eventService = $eventService;
    }


    /**
     * Get featured events
     * Optimized for high-traffic scenarios with caching
     */
    public function FeatureEvent()
    {
        $events = $this->eventService->getFeaturedEvents();

        if ($events->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No featured events found'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'events' => EventCardResource::collection($events)
        ], 200);
    }

    /**
     * Get home page events with filters
     * Optimized for high-traffic scenarios with caching
     */
    public function index(Request $request)
    {
        $filters = [
            'category' => $request->category,
            'booking_type' => $request->type,
            'sort_order' => 'desc'
        ];

        $events = $this->eventService->getHomePageEvents($filters);

        if ($events->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No events found with status 1'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'events' => EventCardResource::collection($events)
        ], 200);
    }


    public function dayWiseEvents($day)
    {
        $targetDate = ($day === 'tomorrow')
            ? now()->addDay()->format('Y-m-d')
            : now()->format('Y-m-d');

        // Fetch events where targetDate falls between date_range start and end
        $events = Event::whereRaw("
        STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', 1), '%Y-%m-%d') <= ?
        AND STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', -1), '%Y-%m-%d') >= ?
    ", [$targetDate, $targetDate])->get();

        return response()->json([
            'status' => true,
            'date' => $targetDate,
            'data' => $events,
        ], 200);
    }

    public function junk()
    {
        $today = Carbon::today()->toDateString();
        $events = Event::onlyTrashed()->where('status', 1)
            ->where(function ($query) use ($today) {
                // Check for single-day events or multi-day events
                $query->where(function ($subQuery) use ($today) {
                    // Single-day events
                    $subQuery->whereRaw('? = DATE_FORMAT(SUBSTRING_INDEX(date_range, ",", 1), "%Y-%m-%d")', [$today])
                        ->orWhereRaw('? < DATE_FORMAT(SUBSTRING_INDEX(date_range, ",", 1), "%Y-%m-%d")', [$today]);
                })
                    ->orWhere(function ($subQuery) use ($today) {
                        // Multi-day events
                        $subQuery->whereRaw('? <= DATE_FORMAT(SUBSTRING_INDEX(date_range, ",", -1), "%Y-%m-%d")', [$today]);
                    });
            })
            ->get();
        foreach ($events as $event) {
            // Get the minimum ticket price for the event
            $event->lowest_ticket_price = $event->tickets->min('price');
            $event->lowest_sale_price = $event->tickets->min('sale_price');
        }
        return response()->json(['status' => true, 'events' => $events], 200);
    }

    public function eventList(Request $request, $id)
    {
        $loggedInUser = Auth::user()->load('reportingUser');
        $today = Carbon::today()->toDateString();

        // 📄 Pagination & search parameters
        $perPage = min($request->input('per_page', 10), 100);
        $page = (int) $request->input('page', 1);
        $search = trim($request->input('search', ''));

        if ($loggedInUser->hasRole('Admin')) {
            $eventsQuery = Event::query();
        } else {
            $reporting_user = $loggedInUser->reportingUser;

            if ($reporting_user) {
                // reporting_user છે
                $reporting_userAdmin = $reporting_user->roles->pluck('name')->first();

                if ($reporting_userAdmin == 'Admin' || $reporting_userAdmin == 'Organizer') {
                    $eventsQuery = Event::where('user_id', $loggedInUser->id);
                } else {
                    $eventsQuery = Event::where('user_id', $loggedInUser->id)
                        ->orWhere('user_id', $reporting_user->id);
                }
            } else {
                // Organizer કે જેનો reporting_user નથી
                $eventsQuery = Event::where('user_id', $loggedInUser->id);
            }
        }

        $events = $eventsQuery
            ->select('id', 'user_id', 'category', 'name', 'date_range', 'created_at', 'event_type', 'event_key', 'venue_id')
            ->with([
                'tickets:id,event_id,price,sale_price',
                'user:id,name,organisation',
                'Category:id,title',
                'venue:id,city',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // Process events
        foreach ($events as $event) {
            $dateRange = explode(',', $event->date_range);

            if (count($dateRange) == 1) {
                $eventDate = Carbon::parse(trim($dateRange[0]));

                if ($today == $eventDate->toDateString()) {
                    $event->event_status = 1; // Ongoing
                } elseif ($today < $eventDate->toDateString()) {
                    $event->event_status = 2; // Upcoming
                } else {
                    $event->event_status = 3; // Past
                }
            } else {
                $startDate = Carbon::parse(trim($dateRange[0]));
                $endDate = Carbon::parse(trim($dateRange[1]));

                if ($today >= $startDate->toDateString() && $today <= $endDate->toDateString()) {
                    $event->event_status = 1; // Ongoing
                } elseif ($today < $startDate->toDateString()) {
                    $event->event_status = 2; // Upcoming
                } else {
                    $event->event_status = 3; // Past
                }
            }

            // Get the lowest ticket price
            $event->lowest_ticket_price = $event->tickets->min('price') ?? 0;
            $event->lowest_sale_price = $event->tickets->min('sale_price') ?? 0;
        }

        // 🔍 In-memory search over computed events (keeps original query logic intact)
        $filtered = $events;
        if ($search !== '') {
            $needle = mb_strtolower($search);

            $filtered = $events->filter(function ($event) use ($needle) {
                $haystack = [
                    $event->name,
                    $event->event_type,
                    $event->event_key,
                    optional($event->Category)->title,
                    optional($event->user)->name,
                    optional($event->user)->organisation,
                    optional($event->venue)->city,
                ];

                foreach ($haystack as $value) {
                    if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        // 📊 Pagination over in-memory collection
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $paginated = $filtered->slice($offset, $perPage)->values();

        return response()->json([
            'status' => true,
            'events' => $paginated,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ], 200);
    }

    public function eventByUser(Request $request, $id)
    {
        $bookingType = $request->type;
        $loggedInUser = Auth::user();
        $today = Carbon::today()->toDateString();

        $bookingField = match ($bookingType) {
            'online' => 'online_booking',
            'agent' => 'agent_booking',
            'sponsor' => 'sponsor_booking',
            'pos' => 'pos_booking',
            'complimentary' => 'complimentary_booking',
            'exhibition' => 'exhibition_booking',
            'amusement' => 'amusement_booking',
            default => null
        };

        // Common relations for all queries
        $relations = [
            'tickets',
            'user',
            'eventMedia:thumbnail,event_id',
            'taxData:id,user_id,convenience_fee,type',
            'EventHasLayout',
            'eventControls:event_id,ticket_system,status'
        ];

        // Build base query based on user role
        $eventsQuery = $this->getBaseQueryByRole($loggedInUser, $id, $relations);

        // Return early if no query (agent with no events)
        if (is_null($eventsQuery)) {
            return response()->json(['status' => false, 'message' => 'No events found for this agent'], 200);
        }

        // Apply common filters
        $this->applyEventFilters($eventsQuery, $bookingField, $today);

        // Get events
        $events = $eventsQuery->get();

        // Process events
        $isAdmin = $loggedInUser->hasRole('Admin');
        $isAgent = $loggedInUser->hasRole('Agent');
        $isPos = $loggedInUser->hasRole('Pos');

        $events->each(function ($event) use ($today, $isAdmin, $isAgent, $isPos) {
            $this->processEvent($event, $today, $isAdmin, $isAgent, $isPos);
        });

        return response()->json(['status' => true, 'events' => $events->values()], 200);
    }

    /**
     * Get base query based on user role
     */
    private function getBaseQueryByRole($user, $id, array $relations)
    {
        $restrictedRoles = ['Agent', 'Sponsor', 'Accreditation', 'Scanner'];

        if ($user->hasAnyRole($restrictedRoles)) {
            $agentEvent = AgentEvent::where('user_id', $user->id)->first();

            if (!$agentEvent || !$agentEvent->event_id) {
                return null;
            }

            $eventIds = json_decode($agentEvent->event_id, true);
            return Event::whereIn('id', $eventIds)->with($relations);
        }

        if ($user->hasRole('Organizer')) {
            return Event::where('user_id', $user->id)->with($relations);
        }

        if ($user->hasRole('Admin')) {
            return Event::with($relations);
        }

        // Default: fetch by user_id or reporting_user
        return Event::where(function ($q) use ($user, $id) {
            $q->where('user_id', $id)
                ->orWhere('user_id', $user->reporting_user);
        })->with($relations);
    }

    /**
     * Apply common filters to query
     */
    private function applyEventFilters($query, $bookingField, $today)
    {
        // Filter by event_controls status = 1
        $query->whereHas('eventControls', fn($q) => $q->where('status', 1));

        // Filter by date_range containing today (PostgreSQL compatible)
        $query->where(function ($q) use ($today) {
            $q->whereRaw("
            CASE 
                WHEN date_range LIKE '%,%' THEN 
                    ?::date BETWEEN SPLIT_PART(date_range, ',', 1)::date AND SPLIT_PART(date_range, ',', 2)::date
                ELSE 
                    date_range::date = ?::date
            END
        ", [$today, $today]);
        });

        // Filter by booking type if provided
        if ($bookingField) {
            $query->where($bookingField, 1);
        }

        $query->orderByDesc('id');
    }

    /**
     * Process individual event
     */
    private function processEvent($event, $today, $isAdmin, $isAgent, $isPos)
    {
        // Filter tickets
        $filteredTickets = $event->tickets->filter(function ($ticket) use ($isAdmin, $isAgent, $isPos) {
            $status = (int) $ticket->status;
            $allowAgent = (int) $ticket->allow_agent;
            $allowPos = (int) $ticket->allow_pos;

            // Admin sees all, status 1 visible to all
            if ($isAdmin || $status === 1) {
                return true;
            }

            // Hidden tickets with agent/pos permissions
            if ($status === 0) {
                return ($allowAgent === 1 && $isAgent) || ($allowPos === 1 && $isPos);
            }

            return false;
        })->values();

        $event->setRelation('tickets', $filteredTickets);
        $event->event_status = $this->calculateEventStatus($event->date_range, $today);
        $event->lowest_ticket_price = $filteredTickets->min('price') ?? 0;
        $event->lowest_sale_price = $filteredTickets->min('sale_price') ?? 0;
        $event->layout_id = $event->EventHasLayout->layout_id ?? null;
    }

    private function calculateEventStatus($dateRangeString, $today)
    {
        $dateRange = explode(',', $dateRangeString);

        if (count($dateRange) === 1) {
            $eventDate = Carbon::parse(trim($dateRange[0]));
            if ($today == $eventDate->toDateString())
                return 1; // Ongoing
            elseif ($today < $eventDate->toDateString())
                return 2; // Upcoming
            else
                return 3; // Past
        }

        if (count($dateRange) === 2) {
            $startDate = Carbon::parse(trim($dateRange[0]));
            $endDate = Carbon::parse(trim($dateRange[1]));
            if ($today >= $startDate->toDateString() && $today <= $endDate->toDateString())
                return 1; // Ongoing
            elseif ($today < $startDate->toDateString())
                return 2; // Upcoming
            else
                return 3; // Past
        }

        return 3; // Default to past if parsing fails
    }

    public function info($id)
    {
        try {
            // Assuming the user is authenticated and you have access to the user object
            $user = Auth::user();
            $isAdmin = $user->hasRole('Admin');
            $isScanner = $user->hasRole('Scanner');

            // Get the current date
            $currentDate = Carbon::today()->toDateString();

            // Fetch all active events based on user role and event date
            $events = Event::with(['tickets.bookings', 'tickets.agentBooking', 'tickets.posBookings'])
                ->where(function ($query) use ($user, $currentDate, $isAdmin, $isScanner) {
                    if ($isAdmin) {
                        // Admins see all events that start today
                        $query->whereRaw('SUBSTRING_INDEX(date_range, ",", 1) = ?', [$currentDate]);
                    } else if ($isScanner) {
                        // Scanners see events assigned to their reporting user that start today
                        $query->where('user_id', $user->reporting_user);
                        // ->whereRaw('SUBSTRING_INDEX(date_range, ",", 1) = ?', [$currentDate]);
                        $query->where('date_range', 'LIKE', "%$currentDate%")
                            ->orWhereRaw("? BETWEEN SUBSTRING_INDEX(date_range, ',', 1) AND SUBSTRING_INDEX(date_range, ',', -1)", [$currentDate]);
                    } else {
                        // Non-admin, non-scanner users see their own events that start today
                        $query->where('user_id', $user->id)
                            ->whereRaw('SUBSTRING_INDEX(date_range, ",", 1) = ?', [$currentDate]);
                    }
                })
                ->get();

            // If no active events are found, return a response indicating so
            if ($events->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'No active events found', $user->reportingUser], 404);
            }

            $eventData = [];

            foreach ($events as $event) {
                // Initialize counts
                $totalBookings = 0;
                $remainingBookings = 0;
                $checkedBookings = 0;

                // Loop through each ticket and its bookings to calculate the counts
                foreach ($event->tickets as $ticket) {
                    $totalBookings += $ticket->bookings->count();
                    $totalBookings += $ticket->agentBooking->count();
                    $totalBookings += $ticket->posBookings->sum('quantity');

                    // Calculate remaining bookings (status 0)
                    $remainingBookings += $ticket->bookings->where('status', 0)->count();
                    $remainingBookings += $ticket->agentBooking->where('status', 0)->count();
                    $remainingBookings += $ticket->posBookings->where('status', 0)->sum('quantity');

                    // Calculate checked bookings (status 1)
                    $checkedBookings += $ticket->bookings->where('status', 1)->count();
                    $checkedBookings += $ticket->agentBooking->where('status', 1)->count();
                    $checkedBookings += $ticket->posBookings->where('status', 1)->sum('quantity');
                }

                // Determine the category based on the event_type
                $category = $event->event_type == 'season' ? 'Seasonal' : 'Daily';

                // Prepare the event data
                $eventData[] = [
                    'event' => $event,
                    'total_bookings' => $totalBookings,
                    'remaining_bookings' => $remainingBookings,
                    'checked_bookings' => $checkedBookings,
                    'category' => $category,
                ];
            }

            return response()->json(['status' => true, 'data' => $eventData], 201);
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return response()->json(['status' => false, 'message' => 'An error occurred: ' . $e->getMessage()], 500);
        }
    }

    public function create(Request $request)
    {
        // Validate unique event name per organizer (user)
        $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    $exists = Event::where('user_id', $request->user_id)
                        ->where('name', $value)
                        ->exists();
                    if ($exists) {
                        $fail('An event with this name already exists for this organizer.');
                    }
                },
            ],
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            $event = new Event();
            $event->user_id = $request->user_id;
            $event->category = $request->category;
            $event->name = $request->name;
            $event->venue_id = $request->venue_id;
            $event->artist_id = $request->artist_id;
            $event->description = $request->description;
            $event->insta_whts_url = $request->insta_whts_url;
            $event->ticket_terms = $request->ticket_terms;
            $event->date_range = $request->date_range;
            $event->entry_time = $request->entry_time;
            $event->start_time = $request->start_time;
            $event->end_time = $request->end_time;
            $event->event_type = $request->event_type;
            $event->whts_note = $request->whts_note;
            $event->booking_notice = $request->booking_notice;
            $eventKey = $this->keyGenerator->generateKey();
            $event->event_key = $eventKey;
            $event->save();

            // 2️⃣ Save event_controls
            $this->storeEventControls($event->id, $request);

            // 3️⃣ Save event_galleries
            $this->storeEventGalleries($event->id, $request);

            // save event_sco
            $this->storeEventSeo($event->id, $request);

            return response()->json(['status' => true, 'message' => 'Event Created Successfully', 'event' => $event], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to create Event', 'error' => $e->getMessage()], 500);
        }
    }

    private function storeEventControls($eventId, Request $request)
    {
        $controls = new EventControl();
        $controls->event_id = $eventId;
        $controls->status = $request->status ?? 1;

        foreach ($this->eventControlBooleanFields() as $field) {
            $controls->{$field} = $this->normalizeBoolean($request->input($field, false));
        }

        $controls->save();
    }

    private function storeEventGalleries($eventId, Request $request)
    {
        $gallery = new EventGallery();
        $gallery->event_id = $eventId;

        // Base gallery folder
        $baseFolder = "gallery/events";

        if ($request->hasFile('thumbnail')) {
            $gallery->thumbnail = $this->storeFile($request->file('thumbnail'), $baseFolder . '/thumbnail');
        }

        if ($request->hasFile('layout_image')) {
            $gallery->layout_image = $this->storeFile($request->file('layout_image'), $baseFolder . '/layout_image');
        }

        if ($request->hasFile('insta_thumbnail')) {
            $gallery->insta_thumbnail = $this->storeFile($request->file('insta_thumbnail'), $baseFolder . '/insta_thumbnail');
        }

        if ($request->hasFile('images')) {
            $imagePaths = [];
            foreach ($request->file('images') as $file) {
                $imagePaths[] = $this->storeFile($file, $baseFolder . '/images');
            }
            $gallery->images = json_encode($imagePaths);
        }

        $gallery->youtube_url = $request->youtube_url ?? null;
        $gallery->insta_url = $request->insta_url ?? null;

        $gallery->save();
    }

    private function storeEventSeo($eventId, Request $request)
    {
        $sco = new SeoConfig();

        $sco->type = $request->seo_type ?? 'event';
        $sco->item_id = $eventId;
        $sco->category_name = $request->name ?? null;
        $sco->meta_title = $request->meta_title ?? null;
        $sco->meta_tag = $request->meta_tag ?? null;
        $sco->meta_description = $request->meta_description ?? null;
        $sco->meta_keyword = $request->meta_keyword ?? null;
        $sco->save();
    }

    public function edit(Request $request, string $id)
    {
        // Always include tickets (for calculations)
        $relations = [
            'Category:id,title',
            'EventHasLayout:id,event_id,layout_id',
            'taxData:id,user_id,convenience_fee,type',
            'eventMedia:thumbnail,insta_thumbnail,insta_url,layout_image,youtube_url,event_id',
            'venue:id,name,address,city,state,map_url,aembeded_code',
            'eventControls:event_id,agent_booking,booking_by_seat,house_full,status,ticket_system,overnight_event',
            'eventSeo:item_id,category_name,meta_description,meta_keyword,meta_title,meta_tag,type',
            'tickets:id,event_id,price,sale,sale_price,sale_date,sold_out'
        ];

        $event = Event::with($relations)
            ->where('event_key', $id)
            ->firstOrFail();

        // 🔹 Artists
        $event->artists_list = $event->artists()->whereNotNull('id')->get();

        // 🔹 ContentMaster fields: replace original field with relational content value
        foreach (Event::$contentFields as $field) {
            // Check if the field has a valid numeric ID before querying
            $fieldValue = $event->getAttribute($field);

            // Only query if the field contains a valid numeric ID
            if ($fieldValue && is_numeric($fieldValue)) {
                try {
                    $contentMaster = $event->{$field}()->select('content')->first();
                    // Set the field directly with the content value (replacing the original)
                    $event->setAttribute($field, $contentMaster ? $contentMaster->content : null);
                } catch (\Exception $e) {
                    // If relationship fails, use the field value as-is
                    $event->setAttribute($field, $fieldValue);
                }
            } else {
                // Field already contains text content, use as-is
                $event->setAttribute($field, $fieldValue);
            }

            // Make sure it's visible (remove from hidden if it was there)
            $event->makeVisible([$field]);
        }

        // 🔹 Expiry check
        $today = Carbon::today();
        $dateRange = explode(',', $event->date_range);
        $endDate = count($dateRange) === 2
            ? Carbon::parse($dateRange[1])
            : Carbon::parse($dateRange[0]);

        $isExpired = $today->gt($endDate);

        // Use EventDetailResource for transformation
        return response()->json([
            'status' => true,
            'event' => new EventDetailResource($event),
            'event_expired' => $isExpired,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            // 🔹 Find the event
            $event = Event::where('event_key', $id)->firstOrFail();

            // 🔹 Validate unique event name per organizer if name is being updated
            if ($request->has('name')) {
                $userId = $request->has('user_id') ? $request->user_id : $event->user_id;
                $exists = Event::where('user_id', $userId)
                    ->where('name', $request->name)
                    ->where('id', '!=', $event->id)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'An event with this name already exists for this organizer.'
                    ], 422);
                }
            }

            // 🔹 Update event fields
            // 🔹 Update event fields only if they exist in request
            if ($request->has('user_id')) {
                $event->user_id = $request->user_id;
            }

            if ($request->has('category')) {
                $event->category = $request->category;
            }

            if ($request->has('name')) {
                $event->name = $request->name;
            }

            if ($request->has('venue_id')) {
                $event->venue_id = $request->venue_id;
            }

            if ($request->has('artist_id')) {
                $event->artist_id = $request->artist_id;
            }

            if ($request->has('description')) {
                $event->description = $request->description;
            }

            if ($request->has('insta_whts_url')) {
                $event->insta_whts_url = $request->insta_whts_url;
            }

            if ($request->has('ticket_terms')) {
                $event->ticket_terms = $request->ticket_terms;
            }

            if ($request->has('date_range')) {
                $event->date_range = $request->date_range;
            }

            if ($request->has('entry_time')) {
                $event->entry_time = $request->entry_time;
            }

            if ($request->has('start_time')) {
                $event->start_time = $request->start_time;
            }

            if ($request->has('end_time')) {
                $event->end_time = $request->end_time;
            }

            if ($request->has('event_type')) {
                $event->event_type = $request->event_type;
            }

            if ($request->has('whts_note')) {
                $event->whts_note = $request->whts_note;
            }

            if ($request->has('booking_notice')) {
                $event->booking_notice = $request->booking_notice;
            }

            // if ($request->has('step')) {
            //     $event->step = $request->step;
            // }

            $event->save();


            // 🔹 Update Event Controls
            $this->updateEventControls($event->id, $request);

            // 🔹 Update Event Galleries
            $this->updateEventGalleries($event->id, $request);

            // 🔹 Update Event Seo
            $this->updateEventSeo($event->id, $request);

            // // update eventhas layout
            // if ($request->ticket_system == 1) {
            //     $this->updateEventHasLayout($event->id);
            // }

            if ($request->step === 'publish') {

                // Base URL from env
                // $baseUrl = rtrim(env('APP_URL', 'http://localhost:8000'), '/');

                $baseUrl = rtrim(config('app.url'), '/');

                // Static segment
                $staticSegment = 'events';

                // Dynamic city from venue
                $city = $event->venue ? str_replace(' ', '-', $event->venue->city) : 'city';

                // Dynamic organisation from event organizer
                $organisation = $event->user ? str_replace(' ', '-', $event->user->organisation) : 'org';

                // Event slug from request
                $eventSlug = str_replace(' ', '-', $request->slug ?? $event->name);

                // Event key/id
                $eventKey = $id;

                // Full URL to shorten
                $fullUrl = "{$baseUrl}/{$staticSegment}/{$city}/{$organisation}/{$eventSlug}/{$eventKey}";

                // Call private function to create short URL
                $shortUrlData = $this->createShortUrlFromUrl($fullUrl, $eventKey);
                $event->short_url = $shortUrlData['short_url'];
                $event->save();
            }


            return response()->json([
                'status' => true,
                'message' => 'Event Updated Successfully',
                'event' => $event
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update Event',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function updateEventControls($eventId, Request $request)
    {
        $controls = EventControl::where('event_id', $eventId)->first();

        if (!$controls) {
            $controls = new EventControl();
            $controls->event_id = $eventId;
        }

        $controls->status = $request->status ?? $controls->status ?? 1;

        foreach ($this->eventControlBooleanFields() as $field) {
            if ($request->has($field)) {
                $controls->{$field} = $this->normalizeBoolean($request->input($field));
            } elseif (!isset($controls->{$field})) {
                $controls->{$field} = false;
            }
        }

        $controls->save();
    }

    /**
     * Normalize incoming values to 0 or 1 for tinyint storage.
     * Frontend still sends/receives boolean, but DB stores as 0/1.
     */
    private function normalizeBoolean($value, $default = false): int
    {
        if (is_null($value)) {
            return (int) $default;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return (int) $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return 0;
            }
            if (is_numeric($normalized)) {
                return (int) $normalized !== 0 ? 1 : 0;
            }
        }

        if (is_numeric($value)) {
            return (int) $value !== 0 ? 1 : 0;
        }

        return $value ? 1 : 0;
    }

    /**
     * Boolean columns stored on event_controls table.
     */
    private function eventControlBooleanFields(): array
    {
        return [
            'scan_detail',
            'event_feature',
            'house_full',
            'online_att_sug',
            'offline_att_sug',
            'multi_scan',
            'ticket_system',
            'booking_by_seat',
            'online_booking',
            'agent_booking',
            'pos_booking',
            'complimentary_booking',
            'exhibition_booking',
            'amusement_booking',
            'accreditation_booking',
            'sponsor_booking',
            'show_on_home',
            'overnight_event',
        ];
    }

    private function updateEventGalleries($eventId, Request $request)
    {
        $gallery = EventGallery::where('event_id', $eventId)->first();

        if (!$gallery) {
            $gallery = new EventGallery();
            $gallery->event_id = $eventId;
        }

        $baseFolder = "gallery/events";

        // 🔹 Replace Thumbnail
        if ($request->hasFile('thumbnail')) {
            if ($gallery->thumbnail && Storage::disk('public')->exists($gallery->thumbnail)) {
                Storage::disk('public')->delete($gallery->thumbnail);
            }
            $gallery->thumbnail = $this->storeFile($request->file('thumbnail'), $baseFolder . '/thumbnail');
        }

        // 🔹 Replace Layout Image
        if ($request->hasFile('layout_image')) {
            if ($gallery->layout_image && Storage::disk('public')->exists($gallery->layout_image)) {
                Storage::disk('public')->delete($gallery->layout_image);
            }
            $gallery->layout_image = $this->storeFile($request->file('layout_image'), $baseFolder . '/layout_image');
        }

        // 🔹 Replace Insta Thumbnail
        if ($request->hasFile('insta_thumbnail')) {
            if ($gallery->insta_thumbnail && Storage::disk('public')->exists($gallery->insta_thumbnail)) {
                Storage::disk('public')->delete($gallery->insta_thumbnail);
            }
            $gallery->insta_thumbnail = $this->storeFile($request->file('insta_thumbnail'), $baseFolder . '/insta_thumbnail');
        }

        // 🔹 Replace Multiple Images
        if ($request->hasFile('images')) {
            // delete all old images first
            if ($gallery->images) {
                $oldImages = json_decode($gallery->images, true);
                foreach ($oldImages as $oldImg) {
                    if (Storage::disk('public')->exists($oldImg)) {
                        Storage::disk('public')->delete($oldImg);
                    }
                }
            }

            $imagePaths = [];
            foreach ($request->file('images') as $file) {
                $imagePaths[] = $this->storeFile($file, $baseFolder . '/images');
            }
            $gallery->images = json_encode($imagePaths);
        }

        // 🔹 Update URLs
        $gallery->youtube_url = $request->youtube_url ?? $gallery->youtube_url;
        $gallery->insta_url = $request->insta_url ?? $gallery->insta_url;

        $gallery->save();
    }

    private function updateEventSeo($eventId, Request $request)
    {
        // Check if SEO record exists
        $seo = SeoConfig::where('item_id', $eventId)
            ->first();

        if (!$seo) {
            // Create new if not exists
            $seo = new SeoConfig();
            $seo->item_id = $eventId;
            // $seo->type = 'event';
        }

        $seo->category_name = $request->name ?? $seo->name;
        $seo->meta_title = $request->meta_title ?? $seo->meta_title;
        $seo->meta_tag = $request->meta_tag ?? $seo->meta_tag;
        $seo->meta_description = $request->meta_description ?? $seo->meta_description;
        $seo->meta_keyword = $request->meta_keyword ?? $seo->meta_keyword;

        $seo->save();
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }

    public function export(Request $request)
    {
        $loggedInUser = Auth::user();
        $organizer = $request->input('organizer');
        $category = $request->input('category');
        $eventType = $request->input('event_type');
        $status = $request->input('status');
        $eventDates = $request->input('date_range') ? explode(',', $request->input('date_range')) : null;
        $dates = $request->input('date') ? explode(',', $request->input('date')) : null;

        $query = Event::query()
            ->with('user');

        // Check if user is Admin or not
        if (!$loggedInUser->hasRole('Admin')) {
            // Get user's own events
            $query->where('user_id', $loggedInUser->id);
        }

        // Apply filters
        if ($request->has('organizer')) {
            $query->where('user_id', $organizer);
        }

        if ($request->has('category')) {
            $query->where('category', $category);
        }

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        if ($request->has('status')) {
            $query->where('status', $status);
        }

        if ($eventDates) {
            if (count($eventDates) === 1) {
                $singleDate = Carbon::parse($eventDates[0])->toDateString();
                $query->whereDate('date_range', $singleDate);
            } elseif (count($eventDates) === 2) {
                $startDate = Carbon::parse($eventDates[0])->startOfDay();
                $endDate = Carbon::parse($eventDates[1])->endOfDay();
                $query->whereBetween('date_range', [$startDate, $endDate]);
            }
        }

        if ($dates) {
            if (count($dates) === 1) {
                $singleDate = Carbon::parse($dates[0])->toDateString();
                $query->whereDate('created_at', $singleDate);
            } elseif (count($dates) === 2) {
                $startDate = Carbon::parse($dates[0])->startOfDay();
                $endDate = Carbon::parse($dates[1])->endOfDay();
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
        }

        $events = $query->get()->map(function ($event, $index) {
            return [
                'sr_no' => $index + 1,
                'name' => $event->name,
                'category' => $event->category,
                'organizer' => optional($event->user)->name ?? 'N/A', // Safely access user name
                'event_date' => $event->date_range,
                'event_type' => $event->event_type,
                'status' => match ((string) $event->status) {
                    '0' => 'Ongoing',
                    '1' => 'Upcoming',
                    '2' => 'Finished',
                    default => 'Unknown'
                },
                'organisation' => $event->user->organisation,
            ];
        })->toArray();
        //return response()->json(['status' => true, 'events' => $events], 200);
        return Excel::download(new EventExport($events), 'events_export.xlsx');
    }

    public function eventWhatsapp(Request $request)
    {
        $today = Carbon::today();
        $categoryTitle = $request->category;
        $bookingType = $request->type;

        $query = Event::where('status', "1")->with([
            'tickets' => function ($query) {
                $query->select('id', 'event_id', 'price', 'sale_price', 'sale', 'booking_not_open', 'sold_out');
            },
            'user' => function ($query) {
                $query->select('id', 'name', 'organisation'); // Include fields you want to retrieve
            },
            'category' => function ($query) {
                $query->select('id', 'title'); // Fetch category title
            }
        ]);
        $bookingTypeFields = [
            'online' => 'online_booking',
            'agent' => 'agent_booking',
            'sponsor' => 'sponsor_booking',
            'pos' => 'pos_booking',
            'complimentary' => 'complimentary_booking',
            'exhibition' => 'exhibition_booking',
            'amusement' => 'amusement_booking',
        ];

        if ($bookingType && isset($bookingTypeFields[$bookingType])) {
            $query->where($bookingTypeFields[$bookingType], 1);
        }

        if ($categoryTitle) {
            $category = Category::where('title', $categoryTitle)->select('id')->first();
            if ($category) {
                $events = $query->where('category', $category->id)
                    ->get(['id', 'name', 'thumbnail', 'event_key', 'date_range', 'category', 'city', 'user_id']);
            } else {
                return response()->json(['status' => false, 'message' => 'Category not found'], 404);
            }
        } else {
            $events = $query->get(['id', 'name', 'thumbnail', 'event_key', 'date_range', 'category', 'city', 'user_id']);
        }
        // Check if any events are fetched
        if ($events->isEmpty()) {
            return response()->json(['status' => true, 'message' => 'No events found with status 1'], 200);
        }

        // Initialize arrays for ongoing and future events
        $ongoingEvents = collect();
        $futureEvents = collect();

        // Categorize events
        foreach ($events as $event) {
            $dates = array_map('trim', explode(',', $event->date_range));
            // Handle single date or date range
            if (count($dates) === 1) {
                $startDate = Carbon::parse($dates[0]);
                $endDate = $startDate; // Set endDate the same as startDate for single date
            } else {
                [$startDate, $endDate] = array_map('trim', $dates);
                $startDate = Carbon::parse($startDate);
                $endDate = Carbon::parse($endDate);
            }

            if ($today->between($startDate, $endDate)) {
                $ongoingEvents->push($event);
            } elseif ($today->lt($startDate)) {
                $futureEvents->push($event);
            }
        }

        // Sort events by start date
        $ongoingEvents = $ongoingEvents->sortBy(fn($event) => Carbon::parse(explode(',', $event->date_range)[0]));
        $futureEvents = $futureEvents->sortBy(fn($event) => Carbon::parse(explode(',', $event->date_range)[0]));

        // Combine ongoing and future events
        $sortedEvents = $ongoingEvents->merge($futureEvents);


        $sortedEvents->transform(function ($event) {
            return [
                'e_name' => $event->name . ' - ' . $event->event_key,
                'event_key' => $event->event_key
            ];
        });
        return response()->json(['status' => true, 'events' => $sortedEvents], 200);
    }

    public function editWhatsapp(string $id)
    {
        $parts = explode(' - ', $id);
        $eventKey = end($parts);
        $eventKey = "AA" . ltrim($eventKey, "AA");
        $event = Event::with('tickets')->where('event_key', $eventKey)->firstOrFail();

        // Only return ticket names and prices
        $tickets = $event->tickets->map(function ($ticket) {
            return [
                't_name' => $ticket->name . ' - ' . $ticket->price,
                'price' => $ticket->price
            ];
        });

        return response()->json(['status' => true, 'tickets' => $tickets], 200);
    }

    public function eventData($id)
    {
        try {
            $user = User::findOrFail($id);

            if (!$user->hasRole('Organizer')) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not an Organizer.',
                ], 403);
            }

            // $events = Event::where('user_id', $user->id)->get();
            $events = Event::where('user_id', $user->id)
                ->with(['tickets:id,event_id,name'])
                ->get(['id', 'name', 'event_key']);

            if ($events->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No events found for this organizer.'
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => $events,
                'message' => 'Events fetched successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function allEventData()
    {
        try {
            $user = Auth::user();

            if ($user->hasRole('Admin')) {
                $events = Event::with(['user.roles', 'Category'])
                    ->whereHas('user.roles', function ($query) {
                        $query->where('name', 'Organizer');
                    })
                    ->whereHas('Category', function ($query) {
                        $query->where('attendy_required', 1);
                    })
                    ->orderByDesc('id')
                    ->get();
            } elseif ($user->hasRole('Organizer')) {
                $events = Event::with(['user.roles', 'Category'])
                    ->where('user_id', $user->id)
                    ->whereHas('user.roles', function ($query) {
                        $query->where('name', 'Organizer');
                    })
                    ->whereHas('Category', function ($query) {
                        $query->where('attendy_required', 1);
                    })
                    ->orderByDesc('id')
                    ->get();
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized or no role assigned'
                ], 403);
            }

            $formattedEvents = $events->map(function ($event) {
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'card_url' => $event->card_url,
                    'category' => $event->category,
                    'attendy_required' => $event->Category->attendy_required,
                    'user_id' => $event->user_id,
                    'role' => optional($event->user->roles->first())->name ?? null
                ];
            });

            if ($formattedEvents->isEmpty()) {
                return response()->json(['status' => false, 'message' => 'No events found'], 200);
            }

            return response()->json(['status' => true, 'data' => $formattedEvents], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Error fetching events: ' . $e->getMessage()], 500);
        }
    }

    private function storeLayout(array $layout, $categoryId)
    {
        CatLayout::updateOrCreate(
            ['category_id' => $categoryId],
            [
                'user_photo' => isset($layout['userPhoto']) ? json_encode($layout['userPhoto']) : null,
                // 'zones'      => isset($layout['zoneGroup']) ? json_encode($layout['zoneGroup']) : null,
                'qr_code' => isset($layout['qrCode']) ? json_encode($layout['qrCode']) : null,
                'text_1' => isset($layout['textValue_0']) ? json_encode($layout['textValue_0']) : null,
                'text_2' => isset($layout['textValue_1']) ? json_encode($layout['textValue_1']) : null,
                'text_3' => isset($layout['textValue_2']) ? json_encode($layout['textValue_2']) : null,
            ]
        );
    }

    public function getLayoutByEventId($id)
    {
        // Step 1: Get the event
        $event = Event::where('id', $id)
            ->select(['id', 'event_key'])
            ->with('eventLayout')
            ->first();


        if (!$event) {
            return response()->json([
                'status' => false,
                'message' => 'Event not found.',
            ], 404);
        }
        if (!$event->IDCardLayout) {
            return response()->json([
                'status' => false,
                'message' => 'Layout not found for this event.',
            ], 404);
        }

        // Step 3: Return layout fields (automatically casted to arrays)
        return response()->json([
            'status' => true,
            'layout' => [
                'user_photo' => json_decode($event->IDCardLayout->user_photo, true),
                // 'zones'      => json_decode($event->IDCardLayout->zones, true),
                'qr_code' => json_decode($event->IDCardLayout->qr_code, true),
                'text_1' => json_decode($event->IDCardLayout->text_1, true),
                'text_2' => json_decode($event->IDCardLayout->text_2, true),
                'text_3' => json_decode($event->IDCardLayout->text_3, true),
            ]
        ]);
    }

    public function pastEvents()
    {
        $today = Carbon::today()->toDateString();
        $eventsQuery = Event::query();

        // Fetch events and calculate if they are past
        $events = $eventsQuery
            ->select('id', 'user_id', 'category', 'name', 'date_range', 'created_at', 'event_type', 'event_key')
            ->where('status', 1)
            ->with(['tickets:id,event_id,price,sale_price', 'user:id,name', 'Category:id,title'])
            ->orderBy('created_at', 'desc')
            ->get();

        $pastEvents = [];

        foreach ($events as $event) {
            $dateRange = explode(',', $event->date_range);

            if (count($dateRange) == 1) {
                // Single-day event
                $eventDate = Carbon::parse(trim($dateRange[0]));
                $isPast = $today > $eventDate->toDateString();
            } else {
                // Multi-day event
                $endDate = Carbon::parse(trim($dateRange[1]));
                $isPast = $today > $endDate->toDateString();
            }

            if ($isPast) {
                $event->event_status = 3; // Past
                $event->lowest_ticket_price = $event->tickets->min('price') ?? 0;
                $event->lowest_sale_price = $event->tickets->min('sale_price') ?? 0;

                $pastEvents[] = $event;
            }
        }

        if (empty($pastEvents)) {
            return response()->json(['status' => false, 'message' => 'No past events found'], 200);
        }

        return response()->json(['status' => true, 'events' => $pastEvents], 200);
    }

    public function handleWebhookTov(Request $request)
    {
        Log::info('Easebuzz TOV Webhook received:', $request->all());
        // Process the webhook data as needed
        // For example, you can log it or save it to the database
        return response()->json(['message' => 'Webhook received successfully'], 200);
    }

    /**
     * Get events by category
     * Uses Repository + Resource pattern for consistency
     */
    public function eventsByCategory($title)
    {
        $formattedTitle = str_replace('-', ' ', $title);

        $category = Category::whereRaw('LOWER(title) = ?', [strtolower($formattedTitle)])
            ->select('id', 'title')
            ->first();

        if (!$category) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }

        // Use Repository to get events (converts title to ID internally)
        $events = $this->eventRepository->getFilteredEventsQuery([
            'controls' => ['status' => "1"],
            'category' => $category->title
        ])->get();

        // Fetch SEO config for this category
        $seoConfig = DB::table('seo_configs')
            ->where('type', 'category')
            ->where('item_id', $category->id)
            ->select('meta_title', 'meta_tag', 'meta_description', 'meta_keyword')
            ->first();

        if ($events->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No events found for this category',
                'seo' => $seoConfig ?? null
            ], 200);
        }

        // Use EventCardResource for consistent transformation
        return response()->json([
            'status' => true,
            'category' => $category->title,
            'seo' => $seoConfig ?? null,
            'events' => EventCardResource::collection($events)
        ], 200);
    }

    public function eventsByData(Request $request)
    {

        $gt = $request->query('gt'); // category, venue, organizer_id
        $value = $request->query('value'); // actual value

        if (!$gt || !$value) {
            return response()->json([
                'status' => false,
                'message' => 'Please provide gt and value parameters'
            ], 400);
        }

        $query = Event::query()
            ->where('events.status', 1)
            ->leftJoin('users', 'events.user_id', '=', 'users.id')
            ->whereNotNull('users.organisation')
            ->where('users.organisation', '!=', '');

        // check gt value
        switch ($gt) {
            case 'cate':
                $categoryTitle = str_replace('-', ' ', $value);
                $category = Category::whereRaw('LOWER(title) = ?', [strtolower($categoryTitle)])->first();

                if (!$category) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Category not found'
                    ], 404);
                }

                $query->where('events.category', $category->id);

                // Fetch SEO
                $seoConfig = \DB::table('seo_configs')
                    ->where('type', 'category')
                    ->where('item_id', $category->id)
                    ->select('meta_title', 'meta_tag', 'meta_description', 'meta_keyword')
                    ->first();
                break;

            case 'venue':
                $query->where('events.venue_id', $value);
                $seoConfig = null;
                break;

            case 'orge_id':
                $query->where('events.user_id', $value);
                $seoConfig = null;
                break;

            default:
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid gt parameter'
                ], 400);
        }

        $events = $query->select(
            'events.id',
            'events.name',
            'events.thumbnail',
            'events.event_key',
            'events.date_range',
            'events.city',
            'events.venue_id',
            'users.organisation as organisation'
        )->get();

        if ($events->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No events found',
                'seo' => $seoConfig ?? null
            ], 200);
        }

        return response()->json([
            'status' => true,
            'events' => $events,
            'seo' => $seoConfig ?? null
        ], 200);
    }

    /**
     * Get organizations with their cities and events
     * Optimized with Repository + Resource pattern
     */
    public function landingOrg(Request $request)
    {
        $city = $request->query('city');

        $organisations = User::select('id', 'organisation', 'thumbnail')
            ->whereNotNull('organisation')
            ->distinct()
            ->get()
            ->map(function ($org) use ($city) {

                // Build filters for this organization
                $filters = ['user_id' => $org->id];
                if ($city) {
                    $filters['city'] = $city;
                }

                // Use Repository to get events
                $events = $this->eventRepository->getFilteredEventsQuery($filters)->get();

                // Get unique cities from events
                $cities = $events
                    ->pluck('venue.city')
                    ->filter()
                    ->unique()
                    ->values();

                // Add cities and events to organization
                $org->cities = $cities;
                $org->events = EventCardResource::collection($events); // Use Resource

                return $org;
            });

        return response()->json([
            'status' => true,
            'data' => $organisations
        ], 200);
    }

    public function landingOrgId(Request $request, $organisation)
    {
        $city = $request->query('city');

        $userId = User::where('organisation', $organisation)->first();

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Organisation not found.'
            ], 404);
        }

        $query = Event::with([
            'organizer:id,organisation',
            'venue:id,city',
            'eventMedia:id,event_id,thumbnail',
            'eventControls:id,event_id,status,house_full,event_feature',
            'tickets' => function ($query) {
                $query->select('id', 'event_id', 'price', 'sale_price', 'sale', 'sale_date', 'booking_not_open', 'sold_out', 'fast_filling', 'status');
            }
        ])
            ->where('user_id', $userId->id)
            ->select('id', 'event_key', 'name', 'user_id', 'category', 'date_range', 'venue_id');

        // Filter by city from related venue
        if ($city) {
            $query->whereHas('venue', function ($q) use ($city) {
                $q->where('city', '=', $city); // ✅ Properly bound parameter
            });
        }

        $events = $query->orderBy('created_at', 'desc')->get();

        if ($events->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No events found for this organisation.'
            ], 200);
        }

        // Use EventCardResource for transformation (same as index and FeatureEvent)
        return response()->json([
            'status' => true,
            'data' => EventCardResource::collection($events)
        ], 200);
    }


    private function createShortUrlFromUrl(string $originalUrl, string $eventKey): array
    {
        // Generate unique short code
        do {
            $shortCode = Str::random(6); // alphanumeric, 6 chars
        } while (ShortUrl::where('short_url', 'like', '%' . $shortCode)->exists());


        $shortUrlFull = 'https://gyt.co.in/s/' . $shortCode;
        // $shortUrlFull = 'https://gyt.co.in/' . $shortCode;

        // Update or create based on event_key
        $shortUrl = ShortUrl::updateOrCreate(
            ['event_key' => $eventKey],
            [
                'long_url' => $originalUrl,
                'short_url' => $shortUrlFull
            ]
        );

        return [
            'long_url' => $originalUrl,
            'short_url' => $shortUrlFull
        ];
    }

    private function getLongUrl($url)
    {
        $ShortUrl = ShortUrl::where('short_url', $url)->first();

        if (!$ShortUrl) {
            return response()->json(['status' => false, 'message' => 'ShortUrl not found'], 200);
        }
        return response()->json([
            'status' => true,
            'message' => 'ShortUrl successfully',
            'data' => $ShortUrl
        ], 200);
    }

    private function redirectUrl($shortCode)
    {

        $shortUrl = ShortUrl::where('short_url', 'like', '%' . $shortCode)->first();
        // return $shortUrl->long_url;
        if (!$shortUrl) {
            return response()->json([
                'status' => false,
                'message' => 'Short URL not found'
            ], 404);
        }

        return redirect()->away($shortUrl->long_url);
    }

    public function editevent(Request $request, $id, $step)
    {
        try {
            // Base query
            $query = Event::query()
                ->where('event_key', $id); // or ->where('id', $id) if numeric ID

            // Step-wise configuration
            $stepConfigs = [
                'basic' => [
                    'columns' => [
                        'id',
                        'event_key',
                        'user_id',
                        'category',
                        'name',
                        'venue_id',
                        'description'
                    ],
                    'relations' => ['Category:id,title', 'venue:id,name,address,city'],
                ],

                'controls' => [
                    'columns' => ['id', 'event_key', 'user_id', 'insta_whts_url', 'whts_note', 'booking_notice'],
                    'relations' => [
                        'eventControls:id,event_id,scan_detail,event_feature,status,house_full,online_att_sug,offline_att_sug,show_on_home'
                    ],
                ],

                'timing' => [
                    'columns' => [
                        'id',
                        'event_key',
                        'user_id',
                        'date_range',
                        'entry_time',
                        'start_time',
                        'end_time',
                        'event_type',
                    ],
                    'relations' => [
                        'eventControls:id,event_id,overnight_event',
                    ],
                ],

                'tickets' => [
                    'columns' => ['id', 'event_key', 'user_id', 'ticket_terms', 'venue_id'],
                    'relations' => [
                        'tickets',
                        'eventControls:id,event_id,multi_scan,ticket_system',
                        'layout:id,venue_id,name',
                        'EventHasLayout:id,event_id,layout_id',
                    ],
                ],

                'artist' => [
                    'columns' => ['id', 'event_key', 'user_id', 'artist_id'],
                    'relations' => [], // will load manually
                ],

                'media' => [
                    'columns' => ['id', 'event_key', 'user_id'],
                    'relations' => [
                        'eventMedia:id,event_id,thumbnail,insta_thumbnail,layout_image,images,insta_url,youtube_url'
                    ],
                ],

                'seo' => [
                    'columns' => ['id', 'event_key', 'user_id'],
                    'relations' => [
                        'eventSeo:id,item_id,meta_title,meta_description,meta_tag,meta_keyword,type,category_name'
                    ],
                ],

                'publish' => [
                    'columns' => [
                        'id',
                        'event_key',
                        'user_id',
                        'category',
                        'short_url',
                        'name',
                    ],
                    'relations' => ['Category:id,title'],
                ],
            ];

            if (!isset($stepConfigs[$step])) {
                return response()->json([
                    'status' => false,
                    'message' => "Invalid step: {$step}"
                ], 400);
            }

            $config = $stepConfigs[$step];

            // Select columns first
            $event = $query->select($config['columns'])->first();

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => "Event not found for step: {$step}"
                ], 404);
            }

            // Load relations dynamically
            if (!empty($config['relations'])) {
                $event->load($config['relations']);
            }

            // ✅ Handle artist step
            if ($step === 'artist') {
                $artistIds = [];

                // Handle both JSON and comma-separated formats
                if (!empty($event->artist_id)) {
                    $decoded = json_decode($event->artist_id, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $artistIds = $decoded;
                    } else {
                        // fallback if it's a comma-separated string like "6,9"
                        $artistIds = array_map('intval', explode(',', $event->artist_id));
                    }
                }

                $event->artists = [];

                if (!empty($artistIds)) {
                    $event->artists = Artist::whereIn('id', $artistIds)
                        ->select('id', 'name', 'photo', 'description', 'type')
                        ->get();
                }
            }

            // ✅ Handle media step
            if ($step === 'media') {
                // $event->images = $event->eventMediaa->pluck('images')->toArray();
                // $event->gallery_images = [];
                // $event->remove_images = [];
            }

            // ✅ Handle controls step - convert booleans to 0/1
            if ($step === 'controls' && $event->eventControls) {
                $controls = $event->eventControls;

                // Convert boolean fields to 0/1
                $controls->scan_detail = $controls->scan_detail ? 1 : 0;
                $controls->event_feature = $controls->event_feature ? 1 : 0;
                $controls->status = $controls->status ? 1 : 0;
                $controls->house_full = $controls->house_full ? 1 : 0;
                $controls->online_att_sug = $controls->online_att_sug ? 1 : 0;
                $controls->offline_att_sug = $controls->offline_att_sug ? 1 : 0;
                $controls->show_on_home = $controls->show_on_home ? 1 : 0;

                $event->eventControls = $controls;
            }

            return response()->json([
                'status' => true,
                'step' => $step,
                'event' => $event,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    //all event,booking ticket delete
    public function deleteEvent($id)
    {
        try {
            $event = Event::findOrFail($id);

            // 🔹 Step 1: Collect related IDs
            $ticketIds = Ticket::where('event_id', $id)->pluck('id');
            $eventControlIds = EventControl::where('event_id', $id)->pluck('id');
            $eventGalleryIds = EventGallery::where('event_id', $id)->pluck('id');

            // 🔹 Step 2: Collect all master_tokens linked to event tickets
            $masterTokens = Booking::whereIn('ticket_id', $ticketIds)
                ->whereNotNull('master_token')
                ->pluck('master_token')
                ->unique()
                ->toArray();

            // 🔹 Step 3: Soft delete event & related tables
            $event->delete();
            EventControl::whereIn('id', $eventControlIds)->delete();
            EventGallery::whereIn('id', $eventGalleryIds)->delete();
            Ticket::whereIn('id', $ticketIds)->delete();

            // 🔹 Step 4: Delete bookings & related master bookings safely
            Booking::whereIn('ticket_id', $ticketIds)->delete();
            ComplimentaryBookings::whereIn('ticket_id', $ticketIds)->delete();
            PosBooking::whereIn('ticket_id', $ticketIds)->delete();

            // 🔹 Step 5: Delete only unique master bookings using tokens
            if (!empty($masterTokens)) {
                MasterBooking::whereIn('order_id', $masterTokens)->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'Event and all related data deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error deleting event: ' . $e->getMessage()
            ], 500);
        }
    }


    //all event,booking ticket restore
    public function restoreEvent($id)
    {
        try {
            $event = Event::withTrashed()->findOrFail($id);
            $event->restore();

            // Related data restore
            $ticketIds = Ticket::withTrashed()->where('event_id', $id)->pluck('id');
            $eventControlIds = EventControl::withTrashed()->where('event_id', $id)->pluck('id');
            $eventGalleryIds = EventGallery::withTrashed()->where('event_id', $id)->pluck('id');

            EventControl::withTrashed()->whereIn('id', $eventControlIds)->restore();
            EventGallery::withTrashed()->whereIn('id', $eventGalleryIds)->restore();
            Ticket::withTrashed()->whereIn('id', $ticketIds)->restore();
            Booking::withTrashed()->whereIn('ticket_id', $ticketIds)->restore();
            PosBooking::withTrashed()->whereIn('ticket_id', $ticketIds)->restore();
            ComplimentaryBookings::withTrashed()->whereIn('ticket_id', $ticketIds)->restore();

            // 🟢 MasterBooking restore logic (based on master_token)
            $masterTokens = Booking::withTrashed()
                ->whereIn('ticket_id', $ticketIds)
                ->whereNotNull('master_token')
                ->pluck('master_token')
                ->unique();

            foreach ($masterTokens as $token) {
                // Restore MasterBooking records where master_token matches
                MasterBooking::withTrashed()->where('order_id', $token)->restore();
            }

            return response()->json([
                'status' => true,
                'message' => 'Event and all related data restored successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error restoring event: ' . $e->getMessage()
            ], 500);
        }
    }


    //all deleted event list
    public function deleteGetEvent()
    {
        $loggedInUser = Auth::user()->load('reportingUser');
        $today = Carbon::today()->toDateString();

        // ✅ Always start with only soft-deleted events
        if ($loggedInUser->hasRole('Admin')) {
            $eventsQuery = Event::onlyTrashed();
        } else {
            $reporting_user = $loggedInUser->reportingUser;

            if ($reporting_user) {
                $reporting_userAdmin = $reporting_user->roles->pluck('name')->first();

                if ($reporting_userAdmin == 'Admin' || $reporting_userAdmin == 'Organizer') {
                    $eventsQuery = Event::onlyTrashed()
                        ->where('user_id', $loggedInUser->id);
                } else {
                    $eventsQuery = Event::onlyTrashed()
                        ->where(function ($query) use ($loggedInUser, $reporting_user) {
                            $query->where('user_id', $loggedInUser->id)
                                ->orWhere('user_id', $reporting_user->id);
                        });
                }
            } else {
                $eventsQuery = Event::onlyTrashed()
                    ->where('user_id', $loggedInUser->id);
            }
        }

        // ✅ Fetch only deleted events (redundant but ensures strict filtering)
        $eventsQuery->whereNotNull('deleted_at');

        $events = $eventsQuery
            ->select('id', 'user_id', 'category', 'name', 'date_range', 'created_at', 'event_type', 'event_key', 'venue_id', 'deleted_at')
            ->with([
                'tickets:id,event_id,price,sale_price',
                'user:id,name,organisation',
                'Category:id,title',
                'venue:id,city',
            ])
            ->orderBy('deleted_at', 'desc')
            ->get();

        // ✅ Mark status for UI (ongoing, upcoming, past)
        foreach ($events as $event) {
            $dateRange = explode(',', $event->date_range);

            if (count($dateRange) == 1) {
                $eventDate = Carbon::parse(trim($dateRange[0]));
                if ($today == $eventDate->toDateString()) {
                    $event->event_status = 1; // ongoing
                } elseif ($today < $eventDate->toDateString()) {
                    $event->event_status = 2; // upcoming
                } else {
                    $event->event_status = 3; // past
                }
            } else {
                $startDate = Carbon::parse(trim($dateRange[0]));
                $endDate = Carbon::parse(trim($dateRange[1]));
                if ($today >= $startDate->toDateString() && $today <= $endDate->toDateString()) {
                    $event->event_status = 1; // ongoing
                } elseif ($today < $startDate->toDateString()) {
                    $event->event_status = 2; // upcoming
                } else {
                    $event->event_status = 3; // past
                }
            }

            // ✅ Lowest price
            $event->lowest_ticket_price = $event->tickets->min('price') ?? 0;
            $event->lowest_sale_price = $event->tickets->min('sale_price') ?? 0;
        }

        return response()->json([
            'status' => true,
            'events' => $events
        ], 200);
    }

    //all event,booking ticket hard delete
    public function destroy($id)
    {
        try {
            $event = Event::withTrashed()->findOrFail($id); // include soft deleted events too

            // Related data IDs
            $ticketIds = Ticket::withTrashed()->where('event_id', $id)->pluck('id');
            $eventControlIds = EventControl::withTrashed()->where('event_id', $id)->pluck('id');
            $eventGalleryIds = EventGallery::withTrashed()->where('event_id', $id)->pluck('id');

            // 🟡 Get unique master_tokens from deleted bookings
            $masterTokens = Booking::withTrashed()
                ->whereIn('ticket_id', $ticketIds)
                ->whereNotNull('master_token')
                ->pluck('master_token')
                ->unique();

            // ✅ Hard delete related records
            Booking::whereIn('ticket_id', $ticketIds)->forceDelete();
            PosBooking::whereIn('ticket_id', $ticketIds)->forceDelete();
            ComplimentaryBookings::whereIn('ticket_id', $ticketIds)->forceDelete();

            // 🔴 Delete MasterBooking once per unique master_token
            foreach ($masterTokens as $token) {
                MasterBooking::where('master_token', $token)->forceDelete();
            }

            Ticket::whereIn('id', $ticketIds)->forceDelete();
            EventControl::whereIn('id', $eventControlIds)->forceDelete();
            EventGallery::whereIn('id', $eventGalleryIds)->forceDelete();

            // ✅ Finally, hard delete event itself
            $event->forceDelete();

            return response()->json([
                'status' => true,
                'message' => 'Event and all related data permanently deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error deleting event: ' . $e->getMessage()
            ], 500);
        }
    }
}
