<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserTicket;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    /**
     * Get user with all required relationships for editing.
     */
    public function getUserForEdit(string $id): ?User
    {
        return User::with([
            'reportingUser:id,name',
            'shop',
            'organizerSignature',
            'roles:id,name'
        ])->find($id);
    }

    /**
     * Get all events and tickets data for a user.
     */
    public function getEventsAndTickets(string $userId): array
    {
        return [
            'events' => $this->getAgentEvents($userId),
            'agentTickets' => $this->getAgentTickets($userId),
            'tickets' => $this->getUserTickets($userId),
        ];
    }

    /**
     * Get agent events with their tickets.
     */
    public function getAgentEvents(string $userId): Collection|array
    {
        $agentEvent = $this->getAgentEventRecord($userId);

        if (!$agentEvent) {
            return [];
        }

        $eventIds = $this->decodeJsonIds($agentEvent->event_id);

        if (empty($eventIds)) {
            return [];
        }

        return Event::with(['tickets:id,event_id,name'])
            ->whereIn('id', $eventIds)
            ->select('id', 'name')
            ->get();
    }

    /**
     * Get agent tickets.
     */
    public function getAgentTickets(string $userId): Collection|array
    {
        $agentEvent = $this->getAgentEventRecord($userId);

        if (!$agentEvent) {
            return [];
        }

        $ticketIds = $this->decodeJsonIds($agentEvent->ticket_id);

        if (empty($ticketIds)) {
            return [];
        }

        return Ticket::whereIn('id', $ticketIds)
            ->select('id', 'event_id', 'name', 'price')
            ->get();
    }

    /**
     * Get formatted user tickets - optimized with single query.
     */
    public function getUserTickets(string $userId): array
    {
        $ticketIds = $this->collectAllUserTicketIds($userId);

        if (empty($ticketIds)) {
            return [];
        }

        return Ticket::whereIn('id', $ticketIds)
            ->select('id', 'name', 'event_id')
            ->get()
            ->map(fn(Ticket $ticket) => [
                'value' => $ticket->id,
                'label' => $ticket->name,
                'eventId' => $ticket->event_id,
            ])
            ->toArray();
    }

    /**
     * Collect all ticket IDs from user tickets.
     */
    private function collectAllUserTicketIds(string $userId): array
    {
        $userTickets = UserTicket::where('user_id', $userId)
            ->pluck('ticket_id')
            ->toArray();

        if (empty($userTickets)) {
            return [];
        }

        return collect($userTickets)
            ->flatten()
            ->unique()
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get agent event record (cached within request).
     */
    private ?AgentEvent $agentEventCache = null;
    private ?string $cachedUserId = null;

    private function getAgentEventRecord(string $userId): ?AgentEvent
    {
        // Simple request-level cache to avoid duplicate queries
        if ($this->cachedUserId === $userId && $this->agentEventCache !== null) {
            return $this->agentEventCache;
        }

        $this->cachedUserId = $userId;
        $this->agentEventCache = AgentEvent::where('user_id', $userId)
            ->select('event_id', 'ticket_id')
            ->first();

        return $this->agentEventCache;
    }

    /**
     * Safely decode JSON IDs.
     */
    private function decodeJsonIds(?string $json): array
    {
        if (empty($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
