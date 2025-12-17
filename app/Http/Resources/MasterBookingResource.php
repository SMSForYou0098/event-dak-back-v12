<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MasterBookingResource extends JsonResource
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
        $firstBooking = $this->bookings->first();

        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'order_id' => $this->order_id,
            'set_id' => $this->set_id,
            'booking_by' => $this->booking_by,
            'booking_type' => $this->booking_type,
            'total_amount' => $this->total_amount,
            'discount' => $this->discount,
            'created_at' => $this->created_at,
            'payment_method' => $this->payment_method,
            'deleted_at' => $this->deleted_at,
            'is_deleted' => !is_null($this->deleted_at),
            'is_master' => true,
            'quantity' => $this->bookings->count(),

            // Extracted from first booking
            'status' => $firstBooking->status ?? null,
            'agent_name' => $firstBooking->agentUser->name ?? '',
            'event_name' => $firstBooking->ticket->event->name ?? '',
            'organizer' => $firstBooking->ticket->event->user->name ?? '',

            // Bookings collection
            'bookings' => BookingResource::collectionWithPermissions(
                $this->bookings,
                $this->canViewUsername,
                $this->canViewContact
            ),
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
