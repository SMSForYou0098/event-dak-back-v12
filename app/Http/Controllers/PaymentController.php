<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\PaymentGatewayManager;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    protected $gatewayManager;

    public function __construct(PaymentGatewayManager $gatewayManager)
    {
        $this->gatewayManager = $gatewayManager;
    }

    public function processPayment(Request $request)
    {

        $organizerId = $request->organizer_id;
        if ($request->event_id) {
            $event = Event::where('event_key', $request->event_id)->first();

            if (!$event) {
                return response()->json(['status' => false, 'message' => 'Event not found'], 404);
            }

            // Check if the event is expired based on date_range

            $today = Carbon::today();
            $dateRange = explode(',', $event->date_range);

            if (count($dateRange) === 2) {
                $endDate = Carbon::parse($dateRange[1]);
            } else {
                $endDate = Carbon::parse($dateRange[0]);
            }

            if ($today->gt($endDate)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event has been expired'
                ], 419);
            }
        }

        if (!$organizerId) {
            return response()->json([
                'success' => false,
                'message' => 'Organizer ID is required.',
            ], 400);
        }


        $number = $request->user_phone ?? null;
        $newQty = $request->quantity ?? 0;
        $eventKey = $request->ticket_id ?? 0;

        if ($number && $eventKey) {
            $ticket = Ticket::find($eventKey);

            if ($ticket) {

                // ✅ Only block if remaining_count is defined and <= 0
                if (!is_null($ticket->remaining_count) && $ticket->remaining_count <= 0) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKETS_SOLD_OUT",
                        "message" => "Tickets are sold out."
                    ], 410);
                }

                // ✅ Only compare newQty if remaining_count is defined
                if (!is_null($ticket->remaining_count) && $newQty > $ticket->remaining_count) {
                    return response()->json([
                        'status' => false,
                        "warningCode" => "TICKET_LIMIT_REACHED",
                        'message' => "Ticket limit reached - just  {$ticket->remaining_count} tickets left.",
                    ], 409);
                }


                $userBookingLimit = $ticket->user_booking_limit;


                $totalBookedByUser = Booking::where('ticket_id', $ticket->id)
                    ->where('number', $number)
                    ->count();

                $data = Booking::where('ticket_id', $ticket->id)->where('number', $number)->get();

                $totalAfterNewBooking = $totalBookedByUser + $newQty;

                if ($userBookingLimit > 0 && $totalAfterNewBooking > $userBookingLimit) {
                    return response()->json([
                        'status' => false,
                        'message' => "You have reached the max limit.",
                    ], 403);
                }
            }
        }

        $ticketQty = $request->quantity ?? 0;
        if ($request->totalFinalAmount == "0" && $ticketQty > 0) {
            $gatewayController = app()->make(EasebuzzController::class);
            return app()->call([$gatewayController, 'initiatePayment'], ['request' => $request]);
        }

        $gatewayControllerClass = $this->gatewayManager->getNextGateway($organizerId);

        if (!$gatewayControllerClass) {
            return response()->json([
                'success' => false,
                'message' => 'No active payment gateway available for this organizer.',
            ], 503);
        }
        $gatewayController = app()->make($gatewayControllerClass);
        return app()->call([$gatewayController, 'initiatePayment'], ['request' => $request]);
    }
}
