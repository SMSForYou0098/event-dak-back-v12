<?php

namespace App\Http\Controllers;

use App\Exports\BookingExport;
use App\Http\Resources\DashboardSummaryResource;
use App\Jobs\BookingMailJob;
use App\Jobs\SendBookingAlertJob;
use App\Models\Booking;
use App\Models\ComplimentaryBookings;
use App\Models\Event;
use App\Models\PromoCode;
use App\Models\MasterBooking;
use App\Models\PaymentLog;
use App\Models\PenddingBooking;
use App\Models\BookingTax;
use App\Models\PenddingBookingsMaster;
use App\Models\PosBooking;
use App\Models\Ticket;
use App\Services\DateRangeService;
use App\Services\PermissionService;
use App\Services\MasterBookingService;
use App\Services\BookingCacheService;
use Illuminate\Support\Facades\Auth;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Services\WhatsappService;
use App\Services\TemplateReplacementService;
use App\Services\BookingAlertService;
use App\Models\WhatsappApi;
use App\Services\DashboardStatisticsService;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;

class BookingController extends Controller
{

    protected DashboardStatisticsService $statsService;

    public function __construct(DashboardStatisticsService $statsService)
    {
        $this->statsService = $statsService;
    }

    public function list(Request $request, $type, $id, PermissionService $permissionService)
    {
        try {

            $loggedInUser = Auth::user();

            // Pagination & search params (same style as OnlineBookings / pendingBookingList)
            $perPage = min($request->input('per_page', 10), 100);
            $page = (int) $request->input('page', 1);
            $search = trim($request->input('search', ''));

            // Permissions for masking sensitive data
            $permissions = $permissionService->check(['View Username', 'View User Number']);
            $canViewUsername = $permissions['View Username'];
            $canViewContact  = $permissions['View User Number'];

            // Date range handling – keep original behavior
            $startDate = Carbon::today()->startOfDay();
            $endDate = Carbon::today()->endOfDay();

            if ($request->has('date')) {
                $dates = explode(',', $request->date);
                $startDate = Carbon::parse($dates[0])->startOfDay();
                $endDate = count($dates) === 2
                    ? Carbon::parse($dates[1])->endOfDay()
                    : Carbon::parse($dates[0])->endOfDay();
            }

            // Get organizer's ticket IDs if needed
            $organizerTicketIds = null;
            if ($loggedInUser->hasRole('Organizer')) {
                $eventIds = Event::where('user_id', $loggedInUser->id)->pluck('id');
                $organizerTicketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id');
            }

            // Get master bookings
            $masterQuery = MasterBooking::withTrashed()
                ->select([
                    'id',
                    'booking_id',
                    'order_id',
                    'set_id',
                    'booking_by',
                    'booking_type',
                    'total_amount',
                    'discount',
                    'created_at',
                    'payment_method',
                    'deleted_at'
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('booking_type', $type);

            // return response()->json($masterQuery);
            if ($loggedInUser->hasRole('Agent') || $loggedInUser->hasRole('Sponsor')) {
                $masterQuery->where('booking_by', $loggedInUser->id);
            }

            $Masterbookings = $masterQuery->get();

            // Filter master bookings for organizer after fetching
            if ($loggedInUser->hasRole('Organizer') && $organizerTicketIds) {
                $Masterbookings = $Masterbookings->filter(function ($master) use ($organizerTicketIds) {
                    if (is_array($master->booking_id)) {
                        // Check if any booking in this master belongs to organizer's events
                        $bookingIds = $master->booking_id;
                        $belongsToOrganizer = Booking::whereIn('id', $bookingIds)
                            ->whereIn('ticket_id', $organizerTicketIds)
                            ->exists();
                        return $belongsToOrganizer;
                    }
                    return false;
                });
            }

            $allBookingIds = $Masterbookings->flatMap(function ($master) {
                return is_array($master->booking_id) ? $master->booking_id : [];
            })->unique()->values();

            // Pre-fetch all agent bookings for master bookings
            $agentBookingsCollection = Booking::withTrashed()
                ->select([
                    'id',
                    'set_id',
                    'booking_by',
                    'user_id',
                    'ticket_id',
                    'status',
                    'total_amount',
                    'discount',
                    'quantity',
                    'booking_type',
                    'created_at',
                    'ticket_id',
                    'user_id',
                    'attendee_id',
                    'token',
                    'master_token',
                    'email',
                    'name',
                    'number',
                    'payment_method',
                    'payment_method',
                    'seat_name',
                    'section_id',
                    'batch_id',
                    'deleted_at'
                ])
                ->whereIn('id', $allBookingIds)
                ->where('booking_type', $type)
                ->withRelations() // Use optimized eager loading scope
                ->get()->keyBy('id');

            // Transform master bookings
            $Masterbookings = $Masterbookings->map(function ($master) use ($agentBookingsCollection) {
                $ids = is_array($master->booking_id) ? $master->booking_id : [];
                $bookings = collect($ids)->map(function ($id) use ($agentBookingsCollection) {
                    return $agentBookingsCollection->get($id);
                })->filter()->values();

                // Get first booking for master info
                $firstBooking = $bookings->first();

                if ($firstBooking) {
                    $master->status = $firstBooking->status;
                    $master->agent_name = $firstBooking->agentUser->name ?? '';
                    $master->event_name = $firstBooking->ticket->event->name ?? '';
                    $master->organizer = $firstBooking->ticket->event->user->name ?? '';
                }

                $master->bookings = $bookings;
                $master->is_deleted = !is_null($master->deleted_at);
                // $master->is_deleted = $master->trashed();
                $master->quantity = $bookings->count();
                $master->is_master = true; // Flag to identify master booking
                return $master;
            });

            // Get normal bookings (single bookings that are NOT part of master bookings)
            $normalQuery = Booking::withTrashed()
                ->select([
                    'id',
                    'set_id',
                    'booking_by',
                    'user_id',
                    'ticket_id',
                    'status',
                    'total_amount',
                    'discount',
                    'quantity',
                    'booking_type',
                    'created_at',
                    'ticket_id',
                    'user_id',
                    'attendee_id',
                    'token',
                    'master_token',
                    'email',
                    'name',
                    'number',
                    'payment_method',
                    'payment_method',
                    'seat_name',
                    'section_id',
                    'batch_id',
                    'deleted_at'
                ])->with([
                    'ticket:id,name,event_id,background_image',
                    'ticket.event:id,event_key,name,user_id',
                    'ticket.event.user:id,name,organisation',
                    'user:id,name,number,email,photo,reporting_user,company_name',
                    'agentUser:id,name',
                    'LSection:id,name'
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('booking_type', $type)
                ->whereNotIn('id', $allBookingIds); // Exclude master booking IDs

            // return response()->json( $normalQuery);
            if ($loggedInUser->hasRole('Agent') || $loggedInUser->hasRole('Sponsor')) {
                $normalQuery->where('booking_by', $loggedInUser->id);
            } elseif ($loggedInUser->hasRole('Organizer') && $organizerTicketIds) {
                $normalQuery->whereIn('ticket_id', $organizerTicketIds);
            }
            // Admin sees all - no additional filtering needed

            $normalBookings = $normalQuery->get()
                ->map(function ($booking) {
                    $booking->agent_name = $booking->agentUser->name ?? '';
                    $booking->event_name = $booking->ticket->event->name ?? '';
                    $booking->organizer = $booking->ticket->event->user->organisation ?? '';
                    $booking->quantity = 1;
                    $booking->is_deleted = !is_null($booking->deleted_at);
                    // $booking->is_deleted = $booking->trashed();
                    $booking->is_master = false; // Flag for single booking
                    return $booking;
                })->values();

            $combinedBookings = $Masterbookings->concat($normalBookings)
                ->sortByDesc('created_at')
                ->values();
            // --- Group Master Bookings by set_id ---
            $grouped = $combinedBookings->groupBy('set_id')->map(function ($bookings, $setId) {
                // Check if it's a master booking (has multiple)

                //                 $uniqueTicketIds = $bookings->pluck('ticket_id')->unique();
                //                 $isMaster = $bookings->count() > 1 && $uniqueTicketIds->count() > 1 && $bookings->first()->is_master;


                $ticketIds = collect();
                foreach ($bookings as $item) {
                    if ($item->is_master && isset($item->bookings) && count($item->bookings) > 0) {
                        foreach ($item->bookings as $child) {
                            $ticketIds->push($child->ticket_id);
                        }
                    } else {
                        $ticketIds->push($item->ticket_id);
                    }
                }

                $uniqueTicketIds = $ticketIds->unique();
                $isMaster = $bookings->count() > 1 && $uniqueTicketIds->count() > 1;

                if ($isMaster) {
                    $first = $bookings->first();
                    // Fetch first actual child booking to extract user info
                    $firstInner = null;
                    if (isset($first->bookings) && count($first->bookings) > 0) {
                        $firstInner = $first->bookings[0];
                    }

                    return [
                        'set_id' => $setId,
                        'total_bookings' => $bookings->count(),
                        'total_amount' => $bookings->sum('total_amount'),
                        'total_discount' => $bookings->sum('discount'),
                        'quantity' => $bookings->sum('quantity'),
                        // 'status' => $bookings->first()->status ?? 1,
                        'status' => $firstInner ? $firstInner->status : ($first->status ?? null),
                        'is_set' => true,
                        'bookings' => $bookings->values(),
                        // 'bookings.user.name'            => $firstInner->name,
                        'user' => [
                            'name' => $bookings
                                ->pluck('user.name')
                                ->filter()
                                ->first() ?? (optional($firstInner)->name ?? $first->name ?? ''),

                            'number' => $bookings
                                ->pluck('user.number')
                                ->filter()
                                ->first() ?? (optional($firstInner)->number ?? $first->number ?? ''),

                            'email' => $bookings
                                ->pluck('user.email')
                                ->filter()
                                ->first() ?? (optional($firstInner)->email ?? $first->email ?? ''),
                        ],

                        'number'          => optional($firstInner)->number ?? $first->number ?? '',
                        'created_at' => optional($firstInner)->created_at ?? $first->created_at,
                        'email'           => optional($firstInner)->email ?? $first->email ?? '',
                        'payment_method'  => optional($firstInner)->payment_method ?? $first->payment_method ?? '',
                        'event_name'      => optional($firstInner)->event_name ?? $first->event_name ?? '',
                        'organizer'       => optional($firstInner)->organizer ?? $first->organizer ?? '',
                        'agent_name'      => optional(optional($firstInner)->agentUser)->name ?? $first->agent_name ?? '',
                        'ticket' => [
                            'name' => $bookings
                                ->pluck('ticket.name')
                                ->filter()
                                ->first() ?? (optional(optional($firstInner)->ticket)->name ?? optional(optional($first)->ticket)->name ?? ''),
                        ]
                    ];
                } else {
                    // Normal single booking
                    $single = $bookings->first();
                    $single->is_set = false;
                    return $single;
                }
            })->values();

            // ---- Apply search, pagination, and permission-based masking (same pattern as BookingController lists) ----

            // Search on the final grouped collection to avoid changing core query logic
            $filtered = $grouped;
            if ($search !== '') {
                $needle = mb_strtolower($search);

                $filtered = $filtered->filter(function ($item) use ($needle) {
                    // Master/set items are arrays, normal bookings are Eloquent models
                    if (is_array($item)) {
                        $haystack = [
                            $item['set_id'] ?? null,
                            $item['user']['name'] ?? null,
                            $item['user']['number'] ?? null,
                            $item['user']['email'] ?? null,
                            $item['event_name'] ?? null,
                            $item['organizer'] ?? null,
                            $item['agent_name'] ?? null,
                            $item['number'] ?? null,
                            $item['email'] ?? null,
                            $item['payment_method'] ?? null,
                        ];
                        // Include ticket names inside the set (for better search)
                        if (isset($item['ticket']['name'])) {
                            $haystack[] = $item['ticket']['name'];
                        }
                        if (isset($item['bookings'])) {
                            foreach ($item['bookings'] as $booking) {
                                // $booking is a Booking model
                                $haystack[] = optional($booking->ticket)->name;
                                $haystack[] = $booking->payment_method ?? null;
                            }
                        }
                        foreach ($haystack as $value) {
                            if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                                return true;
                            }
                        }
                        return false;
                    }

                    // Single booking model
                    $haystack = [
                        $item->name ?? null,
                        optional($item->ticket)->name,
                        $item->number ?? null,
                        $item->email ?? null,
                        $item->event_name ?? null,
                        $item->organizer ?? null,
                        $item->agent_name ?? null,
                        $item->payment_method ?? null,
                        optional($item->user)->name,
                        optional($item->user)->number,
                        optional($item->user)->email,
                    ];

                    foreach ($haystack as $value) {
                        if ($value !== null && mb_stripos((string) $value, $needle) !== false) {
                            return true;
                        }
                    }

                    return false;
                });
            }

            // Sort (same behavior as before)
            $sorted = $filtered->sortByDesc(function ($b) {
                return $b['bookings'][0]['created_at'] ?? $b['created_at'] ?? now();
            })->values();

            // Pagination over the in-memory collection
            $total = $sorted->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;

            $paginated = $sorted->slice($offset, $perPage)
                ->map(function ($item) use ($canViewUsername, $canViewContact) {
                    // Mask sensitive data according to permissions
                    if (is_array($item)) {
                        if (!$canViewUsername) {
                            if (isset($item['user']['name'])) {
                                $item['user']['name'] = null;
                            }
                        }
                        if (!$canViewContact) {
                            if (isset($item['user']['number'])) {
                                $item['user']['number'] = null;
                            }
                            if (isset($item['number'])) {
                                $item['number'] = null;
                            }
                        }
                        return $item;
                    }

                    if (!$canViewUsername) {
                        $item->name = null;
                        if ($item->user) {
                            $item->user->name = null;
                        }
                    }

                    if (!$canViewContact) {
                        $item->number = null;
                        if ($item->user) {
                            $item->user->number = null;
                        }
                    }

                    return $item;
                })
                ->values();

            return response()->json([
                'status' => true,
                'bookings' => $paginated,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage() . " on line " . $e->getLine(),
            ], 500);
        }
    }

    public function getUserBookings(Request $request, $userId)
    {
        // Pagination params
        $perPage = min($request->input('per_page', 10), 100);
        $page = (int) $request->input('page', 1);

        $Masterbookings = MasterBooking::where('user_id', $userId)
            ->latest()
            ->get();
        $allBookingIds = [];
        $Masterbookings->each(function ($masterBooking) use (&$allBookingIds) {
            $allAttendees = [];
            $bookingIds = $masterBooking->booking_id;
            if (is_array($bookingIds)) {
                $allBookingIds = array_merge($allBookingIds, $bookingIds);
                $bookings = Booking::select([
                        'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                        'seat_name', 'token', 'total_amount', 'ticket_id', 'attendee_id', 
                        'created_at', 'deleted_at'
                    ])
                    ->whereIn('id', $bookingIds)
                    ->whereNull('deleted_at')
                    ->with([
                        'ticket:id,name,price,background_image,event_id',
                        'ticket.event:id,name,start_time,date_range',
                        'ticket.event.eventMedia:id,event_id,thumbnail',
                        'attendee'
                    ])
                    ->latest()
                    ->get()
                    ->map(function ($booking) {
                        $booking->is_deleted = $booking->trashed();
                        return $booking;
                    });

                $masterBooking->setRelation('bookings', $bookings);

                $bookings->each(function ($booking) use (&$allAttendees) {
                    if ($booking->attendee) {
                        $allAttendees[] = $booking->attendee;
                    }
                });
            } else {
                $masterBooking->setRelation('bookings', collect());
            }
            $masterBooking->attendees = $allAttendees;
        })->map(function ($booking) {
            $booking->is_deleted = $booking->trashed();
            $booking->type = 'MasterBooking';
            return $booking;
        });

        //agent bookings - now from unified bookings table
        $AgentMasterbookings = MasterBooking::where('user_id', $userId)
            ->where('booking_type', 'agent')
            ->latest()
            ->get();

        $allAgentBookingIds = [];
        $AgentMasterbookings->each(function ($masterBooking) use (&$allAgentBookingIds) {
            $bookingIds = $masterBooking->booking_id;
            if (is_array($bookingIds)) {
                $allAgentBookingIds = array_merge($allAgentBookingIds, $bookingIds);
                $bookings = Booking::select([
                        'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                        'seat_name', 'token', 'total_amount', 'ticket_id', 'created_at', 'deleted_at'
                    ])
                    ->whereIn('id', $bookingIds)
                    ->where('booking_type', 'agent')
                    ->whereNull('deleted_at')
                    ->with([
                        'ticket:id,name,price,background_image,event_id',
                        'ticket.event:id,name,start_time,date_range',
                        'ticket.event.eventMedia:id,event_id,thumbnail'
                    ])
                    ->latest()
                    ->get();
                $masterBooking->setRelation('bookings', $bookings);
            } else {
                $masterBooking->setRelation('bookings', collect());
            }
        })->map(function ($booking) {
            $booking->is_deleted = $booking->trashed();
            $booking->type = 'AgentMasterBooking';
            return $booking;
        });

        $normalAgentBookings = Booking::select([
                'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                'seat_name', 'token', 'total_amount', 'ticket_id', 'created_at', 'deleted_at'
            ])
            ->where('user_id', $userId)
            ->where('booking_type', 'agent')
            ->with([
                'ticket:id,name,price,background_image,event_id',
                'ticket.event:id,name,start_time,date_range',
                'ticket.event.eventMedia:id,event_id,thumbnail'
            ])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                $booking->type = 'Agent';
                return $booking;
            });

        //SponsorBooking - now from unified bookings table
        $SponsorMasterBooking = MasterBooking::where('user_id', $userId)
            ->where('booking_type', 'sponsor')
            ->latest()
            ->get();

        $allSponsorBookingIds = [];
        $SponsorMasterBooking->each(function ($masterBooking) use (&$allSponsorBookingIds) {
            $bookingIds = $masterBooking->booking_id;
            if (is_array($bookingIds)) {
                $allSponsorBookingIds = array_merge($allSponsorBookingIds, $bookingIds);
                $bookings = Booking::select([
                        'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                        'seat_name', 'token', 'total_amount', 'ticket_id', 'created_at', 'deleted_at'
                    ])
                    ->whereIn('id', $bookingIds)
                    ->where('booking_type', 'sponsor')
                    ->whereNull('deleted_at')
                    ->with([
                        'ticket:id,name,price,background_image,event_id',
                        'ticket.event:id,name,start_time,date_range',
                        'ticket.event.eventMedia:id,event_id,thumbnail'
                    ])
                    ->latest()
                    ->get();
                $masterBooking->setRelation('bookings', $bookings);
            } else {
                $masterBooking->setRelation('bookings', collect());
            }
        })->map(function ($booking) {
            $booking->is_deleted = $booking->trashed();
            $booking->type = 'SponsorMasterBooking';
            return $booking;
        });

        $normalSponsorBooking = Booking::select([
                'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                'seat_name', 'token', 'total_amount', 'ticket_id', 'created_at', 'deleted_at'
            ])
            ->where('user_id', $userId)
            ->where('booking_type', 'sponsor')
            ->with([
                'ticket:id,name,price,background_image,event_id',
                'ticket.event:id,name,start_time,date_range',
                'ticket.event.eventMedia:id,event_id,thumbnail'
            ])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                $booking->type = 'SponsorBooking';
                return $booking;
            });

