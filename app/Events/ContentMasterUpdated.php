<?php

namespace App\Events;

use App\Models\ContentMaster;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContentMasterUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $contentMaster;
    public $action;

    /**
     * Create a new event instance.
     */
    public function __construct(ContentMaster $contentMaster, string $action = 'updated')
    {
        $this->contentMaster = $contentMaster;
        $this->action = $action;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('content-master'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'content.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->contentMaster->id,
            'title' => $this->contentMaster->title,
            'content' => $this->contentMaster->content,
            'type' => $this->contentMaster->type,
            'action' => $this->action,
            'timestamp' => now()->toISOString(),
        ];
    }
}
