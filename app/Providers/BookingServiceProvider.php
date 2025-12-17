<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Booking;
use App\Models\ExhibitionBooking;
use App\Models\ComplimentaryBookings;
use App\Models\PosBooking;
use App\Models\MasterBooking;

class BookingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('BookingService', function ($app) {
            return new class {
                public function getBookingsByOrderId($orderId)
                {
                    return [
                        'Booking' => Booking::where('token', $orderId)
                            ->where('booking_type', 'online')
                            ->with(['ticket.event.user', 'attendee'])
                            ->first(),
                        'AgentBooking' => Booking::where('token', $orderId)
                            ->where('booking_type', 'agent')
                            ->with(['ticket.event.user', 'attendee'])
                            ->first(),
                        'ExhibitionBooking' => ExhibitionBooking::where('token', $orderId)
                            ->with(['ticket.event.user', 'attendee'])
                            ->first(),
                        'ComplimentaryBookings' => ComplimentaryBookings::where('token', $orderId)
                            ->with('ticket.event.user')
                            ->first(),
                        'PosBooking' => PosBooking::where('token', $orderId)
                            ->with('ticket.event.user')
                            ->first(),
                        'MasterBooking' => MasterBooking::where('order_id', $orderId)
                            ->where('booking_type', 'online')
                            ->first(),
                        'AgentMasterBooking' => MasterBooking::where('order_id', $orderId)
                            ->where('booking_type', 'agent')
                            ->first(),
                    ];
                }
            };
        });
    }

    public function boot()
    {
        // No additional setup required
    }
}
