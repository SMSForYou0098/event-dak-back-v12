<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeatStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $eventId;
    public $seatIds;
    public $status; // 'booked', 'locked', 'released'
    public $triggeringUserId; // Optional: Session ID that triggered the update

    /**
     * Create a new event instance.
     * 
     * @param int $eventId
     * @param array $seatIds
     * @param string $status
     * @param string|null $triggeringUserId
     */
    public function __construct(int $eventId, array $seatIds, string $status, ?string $triggeringUserId = null)
    {
        $this->eventId = $eventId;
        $this->seatIds = $seatIds;
        $this->status = $status;
        $this->triggeringUserId = $triggeringUserId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Broadcast to a public channel specific to the event
        return [
            new Channel('event-seats.' . $this->eventId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'seat.status.updated';
    }
}
