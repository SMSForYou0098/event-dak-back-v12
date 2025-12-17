<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\MasterBooking;
use App\Models\Event;
use App\Models\Ticket;
use App\Repositories\BookingRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class MasterBookingService
{
    public function __construct(
        private BookingRepository $bookingRepository
    ) {}

    /**
     * Get bookings for a specific type (agent, sponsor, accreditation, online, complimentary)
     * All these types are now in the unified 'bookings' table
     */
    public function getBookingsByType(string $type, int $id): array
    {
        $user = Auth::user();
        $isOrganizer = $user->hasRole('Organizer');
        $isAdmin = $user->hasRole('Admin');

        // Get organizer's ticket IDs if needed
        $ticketIds = null;
        if ($isOrganizer) {
            $eventIds = Event::where('user_id', $user->id)->pluck('id');
            $ticketIds = Ticket::whereIn('event_id', $eventIds)->pluck('id')->toArray();
        }

        // Build query for bookings from unified table
        $query = Booking::withTrashed()
            ->latest()
            ->with('ticket')
            ->where('booking_type', $type);

        // Apply role-based filtering
        if ($isAdmin) {
            // Admin sees all bookings of this type
        } elseif ($isOrganizer && $ticketIds) {
            // Organizer sees bookings for their events
            $query->whereIn('ticket_id', $ticketIds);
        } else {
            // Agent/Sponsor sees only their own bookings
            $query->where('booking_by', $id);
        }

        $allBookings = $query->get();

        // Get master bookings for this type
        $masterQuery = MasterBooking::withTrashed()
            ->latest()
            ->where('booking_type', $type);

        if ($isAdmin) {
            // Admin sees all
        } elseif ($isOrganizer && $ticketIds) {
            // Filter master bookings by checking if any child booking belongs to organizer
            // This is done after fetching for simplicity
        } else {
            $masterQuery->where('booking_by', $id);
        }

        $masterBookings = $masterQuery->get();

        // Process master booking data using repository
        $data = $this->bookingRepository->processMasterBookingData($allBookings, $masterBookings);

        if ($data['bookings']->isNotEmpty()) {
            return [
                'status' => true,
                'bookings' => $data['bookings'],
                'amount' => $data['amount'],
                'discount' => $data['discount'],
                'allbookings' => $data['allBookings'],
            ];
        }

        return [
            'status' => false,
            'message' => 'No Bookings Found',
        ];
    }

    /**
     * Process master bookings and attach related bookings
     */
    public function processMasterBookings(
        Collection $masterBookings,
        Collection $allBookings
    ): Collection {
        $bookingsMap = $allBookings->keyBy('id');

        return $masterBookings->map(function ($master) use ($bookingsMap) {
            $ids = is_array($master->booking_id) ? $master->booking_id : [];

            $bookings = collect($ids)
                ->map(fn($id) => $bookingsMap->get($id))
                ->filter()
                ->values();

            $firstBooking = $bookings->first();

            if ($firstBooking) {
                $master->status = $firstBooking->status;
                $master->agent_name = $firstBooking->agentUser->name ?? '';
                $master->event_name = $firstBooking->ticket->event->name ?? '';
                $master->organizer = $firstBooking->ticket->event->user->name ?? '';
            }

            $master->bookings = $bookings;
            $master->is_deleted = !is_null($master->deleted_at);
            $master->quantity = $bookings->count();
            $master->is_master = true;

            return $master;
        });
    }

    /**
     * Group bookings by set_id for master booking display
     */
    public function groupBySetId(Collection $bookings): Collection
    {
        return $bookings->groupBy('set_id')->map(function ($bookings, $setId) {
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
                return $this->createMasterBookingGroup($bookings, $setId);
            }

            // Normal single booking
            $single = $bookings->first();
            $single->is_set = false;
            return $single;
        })->values();
    }

    /**
     * Create a master booking group structure
     */
    private function createMasterBookingGroup(Collection $bookings, string $setId): array
    {
        $first = $bookings->first();
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
            'status' => $firstInner ? $firstInner->status : ($first->status ?? null),
            'is_set' => true,
            'bookings' => $bookings->values(),
            'user' => [
                'name' => $bookings->pluck('user.name')->filter()->first()
                    ?? ($firstInner->name ?? $first->name ?? ''),
                'number' => $bookings->pluck('user.number')->filter()->first()
                    ?? ($firstInner->number ?? $first->number ?? ''),
                'email' => $bookings->pluck('user.email')->filter()->first()
                    ?? ($firstInner->email ?? $first->email ?? ''),
            ],
            'number' => $firstInner->number ?? $first->number ?? '',
            'created_at' => $firstInner->created_at ?? $first->created_at ?? null,
            'email' => $firstInner->email ?? $first->email ?? '',
            'payment_method' => $firstInner->payment_method ?? $first->payment_method ?? '',
            'event_name' => $firstInner->event_name ?? $first->event_name ?? '',
            'organizer' => $firstInner->organizer ?? $first->organizer ?? '',
            'agent_name' => $firstInner->agentUser->name ?? $first->agent_name ?? '',
            'ticket' => [
                'name' => $bookings->pluck('ticket.name')->filter()->first()
                    ?? ($firstInner->ticket->name ?? $first->ticket->name ?? ''),
            ],
        ];
    }

    /**
     * Calculate totals from a collection of bookings
     */
    public function calculateTotals(Collection $bookings): array
    {
        return [
            'total_amount' => $bookings->sum('total_amount'),
            'total_discount' => $bookings->sum('discount'),
            'total_quantity' => $bookings->sum('quantity'),
            'count' => $bookings->count(),
        ];
    }
}
