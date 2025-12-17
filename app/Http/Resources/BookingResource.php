<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    protected $canViewUsername;
    protected $canViewContact;

    /**
     * Create a new resource instance.
     */
    public function __construct($resource, bool $canViewUsername = true, bool $canViewContact = true)
    {
        parent::__construct($resource);
        $this->canViewUsername = $canViewUsername;
        $this->canViewContact = $canViewContact;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'set_id' => $this->set_id,
            'booking_by' => $this->booking_by,
            'user_id' => $this->user_id,
            'ticket_id' => $this->ticket_id,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'discount' => $this->discount,
            'quantity' => $this->quantity ?? 1,
            'booking_type' => $this->booking_type,
            'created_at' => $this->created_at,
            'token' => $this->token,
            'master_token' => $this->master_token,
            'email' => $this->email,
            'name' => $this->canViewUsername ? $this->name : null,
            'number' => $this->canViewContact ? $this->number : null,
            'payment_method' => $this->payment_method,
            'seat_name' => $this->seat_name,
            'section_id' => $this->section_id,
            'batch_id' => $this->batch_id,
            'deleted_at' => $this->deleted_at,
            'is_deleted' => !is_null($this->deleted_at),
            'is_master' => false,
            'is_set' => false,

            // Relationships
            'ticket' => $this->whenLoaded('ticket', function () {
                return [
                    'id' => $this->ticket->id,
                    'name' => $this->ticket->name,
                    'event_id' => $this->ticket->event_id,
                    'price' => $this->ticket->price ?? null,
                    'background_image' => $this->ticket->background_image ?? null,
                    'event' => $this->when($this->ticket->relationLoaded('event'), function () {
                        return [
                            'id' => $this->ticket->event->id,
                            'name' => $this->ticket->event->name,
                            'event_key' => $this->ticket->event->event_key ?? null,
                            'user_id' => $this->ticket->event->user_id,
                            'user' => $this->when($this->ticket->event->relationLoaded('user'), function () {
                                return [
                                    'id' => $this->ticket->event->user->id,
                                    'name' => $this->ticket->event->user->name,
                                    'organisation' => $this->ticket->event->user->organisation ?? null,
                                ];
                            }),
                        ];
                    }),
                ];
            }),

            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->canViewUsername ? $this->user->name : null,
                    'number' => $this->canViewContact ? $this->user->number : null,
                    'email' => $this->user->email ?? null,
                    'photo' => $this->user->photo ?? null,
                    'company_name' => $this->user->company_name ?? null,
                ];
            }),

            'agentUser' => $this->whenLoaded('agentUser', function () {
                return [
                    'id' => $this->agentUser->id,
                    'name' => $this->agentUser->name,
                ];
            }),

            'attendee' => $this->whenLoaded('attendee'),
            'LSection' => $this->whenLoaded('LSection'),

            // Computed fields
            'agent_name' => $this->agentUser->name ?? '',
            'event_name' => $this->ticket->event->name ?? '',
            'organizer' => $this->ticket->event->user->organisation ?? '',
        ];
    }

    /**
     * Static method to create collection with permissions
     */
    public static function collectionWithPermissions($resource, bool $canViewUsername, bool $canViewContact)
    {
        return $resource->map(function ($item) use ($canViewUsername, $canViewContact) {
            return new self($item, $canViewUsername, $canViewContact);
        });
    }
}
