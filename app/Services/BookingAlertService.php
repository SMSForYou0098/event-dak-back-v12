<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\WhatsappApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BookingAlertService
{
    protected $smsService;
    protected $whatsappService;

    public function __construct(SmsService $smsService, WhatsappService $whatsappService)
    {
        $this->smsService = $smsService;
        $this->whatsappService = $whatsappService;
    }

    /**
     * Send booking alerts (SMS + WhatsApp)
     * 
     * This method handles sending alerts for both Agent and POS bookings.
     * Called from SendBookingAlertJob to avoid blocking the main booking request.
     * 
     * @param array $bookingIds - IDs of bookings to send alerts for
     * @param string $bookingType - Type of booking (agent, pos, sponsor, etc.)
     * @return bool - Success status
     */
    public function sendBookingAlerts(array $bookingIds, string $bookingType = 'agent'): bool
    {
        try {
            if (empty($bookingIds)) {
                return false;
            }

            // Fetch the first booking with all related data
            $firstBooking = Booking::with('ticket.event')
                ->whereIn('id', $bookingIds)
                ->first();

            if (!$firstBooking) {
                return false;
            }

            // Get all bookings for this batch
            $bookings = Booking::whereIn('id', $bookingIds)
                ->get();

            $ticket = $firstBooking->ticket;
            $event = $ticket->event;

            // 🔥 Get WhatsApp Template
            $whatsappTemplate = WhatsappApi::where('title', 'Agent Booking')->first();
            $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

            // 🔥 Determine Order ID (using perfect logic)
            $orderId = $this->resolveOrderId($firstBooking, $bookings);

            // 🔥 Build short link
            $shortLink = "t.getyourticket.in/t/{$orderId}";

            // 🔥 Format dates
            $dates = explode(',', $event->date_range);
            $formattedDates = array_map(
                fn($d) => Carbon::parse($d)->format('d-m-Y'),
                $dates
            );
            $eventDateTime = implode(' | ', $formattedDates) . ' | ' . $event->start_time . ' - ' . $event->end_time;

            // 🔥 Build ticket summary
            $ticketSummary = $bookings
                ->groupBy('ticket_id')
                ->map(function ($items) {
                    $ticketName = $items->first()->ticket->name ?? 'Unknown Ticket';
                    $qty = $items->count();
                    return "{$ticketName} x{$qty}";
                })
                ->implode(' | ');

            // 🔥 Prepare alert data
            $alertData = (object) [
                'name' => $firstBooking->name,
                'number' => $firstBooking->number,
                'templateName' => 'Agent Booking Template',
                'whatsappTemplateData' => $whatsappTemplateName,
                'shortLink' => $orderId,
                'insta_whts_url' => $event->insta_whts_url ?? 'helloinsta',
                'mediaurl' => $event->eventMedia->thumbnail ?? null,
                'values' => [
                    $firstBooking->name,
                    $firstBooking->number,
                    $event->name,
                    count($bookings),
                    $ticketSummary,
                    $event->venue->address ?? 'TBD',
                    $eventDateTime,
                    $event->whts_note ?? 'Welcome!',
                ],
                'replacements' => [
                    ':C_Name' => $firstBooking->name,
                    ':T_QTY' => count($bookings),
                    ':Ticket_Name' => $ticketSummary,
                    ':Event_Name' => $event->name,
                    ':Event_Date' => $eventDateTime,
                    ':S_Link' => $shortLink,
                ],
            ];

            // 🔥 Send alerts (non-blocking in job context)
            $this->smsService->send($alertData);
            $this->whatsappService->send($alertData);

            return true;
        } catch (\Exception $e) {
            Log::error('BookingAlertService Error: ' . $e->getMessage(), [
                'booking_ids' => $bookingIds,
                'booking_type' => $bookingType
            ]);
            return false;
        }
    }

    /**
     * Resolve the Order ID using perfect logic
     * 
     * Priority:
     * 1. If master_token exists → use master_token
     * 2. If multiple different ticket_ids in same set → use set_id
     * 3. Otherwise → use individual token
     * 
     * @param Booking $firstBooking
     * @param \Illuminate\Database\Eloquent\Collection $bookings
     * @return string
     */
    private function resolveOrderId(Booking $firstBooking, $bookings): string
    {
        // Default: token
        $orderId = $firstBooking->token;

        // Case 1 → master_token exists: ALWAYS use master_token
        if (!empty($firstBooking->master_token)) {
            $orderId = $firstBooking->master_token;
        }
        // Case 2 → multiple ticket_ids inside same set → use set_id
        else {
            $bookingsWithSameSetId = $bookings->where('set_id', $firstBooking->set_id);
            $uniqueTicketIds = $bookingsWithSameSetId->pluck('ticket_id')->unique();

            if (!empty($firstBooking->set_id) && $uniqueTicketIds->count() > 1) {
                $orderId = $firstBooking->set_id;
            }
        }

        return $orderId;
    }

    /**
     * Send alert for a single booking (POS style)
     * 
     * @param int $bookingId
     * @return bool
     */
    public function sendSingleBookingAlert(int $bookingId): bool
    {
        return $this->sendBookingAlerts([$bookingId], 'pos');
    }
}