        //BOOKING
        $normalBookings = Booking::select([
                'id', 'booking_type', 'discount', 'payment_method', 'quantity', 
                'seat_name', 'token', 'total_amount', 'ticket_id', 'attendee_id', 
                'created_at', 'deleted_at'
            ])
            ->where('user_id', $userId)
            ->with([
                'ticket:id,name,price,background_image,event_id',
                'ticket.event:id,name,start_time,date_range',
                'ticket.event.eventMedia:id,event_id,thumbnail',
                'attendee'
            ])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                $booking->type = 'Booking';
                return $booking;
            });

        $combinedBookings = $Masterbookings
            ->concat($normalBookings->filter(function ($booking) use ($allBookingIds) {
                return !in_array($booking->id, $allBookingIds);
            }))
            ->concat($AgentMasterbookings)
            ->concat($normalAgentBookings->filter(function ($booking) use ($allAgentBookingIds) {
                return !in_array($booking->id, $allAgentBookingIds);
            }))
            ->concat($SponsorMasterBooking)
            ->concat($normalSponsorBooking->filter(function ($booking) use ($allSponsorBookingIds) {
                return !in_array($booking->id, $allSponsorBookingIds);
            }))
            ->sortByDesc('created_at')
            ->values();

        // Pagination
        $total = $combinedBookings->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $paginated = $combinedBookings->slice($offset, $perPage)->values();

        return response()->json([
            'status' => true,
            'bookings' => $paginated,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ]
        ], 200);
    }

    public function agentBooking($id, MasterBookingService $masterBookingService, BookingCacheService $cacheService)
    {
        // Use cache service (Octane-compatible, no closures)
        $result = $cacheService->remember('agent_bookings', $id, function () use ($id, $masterBookingService) {
            return $masterBookingService->getBookingsByType('agent', $id);
        });

        return response()->json($result, $result['status'] ? 200 : 200);
    }
    public function sponsorBooking($id, MasterBookingService $masterBookingService, BookingCacheService $cacheService)
    {
        // Use cache service (Octane-compatible, no closures)
        $result = $cacheService->remember('sponsor_bookings', $id, function () use ($id, $masterBookingService) {
            return $masterBookingService->getBookingsByType('sponsor', $id);
        });

        return response()->json($result, $result['status'] ? 200 : 200);
    }

    public function accreditationBooking($id, MasterBookingService $masterBookingService, BookingCacheService $cacheService)
    {
        // Use cache service (Octane-compatible, no closures)
        $result = $cacheService->remember('accreditation_bookings', $id, function () use ($id, $masterBookingService) {
            return $masterBookingService->getBookingsByType('accreditation', $id);
        });

        return response()->json($result, $result['status'] ? 200 : 200);
    }

    public function pendingBookingList(Request $request, $id, PermissionService $permissionService, DateRangeService $dateRangeService)
    {
        try {
            $loggedInUser = Auth::user();
            $isAdmin = $loggedInUser->hasRole('Admin');

            // Pagination parameters
            $perPage = min($request->input('per_page', 10), 100);
            $page = (int) $request->input('page', 1);
            $search = trim($request->input('search', ''));

            // Permission checks
            $permissions = $permissionService->check(['View Username', 'View User Number']);
            $canViewUsername = $permissions['View Username'];
            $canViewContact = $permissions['View User Number'];

            // Handle date filtering
            $dateRange = $dateRangeService->parseDateRangeSafe($request);

            if (isset($dateRange['error'])) {
                return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
            }

            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];
            $searchTerm = $search !== '' ? "%{$search}%" : null;

            // For non-admin, get ticket IDs upfront
            $ticketIds = null;
            if (!$isAdmin) {
                $ticketIds = Ticket::whereIn('event_id', function ($q) use ($id) {
                    $q->select('id')->from('events')->where('user_id', $id);
                })->pluck('id')->toArray();
            }

            // 🔹 Master bookings query with eager loading
            $masterQuery = PenddingBookingsMaster::withTrashed()
                ->with(['paymentLog', 'user:id,name,number,email'])
                ->select(['id', 'user_id', 'booking_id', 'session_id', 'order_id', 'payment_id', 'amount', 'discount', 'deleted_at', 'created_at'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNull('deleted_at');

            // Apply search to master bookings
            if ($searchTerm) {
                $masterQuery->where(function ($q) use ($searchTerm) {
                    $q->where('session_id', 'ILIKE', $searchTerm)
                        ->orWhere('order_id', 'ILIKE', $searchTerm)
                        ->orWhereRaw('user_id IN (
                            SELECT id FROM users 
                            WHERE name ILIKE ? OR number::text ILIKE ? OR email ILIKE ?
                        )', [$searchTerm, $searchTerm, $searchTerm]);
                });
            }

            $Masterbookings = $masterQuery->latest()->get();

            // Extract all booking IDs efficiently
            $allBookingIds = $Masterbookings
                ->pluck('booking_id')
                ->flatMap(fn($ids) => is_array($ids) ? $ids : (is_string($ids) ? explode(',', $ids) : []))
                ->filter()
                ->map(fn($id) => (int) trim($id))
                ->unique()
                ->toArray();

            // 🔹 Pending bookings query with optimized eager loading
            $bookingQuery = PenddingBooking::withTrashed()
                ->with([
                    'ticket:id,name,event_id,price',
                    'ticket.event:id,name,user_id',
                    'ticket.event.user:id,name,organisation',
                    'user:id,name,number,email,photo,company_name',
                    'attendee:id,booking_id,Name,Mo,Email',
                    'paymentLog'
                ])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNull('deleted_at');

            // Apply search to normal bookings
            if ($searchTerm) {
                $bookingQuery->where(function ($q) use ($searchTerm) {
                    $q->where('pendding_bookings.name', 'ILIKE', $searchTerm)
                        ->orWhereRaw('pendding_bookings.number::text ILIKE ?', [$searchTerm])
                        ->orWhere('pendding_bookings.email', 'ILIKE', $searchTerm)
                        ->orWhere('pendding_bookings.session_id', 'ILIKE', $searchTerm)
                        // Combined subquery for ticket name OR event name
                        ->orWhereRaw('pendding_bookings.ticket_id::bigint IN (
                            SELECT t.id FROM tickets t
                            LEFT JOIN events e ON t.event_id = e.id
                            WHERE (t.name ILIKE ? OR e.name ILIKE ?)
                            AND t.deleted_at IS NULL
                        )', [$searchTerm, $searchTerm]);
                });
            }

            // Filter by organizer's tickets
            if ($ticketIds !== null && !empty($ticketIds)) {
                $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
                $bookingQuery->whereRaw("pendding_bookings.ticket_id::bigint IN ({$placeholders})", $ticketIds);
            }

            $bookings = $bookingQuery->get();
            $bookingsMap = $bookings->keyBy('id');

            // 🔹 Attach bookings to each MasterBooking
            $Masterbookings->each(function ($masterBooking) use ($bookingsMap) {
                $bookingIds = $masterBooking->booking_id;

                // Handle both array and string formats
                if (is_string($bookingIds)) {
                    $bookingIds = array_filter(array_map('trim', explode(',', $bookingIds)));
                } elseif (!is_array($bookingIds)) {
                    $bookingIds = [];
                }

                $masterBooking->bookings = collect();

                if (!empty($bookingIds)) {
                    $bookingsForMaster = collect($bookingIds)
                        ->map(fn($id) => $bookingsMap->get((int) $id))
                        ->filter();

                    $firstBooking = $bookingsForMaster->first();
                    $masterBooking->bookings = $bookingsForMaster->map(function ($booking) {
                        $booking->event_name = $booking->ticket->event->name ?? '';
                        $booking->organizer = $booking->ticket->event->user->organisation ?? '';
                        return $booking;
                    });
                    $masterBooking->payment_method = $firstBooking->payment_method ?? '';
                    $masterBooking->quantity = $bookingsForMaster->count();
                }

                $masterBooking->is_deleted = $masterBooking->trashed();
            });

            // 🔹 Normal bookings - use array_flip for O(1) lookup
            $masterBookingIdSet = array_flip($allBookingIds);
            $normalBookings = $bookings->reject(fn($booking) => isset($masterBookingIdSet[$booking->id]))
                ->map(function ($booking) {
                    $booking->event_name = $booking->ticket->event->name ?? '';
                    $booking->organizer = $booking->ticket->event->user->organisation ?? '';
                    $booking->is_deleted = $booking->trashed();
                    $booking->quantity = 1;
                    return $booking;
                });

            // 🔹 Combine and sort
            $combinedBookings = $Masterbookings->concat($normalBookings)
                ->sortByDesc('created_at')
                ->values();

            // 🔹 Pagination
            $total = $combinedBookings->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            $offset = ($page - 1) * $perPage;

            $paginatedBookings = $combinedBookings->slice($offset, $perPage)
                ->map(function ($booking) use ($canViewUsername, $canViewContact) {
                    if (!$canViewContact) {
                        $booking->number = null;
                    }

                    if ($booking->user) {
                        if (!$canViewUsername) {
                            $booking->user->name = null;
                            $booking->name = null;
                        }
                        if (!$canViewContact) {
                            $booking->number = null;
                            $booking->user->number = null;
                        }
                    }

                    return $booking;
                })
                ->values();

            return response()->json([
                'status' => true,
                'bookings' => $paginatedBookings,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => $lastPage,
                ]
            ]);
        } catch (Exception $e) {
            Log::error('PenddingBookingList Error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'error' => $e->getMessage() . ' on line ' . $e->getLine(),
            ], 500);
        }
    }

    public function sendBookingMail(Request $request)
    {
        $booking = $request->data;
        try {

            dispatch(new BookingMailJob($booking));

            return response()->json([
                'message' => 'Booking Email has been queued successfully.',
                'status' => true
            ], 200);
        } catch (Exception $e) {
            Log::error('Email sending failed', ['error' => $e->getMessage(), 'data' => $booking]);
            return response()->json([
                'message' => 'Failed to send email.',
                'status' => 'error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id, $token)
    {
        try {
            $Masterbookings = MasterBooking::where('id', $id)
                ->where('order_id', $token)
                ->latest()
                ->first();

            if ($Masterbookings) {
                $bookingIds = is_array($Masterbookings->booking_id)
                    ? $Masterbookings->booking_id
                    : json_decode($Masterbookings->booking_id, true);

                if (!empty($bookingIds) && is_array($bookingIds)) {
                    // 🔍 Check for any deleted tickets before deleting bookings
                    $relatedTickets = Ticket::withTrashed()
                        ->whereIn('id', Booking::whereIn('id', $bookingIds)->pluck('ticket_id'))
                        ->get();

                    if ($relatedTickets->contains(fn($ticket) => $ticket->trashed())) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Cannot delete — one or more related booking are already deleted.',
                        ], 200);
                    }

                    // ✅ Safe to delete all related bookings
                    Booking::whereIn('id', $bookingIds)->delete();
                }

                $Masterbookings->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Master Booking and related bookings deleted successfully.',
                ], 200);
            }

            // 🔍 Check if it's a normal single booking instead
            $normalBooking = Booking::where('id', $id)
                ->where('token', $token)
                ->first();

            if ($normalBooking) {
                $ticket = Ticket::withTrashed()->find($normalBooking->ticket_id);

                if ($ticket && $ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete — related booking is already deleted.',
                    ], 200);
                }

                $normalBooking->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Booking deleted successfully.',
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error deleting booking: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function restoreBooking($id, $token)
    {
        try {
            // 🔍 Step 1: Check for MasterBooking first
            $Masterbookings = MasterBooking::withTrashed()
                ->where('id', $id)
                ->where('order_id', $token)
                ->first();

            if ($Masterbookings) {
                $bookingIds = is_array($Masterbookings->booking_id)
                    ? $Masterbookings->booking_id
                    : json_decode($Masterbookings->booking_id, true);

                if (!empty($bookingIds) && is_array($bookingIds)) {
                    // 🟠 Check if any related tickets are deleted — prevent restore
                    $relatedTickets = Ticket::withTrashed()
                        ->whereIn('id', Booking::withTrashed()->whereIn('id', $bookingIds)->pluck('ticket_id'))
                        ->get();

                    if ($relatedTickets->contains(fn($ticket) => $ticket->trashed())) {
                        return response()->json([
                            'status' => false,
                            'message' => 'This booking event is disabled.',
                        ], 200);
                    }

                    // ✅ Restore related bookings
                    Booking::withTrashed()
                        ->whereIn('id', $bookingIds)
                        ->restore();
                }

                // ✅ Restore the MasterBooking
                $Masterbookings->restore();

                return response()->json([
                    'status' => true,
                    'message' => 'Master Booking and related bookings restored successfully.',
                ], 200);
            }

            // 🔍 Step 2: Handle normal booking
            $normalBooking = Booking::withTrashed()
                ->where('id', $id)
                ->where('token', $token)
                ->latest()
                ->first();

            if ($normalBooking) {
                $ticket = Ticket::withTrashed()->find($normalBooking->ticket_id);

                if ($ticket && $ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This booking event is disabled.',
                    ], 400);
                }

                $normalBooking->restore();

                return response()->json([
                    'status' => true,
                    'message' => 'Booking restored successfully.',
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'Booking not found.',
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error restoring booking: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function export(Request $request)
    {
        $Attendee = $request->input('user_id');
        $eventName = $request->input('ticket_id');
        $status = $request->input('status');
        $dates = $request->input('date') ? explode(',', $request->input('date')) : [Carbon::today()->format('Y-m-d')];

        $query = Booking::query();

        if ($request->has('ticket_id')) {
            $query->where('ticket_id', $eventName);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $Attendee);
        }

        if ($request->has('status')) {
            $query->where('status', $status);
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

        $bookings = $query->with(['userData', 'ticket.event.user'])->get();

        // ✅ Group by session_id and count qty
        $groupedBookings = $bookings->groupBy('session_id')->map(function ($group) {
            $first = $group->first();

            return [
                'event_name' => $first->ticket->event->name ?? 'N/A',
                'org_name' => $first->ticket->event->user->organisation ?? 'N/A',
                'attendee' => $first->userData->name ?? 'No User',
                'number' => $first->number ?? '',
                'ticket_name' => $first->ticket->name ?? '',
                'quantity' => $group->count(), // ✅ Qty = records with same session_id
                'discount' => $first->discount ?? 0,
                'base_amount' => $first->base_amount ?? 0,
                'amount' => $first->amount ?? 0,
                'status' => $first->status,
                'disabled' => $first->disabled,
                'created_at' => $first->created_at
            ];
        })->values();

        return Excel::download(new BookingExport($groupedBookings), 'Booking_export.xlsx');
    }

    public function retrieveImage(Request $request)
    {
        $fullImagePath = $request->input('path');

        if (!$fullImagePath) {
            return response()->json(['error' => 'No image path provided'], 400);
        }

        $parsedUrl = parse_url($fullImagePath);
        if (isset($parsedUrl['host']) && $parsedUrl['host'] === parse_url(url('/'), PHP_URL_HOST)) {
            $relativePath = $parsedUrl['path'];
        } elseif (str_starts_with($fullImagePath, url('/'))) {
            $relativePath = str_replace(url('/'), '', $fullImagePath);
        } else {
            $relativePath = $fullImagePath;
        }

        $relativePath = urldecode(ltrim($relativePath, '/'));
        $absolutePath = public_path(ltrim($relativePath, '/'));

        if (!file_exists($absolutePath)) {
            return response()->json([
                'error' => 'Image not found',
                'path' => $absolutePath,
                'original_path' => $fullImagePath
            ], 404);
        }

        try {
            $fileContents = file_get_contents($absolutePath);
            $mimeType = mime_content_type($absolutePath);

            return response($fileContents, 200)
                ->header('Content-Type', $mimeType);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve image',
                'message' => $e->getMessage(),
                'path' => $absolutePath
            ], 500);
        }
    }

    // Backward compatibility aliases
    public function imagesRetrive(Request $request)
    {
        return $this->retrieveImage($request);
    }

    public function userImagesRetrive(Request $request)
    {
        return $this->retrieveImage($request);
    }

    //penddingBookingConform
    public function pendingBookingConform($id)
    {
        $status = 'success';
        $decryptedSessionId = $id;
        $bookings = PenddingBooking::where('session_id', $decryptedSessionId)->with('paymentLog')->get();
        $bookingMaster = PenddingBookingsMaster::where('session_id', $decryptedSessionId)->with('paymentLog')->get();
        $masterBookingIDs = [];

        if ($bookings->isNotEmpty()) {
            foreach ($bookings as $individualBooking) {
                if ($status) {
                    $data = $individualBooking;
                    $booking = $this->bookingData($data);

                    if ($booking) {
                        $masterBookingIDs[] = $booking->id;
                        $individualBooking->delete();
                    }
                }
            }

            // ✅ Send SMS/WhatsApp for single booking (no master) - Using Job
            if ($bookingMaster->isEmpty() && !empty($masterBookingIDs)) {
                SendBookingAlertJob::dispatch($masterBookingIDs, 'online');
            }
        }

        if ($bookingMaster->isNotEmpty()) {
            if ($status) {
                $updated = $this->updateMasterBooking($bookingMaster, $masterBookingIDs);
                if ($updated) {
                    $bookingMaster->each->delete();
                }
            }
        }

        return response()->json(['status' => true], 200);
    }

    private function bookingData($data)
    {
        $booking = new Booking();
        $booking->ticket_id = $data->ticket_id;
        $booking->user_id = $data->user_id;
        $booking->session_id = $data->session_id;
        $booking->promocode_id = $data->promocode_id;
        $booking->token = $data->token;
        $booking->amount = $data->amount;
        $booking->email = $data->email;
        $booking->name = $data->name;
        $booking->number = $data->number;
        $booking->type = $data->type;
        $booking->dates = $data->dates;
        $booking->payment_method = $data->payment_method;
        $booking->discount = $data->discount;
        $booking->status = $data->status = 0;
        $booking->payment_status = 1;
        $booking->txnid = $data->txnid;
        $booking->device = $data->device;
        $booking->base_amount = $data->base_amount;
        $booking->convenience_fee = $data->convenience_fee;
        $booking->attendee_id = $data->attendee_id;
        $booking->total_tax = $data->total_tax;
        $booking->gateway = $data->gateway;
        $booking->payment_id = optional($data->paymentLog)->payment_id;
        $booking->save();

        if (isset($booking->promocode_id)) {
            $promocode = Promocode::where('code', $booking->promocode_id)->first();

            if (!$promocode) {
                return response()->json(['status' => false, 'message' => 'Invalid promocode'], 400);
            }

            // Initialize remaining_count based on usage_limit if it hasn't been set yet
            if ($promocode->remaining_count === null) {
                // First time use: set remaining_count to usage_limit - 1
                $promocode->remaining_count = $promocode->usage_limit - 1;
            } elseif ($promocode->remaining_count > null) {
                // Decrease remaining_count on subsequent uses
                $promocode->remaining_count--;
            } else {
                return response()->json(['status' => false, 'message' => 'Promocode usage limit reached'], 400);
            }

            // Assign promocode_id to booking
            if (isset($booking->promocode_id)) {
                $booking->promocode_id = $booking->promocode_id;
            }

            // Save updated promocode details
            $promocode->save();
        }
        // Removed individual SMS send from here
        return $booking;
    }

    private function updateMasterBooking($bookingMaster, $ids)
    {
        foreach ($bookingMaster as $entry) {
            $data = [
                'user_id' => $entry->user_id,
                'session_id' => $entry->session_id,
                'booking_id' => $ids,
                'order_id' => $entry->order_id,
                'amount' => $entry->amount,
                'discount' => $entry->discount,
                'payment_method' => $entry->payment_method,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $master = MasterBooking::create($data);

            if ($master) {
                // ✅ Send SMS/WhatsApp notifications using Job (async)
                if (!empty($ids)) {
                    SendBookingAlertJob::dispatch($ids, 'online');
                }
            } else {
                return false;
            }
        }

        return true;
    }

    public function boxOfficeBooking($number)
    {
        $allAttendees = [];

        // 1. ONLINE BOOKINGS
        $bookings = Booking::where('number', $number)
            ->whereNull('deleted_at')
            ->with(['ticket.event.user', 'ticket.event.Category', 'user', 'attendee'])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                return $booking;
            });

        // 2. MASTER BOOKINGS (linked by session_id)
        $masterBookingIds = $bookings->pluck('session_id')->filter()->unique();

        $Masterbookings = $masterBookingIds->isNotEmpty()
            ? MasterBooking::whereIn('session_id', $masterBookingIds)->get()
            : collect();

        // 3. COMPLIMENTARY BOOKINGS
        $complimentaryBookings = ComplimentaryBookings::where('number', $number)
            ->with(['ticket.event.user', 'ticket.event.Category', 'user'])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                return $booking;
            });

        // 4. AGENT BOOKINGS
        $agentBookings = Booking::where('number', $number)
            ->where('booking_type', 'agent')
            ->with(['ticket.event.user', 'ticket.event.Category', 'user', 'attendee'])
            ->latest()
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                return $booking;
            });

        // 5. CHECK IF ALL EMPTY
        if (
            $bookings->isEmpty() &&
            $complimentaryBookings->isEmpty() &&
            $Masterbookings->isEmpty() &&
            $agentBookings->isEmpty()
        ) {
            return response()->json([
                'status' => false,
                'message' => 'No bookings found for this mobile number.'
            ], 404);
        }

        // 6. FINAL RESPONSE
        return response()->json([
            'status' => true,
            'bookings' => $bookings,
            'master_bookings' => $Masterbookings,
            'complimentary_bookings' => $complimentaryBookings,
            'agent_bookings' => $agentBookings,
        ], 200);
    }

    protected function parseDomainFromReferer(?string $referer): ?string
    {
        if (!$referer)
            return null;
        $parsed = parse_url($referer);
        return $parsed['host'] ?? null;
    }

    public function verifyBooking(Request $request)
    {
        try {
            $decryptedSessionId = $request->session_id;

            if (!$decryptedSessionId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid session ID or booking not found.'
                ], 400);
            }

            // Step 1: Fetch booking data
            $result = $this->verifyBookingData($decryptedSessionId);
            $bookingData = $result['bookings'];
            $isMaster = $result['isMaster'];

            // Step 2: Calculate total total_amount
            $totalAmount = $isMaster
                ? collect($bookingData->bookings)->sum('total_amount')
                : floatval($bookingData->total_amount);

            // ✅ Step 2.5: Fetch tax data
            $taxData = null;
            if ($isMaster) {
                $masterBookingId = $bookingData->id ?? null;
                if ($masterBookingId) {
                    $taxData = BookingTax::where('booking_id', $masterBookingId)
                        ->where('type', 'online_master')
                        ->first();
                }
            } else {
                $singleBookingId = $bookingData->id ?? null;
                if ($singleBookingId) {
                    $taxData = BookingTax::where('booking_id', $singleBookingId)
                        ->where('type', 'online')
                        ->first();
                }
            }

            // Step 3: If amount is 0, return directly
            if ($totalAmount <= 0) {

                $attendees = collect($isMaster ? $bookingData->bookings : [$bookingData])
                    ->pluck('attendee')
                    ->filter()
                    ->map(fn($a) => collect($a)->only(['id', 'user_id', 'Name', 'Mo', 'Photo', 'Email']))
                    ->values();

                $booking = $isMaster ? $bookingData->bookings : collect([$bookingData]);
                // $booking = $isMaster ? $bookingData->bookings->first() : $bookingData;

                // Ticket
                $ticketObj = $isMaster
                    ? collect($bookingData->bookings)->pluck('ticket')->filter()->first()
                    : $bookingData->ticket;

                $ticket = $ticketObj
                    ? collect($ticketObj)->only(['id', 'name', 'event_id', 'price', 'sale_price', 'currency', 'background_image'])
                    : null;

                // Event
                $eventObj = $ticketObj->event ?? null;
                $event = null;
                if ($eventObj) {
                    $venue = $eventObj->venue ?? null;
                    $event = collect($eventObj)->only([
                        'id',
                        'name',
                        'start_time',
                        'end_time',
                        'date_range'
                    ]);

                    if ($venue) {
                        $event = $event->merge([
                            'address' => $venue->address ?? null,
                            'city'    => $venue->city ?? null,
                        ]);
                    }
                }

                // User
                $userObj = $isMaster
                    ? collect($bookingData->bookings)->pluck('user')->filter()->first()
                    : $bookingData->user;

                $user = $userObj ? collect($userObj)->only(['id', 'name', 'number', 'email']) : null;

                // Clean bookings (remove attendee, ticket, user)
                $cleanBooking = $booking->map(fn($b) => collect($b)->except(['attendee', 'ticket', 'user']));
                // $cleanBooking = collect($booking)->except(['attendee', 'ticket', 'user']);

                return response()->json([
                    'status' => true,
                    // 'bookings' => $isMaster ? array_merge($bookingData->toArray(), [
                    //     'bookings' => $cleanBooking
                    // ]) : $cleanBooking,
                    'bookings' => $bookingData,
                    // ? array_merge($bookingData->toArray(), ['bookings' => $cleanBooking])
                    // : $cleanBooking->first(),
                    'attendee' => $attendees ?? null,
                    'ticket' => $ticket,
                    'event' => $event,
                    'user' => $user,
                    'isMaster' => $isMaster,
                    'taxes' => $taxData ? [
                        'base_amount' => $taxData->base_amount ?? 0,
                        'discount' => $taxData->discount ?? 0,
                        'central_gst' => $taxData->central_gst ?? 0,
                        'state_gst' => $taxData->state_gst ?? 0,
                        'total_tax' => $taxData->total_tax ?? 0,
                        'convenience_fee' => $taxData->convenience_fee ?? 0,
                        'final_amount' => $taxData->final_amount ?? 0,
                    ] : null
                ], 200);
            }

            // Step 4: If amount > 0, check payment log
            $paymentLog = PaymentLog::where('session_id', $decryptedSessionId)->first();

            if (!$paymentLog) {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment log not found.'
                ], 404);
            }

            $status = strtolower(trim($paymentLog->status));
            $successStatuses = ['success', 'credit', 'completed', 'paid'];
            $failureStatuses = ['failed', 'failure', 'error', 'cancelled', 'declined'];

            if (in_array($status, $successStatuses)) {
                $status = 'success';
            } elseif (in_array($status, $failureStatuses)) {
                $status = 'failed';
            }

            if ($status != 'success') {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment Failed.'
                ], 400);
            }

            if ($isMaster) {
                foreach ($bookingData->bookings as $b) {
                    if ($b->ticket) {
                        $ticket = $b->ticket;
                        $quantity = (int) $b->quantity ?? 1;

                        $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
                        $newRemaining = max(0, $newRemaining - $quantity);

                        $ticket->remaining_count = $newRemaining;
                        $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
                        $ticket->save();
                    }
                }
            } else {
                if ($bookingData->ticket) {
                    $ticket = $bookingData->ticket;
                    $quantity = (int) $bookingData->quantity ?? 1;

                    $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
                    $newRemaining = max(0, $newRemaining - $quantity);

                    $ticket->remaining_count = $newRemaining;
                    $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
                    $ticket->save();
                }
            }

            // Step 5: All ok, return single object booking
            $finalBooking = $isMaster ? $bookingData : $bookingData;

            return response()->json([
                'status' => true,
                'bookings' => $finalBooking,
                'isMaster' => $isMaster,
                'payment_id' => $paymentLog->payment_id,
                'taxes' => $taxData ? [
                    'base_amount' => $taxData->base_amount ?? 0,
                    'discount' => $taxData->discount ?? 0,
                    'central_gst' => $taxData->central_gst ?? 0,
                    'state_gst' => $taxData->state_gst ?? 0,
                    'total_tax' => $taxData->total_tax ?? 0,
                    'convenience_fee' => $taxData->convenience_fee ?? 0,
                    'final_amount' => $taxData->final_amount ?? 0,
                ] : null
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to verify booking.',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    private function verifyBookingData($decryptedSessionId)
    {
        $master = MasterBooking::where('session_id', $decryptedSessionId)->first();

        if ($master) {
            $bookingIds = $master->booking_id;
            $bookingIds = is_string($bookingIds)
                ? explode(',', str_replace('"', '', $bookingIds))
                : (array) $bookingIds;

            $master->bookings = !empty($bookingIds)
                ? Booking::whereIn('id', $bookingIds)
                ->with(['ticket:id,background_image,event_id', 'ticket.event.user', 'user', 'attendee'])
                ->latest()
                ->get()
                : collect();
            //return $master;
            return [
                'status' => true,
                'bookings' => $master,
                'isMaster' => true
            ];
        }

        $booking = Booking::with(['ticket.event.user', 'attendee', 'user'])
            ->where('session_id', $decryptedSessionId)
            ->first();

        return [
            'bookings' => $booking,
            'isMaster' => false
        ];
    }

    public function bookingStats($type, $id)
    {
        try {
            $user = Auth::user(); // 🔹 Current login user

            if ($type === 'agent') {
                if ($user->user_type === 'Admin') {
                    // 🔹 Admin ne badha agent bookings
                    $query = Booking::where('booking_type', 'agent');
                } else {
                    // 🔹 Normal user ne potana id ni booking
                    $query = Booking::where('booking_type', 'agent')
                        ->where('booking_by', $id);
                }

                // 🔹 Aggregates (for agent)
                $totalBookings = $query->count();
                $totalAmount   = $query->sum('total_amount');
                $totalDiscount = $query->sum('discount');
            } elseif ($type === 'pos') {
                if ($user->user_type === 'Admin') {
                    // 🔹 Admin ne badha POS bookings
                    $query = PosBooking::query();
                } else {
                    // 🔹 Normal user ne potana POS bookings
                    $query = PosBooking::where('user_id', $id);
                }

                // 🔹 Aggregates (for POS)
                $totalBookings = $query->count();
                $totalAmount   = $query->sum('amount');
                $totalDiscount = $query->sum('discount');
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid type. Allowed types: agent, pos',
                ], 400);
            }

            // ✅ Response
            return response()->json([
                'status'         => true,
                'id'             => $id,
                'bookings'       => $totalBookings,
                'amount'         => round($totalAmount, 2),
                'discount'       => round($totalDiscount, 2),
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function BookingSummary($type, Request $request, DateRangeService $dateRangeService)
    {
        $user = auth()->user();

        // Parse date range using DateRangeService
        $dateRange = $dateRangeService->parseDateRangeSafe($request);

        if (isset($dateRange['error'])) {
            return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
        }

        $startDate = $dateRange['startDate'];
        $endDate = $dateRange['endDate'];

        // Use cache tags for better memory management in Octane
        $cacheKey = "dashboard_summary_{$type}_{$user->id}_{$startDate}_{$endDate}";

        $data = Cache::tags(['dashboard', "user:{$user->id}"])
            ->flexible($cacheKey, [30, 60], function () use ($user, $type, $startDate, $endDate) {
                return $this->statsService->getSummaryData($user, $type, $startDate, $endDate);
            });

        if (isset($data['error'])) {
            return response()->json($data, 400);
        }

        $summary = $data['summary'] ?? [];
        unset($data['summary']);

        return (new DashboardSummaryResource($data))->additional([
            'status' => true,
            'user_role' => $user->getRoleNames()->first(),
            'user_id' => $user->id,
            'organisation' => $user->organisation ?? null,
            'type' => ucfirst($type),
            'summary' => $summary,
        ]);
    }

    public function eventWiseTicketSales(Request $request, DateRangeService $dateRangeService, $type = 'online')
    {
        $user = Auth::user();
        // return $user;
        $dateRange = $dateRangeService->parseDateRangeSafe($request);

        if (isset($dateRange['error'])) {
            return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
        }

        $startDate = $dateRange['startDate'];
        $endDate = $dateRange['endDate'];

        $data = $this->statsService->getEventWiseTicketSales($user, $type, $startDate, $endDate);

        if (isset($data['error'])) {
            return response()->json(['status' => false, 'message' => $data['error']], 400);
        }

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
}
