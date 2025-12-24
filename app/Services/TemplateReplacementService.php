<?php

namespace App\Services;

use App\Models\WhatsappApi;
use App\Models\SmsTemplate;
use Illuminate\Support\Facades\Log;

class TemplateReplacementService
{
    /**
     * Get replacements for templates based on template configuration
     * Works for both WhatsApp and SMS
     * 
     * @param string $templateTitle - Template title from WhatsappApi (e.g., "Agent Booking", "Online Booking")
     * @param object $data - Booking/Event data object
     * @return array - Replacement mapping [placeholder => value]
     */
    public function getTemplateReplacements(string $templateTitle, object $data): array
    {
        // Fetch template configuration
        $template = WhatsappApi::where('title', $templateTitle)->first();
        
        if (!$template || empty($template->variables)) {
            Log::warning('TemplateReplacementService: Template not found or no variables configured', [
                'template_title' => $templateTitle
            ]);
            return $this->getFallbackReplacements($data);
        }

        // Parse template variables (already cast to array by model)
        $variables = $template->variables ?? [];

        if (!$variables) {
            return $this->getFallbackReplacements($data);
        }

        // Check if variables is an associative array (has string keys)
        if (array_keys($variables) === range(0, count($variables) - 1)) {
            // It's an indexed array, not associative - use fallback
            Log::warning('TemplateReplacementService: Variables is indexed array, not associative. Using fallback.', [
                'template_title' => $templateTitle,
                'variables' => $variables
            ]);
            return $this->getFallbackReplacements($data);
        }

        return $this->mapVariablesToData($variables, $data);
    }

    /**
     * Map template variables to actual data values
     * 
     * Template variables format in database:
     * [
     *   "{{username}}" => "name",
     *   "{{quantity}}" => "quantity", 
     *   "{{ticketname}}" => "ticket_name",
     *   "{{eventname}}" => "event_name",
     *   "{{eventdatetime}}" => "event_datetime",
     *   "{{shortlink}}" => "short_link"
     * ]
     * 
     * @param array $variables - Template variable mapping from database
     * @param object $data - Actual data object
     * @return array
     */
    private function mapVariablesToData(array $variables, object $data): array
    {
        $replacements = [];
        foreach ($variables as $placeholder => $dataKey) {
            // Get value from data object using the mapping key
            $value = $this->getNestedValue($data, $dataKey);
            $replacements[$placeholder] = $value ?? '';
        }

        return $replacements;
    }

    /**
     * Get nested value from object using dot notation
     * Examples: "name", "ticket.name", "event.name"
     * 
     * @param object $data
     * @param string $key
     * @return mixed
     */
    private function getNestedValue(object $data, string $key)
    {
        $keys = explode('.', $key);
        $value = $data;

        foreach ($keys as $k) {
            if (is_object($value) && isset($value->$k)) {
                $value = $value->$k;
            } elseif (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Fallback replacements if template configuration not found
     * Uses common booking data structure
     * Works for BOTH WhatsApp and SMS (same placeholders)
     * 
     * @param object $data
     * @return array
     */
    private function getFallbackReplacements(object $data): array
    {
        return [
            '{{username}}' => $data->name ?? $data->customer_name ?? '',
            '{{quantity}}' => $data->quantity ?? $data->qty ?? 1,
            '{{ticketname}}' => $data->ticket_name ?? ($data->ticket->name ?? ''),
            '{{eventname}}' => $data->event_name ?? ($data->event->name ?? ''),
            '{{eventdatetime}}' => $data->event_datetime ?? '',
            '{{shortlink}}' => $data->short_link ?? ($data->shortLink ?? ''),
        ];
    }

    /**
     * Build standardized data object for notifications
     * This ensures consistent data structure across the app
     * 
     * @param object $booking - Booking model instance
     * @param object $event - Event model instance
     * @param array $options - Additional options (qty, short_link, etc.)
     * @return object
     */
    public function buildNotificationData($booking, $event, array $options = []): object
    {
        $qty = $options['qty'] ?? 1;
        $shortLink = $options['short_link'] ?? ($booking->token ?? '');
        $shortLinksms = "getyourticket.in/t/{$shortLink}";

        // Format event date & time
        $eventDateTime = $this->formatEventDateTime($event);

        return (object) [
            'name' => $booking->name ?? 'Guest',
            'customer_name' => $booking->name ?? 'Guest',
            'number' => $booking->number ?? '',
            'quantity' => $qty,
            'qty' => $qty,
            'ticket_name' => $booking->ticket->name ?? 'Ticket',
            'event_name' => $event->name ?? 'Event',
            'event_datetime' => $eventDateTime,
            'short_link' => $shortLinksms,
            'shortLink' => $shortLink,
            'venue' => $event->address ?? 'Venue',
            'note' => $event->whts_note ?? 'Welcome',
            'insta_whts_url' => $event->insta_whts_url ?? 'helloinsta',
            'mediaurl' => $event->thumbnail ?? '',
            
            // Raw objects for nested access
            'ticket' => $booking->ticket ?? null,
            'event' => $event,
            'booking' => $booking,
        ];
    }

    /**
     * Format event date and time string
     * 
     * @param object $event
     * @return string
     */
    private function formatEventDateTime($event): string
    {
        $dates = explode(',', $event->date_range ?? '');
        $formattedDates = [];
        
        foreach ($dates as $date) {
            if (trim($date)) {
                $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
            }
        }
        
        $dateRangeFormatted = implode(' | ', $formattedDates);
        return $dateRangeFormatted . ' | ' . ($event->start_time ?? '') . ' - ' . ($event->end_time ?? '');
    }

    /**
     * Prepare WhatsApp send data with template replacements
     * 
     * @param object $notificationData - Standardized notification data
     * @param string $templateTitle - WhatsApp template title
     * @return object
     */
    public function prepareWhatsappData($notificationData, string $templateTitle = 'Online Booking'): object
    {
        $whatsappTemplate = WhatsappApi::where('title', $templateTitle)->first();
        $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

        return (object) [
            'name' => $notificationData->name,
            'number' => $notificationData->number,
            'templateName' => $templateTitle,
            'whatsappTemplateData' => $whatsappTemplateName,
            'shortLink' => $notificationData->shortLink,
            'insta_whts_url' => $notificationData->insta_whts_url,
            'mediaurl' => $notificationData->mediaurl,
            'values' => $this->buildWhatsappValues($notificationData), // Positional array for WhatsApp
        ];
    }

    /**
     * Build WhatsApp template values array
     * Order must match the WhatsApp template variable positions
     * 
     * @param object $data
     * @return array
     */
    private function buildWhatsappValues($data): array
    {
        return [
            $data->name,
            $data->number,
            $data->event_name,
            $data->quantity,
            $data->ticket_name,
            $data->venue,
            $data->event_datetime,
            $data->note,
        ];
    }

    /**
     * Prepare SMS send data with template replacements
     * Uses key-value replacements for SMS templates
     * 
     * @param object $notificationData - Standardized notification data
     * @param string $templateTitle - Template title (same as WhatsApp)
     * @return object
     */
    public function prepareSmsData($notificationData, string $templateTitle = 'Online Booking'): object
    {
        // Get dynamic replacements based on template configuration (key-value pairs for SMS)
        $replacements = $this->getTemplateReplacements($templateTitle, $notificationData);

        return (object) [
            'name' => $notificationData->name,
            'number' => $notificationData->number,
            'templateName' => $templateTitle,
            'replacements' => $replacements, // Key-value pairs for SMS
        ];
    }
}
