<?php

namespace App\Services;

use App\Models\WhatsappApi;

class TicketMessageService
{

    public function prepareData($tableName, $booking, $bookings, $event, $ticket, $orderId, $eventDateTime)
    {
        // 🔹 Define WhatsApp / SMS Template Titles
        switch (strtolower($tableName)) {
            case 'online':
            case 'online_master':
                $whatsappTitle = 'Online Booking';
                $smsTitle = 'Online Booking Template';
                break;
            case 'agent':
            case 'agentmaster':
                $whatsappTitle = 'Agent Booking';
                $smsTitle = 'Agent Booking Template';
                break;
            case 'sponsorbooking':
            case 'sponsormasterbooking':
                $whatsappTitle = 'Sponsor Booking';
                $smsTitle = 'Sponsor Booking Template';
                break;
            default:
                $whatsappTitle = 'Event Ticket';
                $smsTitle = 'Event Ticket Template';
                break;
        }

        $whatsappTemplate = WhatsappApi::where('title', $whatsappTitle)->first();
        $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

        // 🔹 Short Link
        $shortLinksms = "t.getyourticket.in/t/{$orderId}";

        // 🔹 Ticket Summary
        $ticketSummary = collect($bookings)
            ->groupBy('ticket_id')
            ->map(function ($items) {
                $ticketName = $items->first()->ticket->name ?? 'Unknown Ticket';
                $qty = $items->first()->quantity ?? 0;
                return "{$ticketName} x{$qty}";
            })
            ->implode(' | ');
        $totalQty = $bookings->where('set_id', $bookings->first()->set_id)->count();
        // 🔹 Prepare Final Data
        return (object) [
            'name' => $booking->name ?? '',
            'number' => $booking->number ?? '',
            'templateName' => $smsTitle,
            'whatsappTemplateData' => $whatsappTemplateName,
            'shortLink' => $shortLinksms,
            'insta_whts_url' => $event->insta_whts_url ?? '',
            'mediaurl' => $event->thumbnail ?? '',
            'values' => [
                (string) ($booking->name ?? 'Guest'),
                (string) ($booking->number ?? '0000000000'),
                (string) ($event->name ?? 'Event'),
                (string) ($totalQty ?? '0'),
                (string) ($ticketSummary ?? 'Ticket'),
                (string) ($event->venue->address ?? 'Venue'),
                (string) ($eventDateTime ?? ''),
                (string) ($event->whts_note ?? 'Do not share this ticket'),
            ],
            'replacements' => [
                ':C_Name'      => $booking->name ?? '',
                ':T_QTY'       => $totalQty ?? '0',
                ':Ticket_Name' => $ticketSummary ?? '',
                ':Event_Name'  => $event->name ?? '',
                ':Event_Date'  => $eventDateTime ?? '',
                ':S_Link'      => $shortLinksms,
            ],
        ];
    }
}
