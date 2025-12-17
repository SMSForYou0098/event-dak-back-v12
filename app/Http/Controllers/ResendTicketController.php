<?php

namespace App\Http\Controllers;

use App\Models\MasterBooking;
use App\Models\WhatsappApi;
use App\Services\SmsService;
use App\Services\TicketMessageService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;

class ResendTicketController extends Controller
{
    public function resendTicket(Request $request)
    {
        try {
            $tableName = strtolower($request->table_name);
            $isMaster  = $request->is_master;
            $orderId   = $request->order_id;
            $setId     = $request->set_id;

            // 🧩 Always use Booking model for agent/sponsor/booking/masterbooking/online
            if (in_array($tableName, ['agent', 'sponsor', 'booking', 'masterbooking', 'online'])) {
                $modelClass = "\\App\\Models\\Booking";
            } elseif ($tableName === 'online_master') {
                $modelClass = MasterBooking::class;
            } else {
                $modelClass = "\\App\\Models\\" . ucfirst($tableName);
            }

            if (!class_exists($modelClass)) {
                return response()->json(['status' => false, 'message' => 'Invalid table name'], 400);
            }

            // 🟢 STEP 1: Fetch record(s)
               if ($isMaster || !empty($setId) || $tableName === 'online_master') {

            // Default query
            $query = $modelClass::query();

            // 🔹 For ONLINE master bookings → use MasterBooking and match by order_id
            if ($tableName === 'online_master') {
                $master = \App\Models\MasterBooking::where('order_id', $orderId)->first();

                if (!$master) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Master booking not found'
                    ], 404);
                }

                // 🔹 Fetch child bookings using set_id from MasterBooking
                $bookings = \App\Models\Booking::where('set_id', $master->set_id)->get();

                if ($bookings->isEmpty()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No child bookings found under this master booking'
                    ], 404);
                }

                $booking = $bookings->first();
            } else {
                // 🔹 Non-online master bookings → use set_id
                $query->where('set_id', $setId);

                // 🔹 If agent/sponsor/online → filter by booking_type
                if (in_array($tableName, ['agent', 'sponsor', 'online'])) {
                    $query->where('booking_type', $tableName);
                }

                $bookings = $query->get();
                $booking  = $bookings->first();
            }

        } else {
            // 🟣 For single bookings (non-master)
            $query = $modelClass::query()
                ->where(function ($q) use ($orderId) {
                    $q->where('token', $orderId)
                      ->orWhere('order_id', $orderId);
                });

            if (in_array($tableName, ['agent', 'sponsor', 'online'])) {
                $query->where('booking_type', $tableName);
            }

            $booking  = $query->first();
            $bookings = collect([$booking]);
        }

            // 🟡 Validation
            if (!$booking) {
                return response()->json([
                    'status' => false,
                    'debug' => [
                        'table_name' => $tableName,
                        'is_master' => $isMaster,
                        'order_id' => $orderId,
                        'set_id' => $setId,
                    ],
                    'message' => 'Booking not found',
                ], 404);
            }

            // 🟢 STEP 2: Get Event & Ticket
            $event  = $booking->ticket->event ?? null;
            $ticket = $booking->ticket ?? null;
          
            if (!$event || !$ticket) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event or ticket details missing'
                ], 404);
            }

            // 🕒 Format date range
            $dates = explode(',', $event->date_range);
            $formattedDates = collect($dates)
                ->map(fn($d) => \Carbon\Carbon::parse($d)->format('d-m-Y'))
                ->implode(' | ');
            $eventDateTime = "{$formattedDates} | {$event->start_time} - {$event->end_time}";

            // 🧾 Prepare message data
            $ticketMessageService = new TicketMessageService();
            $data = $ticketMessageService->prepareData(
                $tableName,
                $booking,
                $bookings,
                $event,
                $ticket,
                $orderId,
                $eventDateTime
            );

            // 📨 Send via SMS & WhatsApp
            (new SmsService)->send($data);
            (new WhatsappService)->send($data);

            return response()->json([
                'status' => true,
                'message' => 'Ticket resent successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
