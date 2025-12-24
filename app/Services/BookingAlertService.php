<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\WhatsappApi;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BookingAlertService
{
    protected $smsService;
    protected $whatsappService;
    protected $templateService;

    public function __construct(
        SmsService $smsService, 
        WhatsappService $whatsappService,
        TemplateReplacementService $templateService
    ) {
        $this->smsService = $smsService;
        $this->whatsappService = $whatsappService;
        $this->templateService = $templateService;
    }

    /**
     * Send booking alerts (SMS + WhatsApp)
     * 
     * This method handles sending alerts for both Agent and POS bookings.
     * Called from SendBookingAlertJob to avoid blocking the main booking request.
     * 
     * @param array|\Illuminate\Support\Collection $bookingIdsOrCollection - IDs of bookings OR collection of Booking models
     * @param string $bookingType - Type of booking (agent, pos, sponsor, etc.)
     * @return bool - Success status
     */
    public function sendBookingAlerts($bookingIdsOrCollection, string $bookingType = 'agent'): bool
    {
        try {
            // Handle both array of IDs and Collection of Booking models
            if ($bookingIdsOrCollection instanceof Collection) {
                // Collection of Booking models passed directly (from ResendTicketController)
                $bookings = $bookingIdsOrCollection;
                
                if ($bookings->isEmpty()) {
                    return false;
                }
                
                $firstBooking = $bookings->first();
                
                // Ensure relationships are loaded
                if (!$firstBooking->relationLoaded('ticket') || !$firstBooking->ticket->relationLoaded('event')) {
                    $bookingIds = $bookings->pluck('id')->toArray();
                    $bookings = Booking::with('ticket.event')->whereIn('id', $bookingIds)->get();
                    $firstBooking = $bookings->first();
                }
            } else {
                // Array of IDs passed (from Job)
                $bookingIds = $bookingIdsOrCollection;
                
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
                // Get all bookings for this batch
                $bookings = Booking::whereIn('id', $bookingIds)->get();
            }

            $ticket = $firstBooking->ticket;
            $event = $ticket->event;

            // 🔥 Determine Order ID (using perfect logic)
            $orderId = $this->resolveOrderId($firstBooking, $bookings);

            // 🔥 Build short link
            $shortLink = "getyourticket.in/t/{$orderId}";

            // 🔥 Build ticket summary
            $tickets = $bookings
                ->groupBy('ticket_id')
                ->map(function ($items) {
                    $ticketName = $items->first()->ticket->name ?? 'Unknown Ticket';
                    $qty = $items->count();
                    return "{$ticketName} x{$qty}";
                })
                ->implode(' | ');

            // 🔥 Build standardized notification data
            $notificationData = $this->templateService->buildNotificationData(
                $firstBooking,
                $event,
                [
                    'qty' => count($bookings),
                    'short_link' => $shortLink,
                    'ticket_name' => $tickets,
                    'venue' => $event->venue->address ?? 'TBD',
                ]
            );
            
            Log::info('BookingAlertService: Built notification data', [
                'notification_data' => $notificationData,
            ]);
            
            // 🔥 Prepare WhatsApp data with template-based replacements
            try {
                $whatsappData = $this->templateService->prepareWhatsappData($notificationData, 'Agent Booking');
                Log::info('BookingAlertService: WhatsApp data prepared', [
                    'whatsapp_data' => $whatsappData,
                ]);
            } catch (\Exception $e) {
                Log::error('BookingAlertService: WhatsApp preparation failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            // 🔥 Prepare SMS data with template-based replacements (same template title)
            $smsData = $this->templateService->prepareSmsData($notificationData, 'Agent Booking');
            
            Log::info('BookingAlertService: SMS data prepared', [
                'sms_data' => $smsData,
            ]);

            // 🔥 Send alerts (non-blocking in job context)
            $this->smsService->send($smsData);
            $this->whatsappService->send($whatsappData);

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
