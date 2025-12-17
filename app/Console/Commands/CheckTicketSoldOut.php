<?php

namespace App\Console\Commands;


use Illuminate\Console\Command;
use App\Models\Ticket;
use App\Models\PosBooking;
use App\Models\Booking;
use App\Models\Event;

class CheckTicketSoldOut extends Command
{
    protected $signature = 'tickets:check-soldout';
    protected $description = 'Check if tickets are sold out based on total bookings from all sources';

    public function handle()
    {
        $tickets = Ticket::all();

        foreach ($tickets as $ticket) {
            $ticketQty = $ticket->quantity;

            $bookingCount = Booking::where('ticket_id', (string) $ticket->id)->sum('quantity');
            $posCount = PosBooking::where('ticket_id', (string) $ticket->id)->sum('quantity');

            $totalBooked = $bookingCount + $posCount;

            $ticket->sold_out = $totalBooked >= $ticketQty ? 1 : 0;
            $ticket->save();
        }

        $eventIds = Ticket::distinct()->pluck('event_id');

        foreach ($eventIds as $eventId) {
            $eventTickets = Ticket::where('event_id', $eventId)->get();

            $allSoldOut = $eventTickets->every(function ($ticket) {
                return $ticket->sold_out == 1;
            });

            $event = Event::find($eventId);
            if ($event) {
                $event->sold_out = $allSoldOut ? 1 : 0;
                $event->save();
            }
        }

        $this->info('Tickets and event statuses updated based on booking status.');
    }
}
