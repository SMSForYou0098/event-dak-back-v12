<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CardBooking;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Http\Request;
use App\Models\PromoCode;
use App\Models\TicketHistory;
use Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class TicketController extends Controller
{

    public function index($id)
    {
        try {
            if (!is_numeric($id)) {
                $event = Event::where('event_key', $id)->first();

                if (!$event) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Event not found for given event_key'
                    ], 404);
                }

                $tickets = Ticket::where('event_id', $event->id)
                    ->get();
            } else {
                $tickets = Ticket::where('event_id', $id)
                    ->get();
            }

            // ✅ Check if we got any tickets
            if ($tickets->isEmpty()) {
                return response()->json([
                    'status' => true,
                    'tickets' => []
                ], 200);
            }

            // ✅ Add on_sale and lowest_sale_price logic (per event)
            $hasSale = $tickets->contains(function ($ticket) {
                return $this->boolValue($ticket->sale ?? false);
            });

            $lowest_sale_price = 0;
            $on_sale = false;

            if ($hasSale) {
                $today = Carbon::today();
                $validSaleTickets = collect();

                foreach ($tickets as $ticket) {
                    if ($this->boolValue($ticket->sale ?? false) && !empty($ticket->sale_date)) {
                        $dates = array_map('trim', explode(',', $ticket->sale_date));

                        if (count($dates) === 1) {
                            $startDate = Carbon::parse($dates[0])->startOfDay();
                            $endDate = Carbon::parse($dates[0])->endOfDay();
                        } else {
                            $startDate = Carbon::parse($dates[0])->startOfDay();
                            $endDate = Carbon::parse($dates[1])->endOfDay();
                        }

                        if (
                            $today->toDateString() == $startDate->toDateString() ||
                            ($today->greaterThanOrEqualTo($startDate) && $today->lessThanOrEqualTo($endDate))
                        ) {
                            $validSaleTickets->push($ticket);
                        }
                    }
                }

                if ($validSaleTickets->isNotEmpty()) {
                    $lowest_sale_price = $validSaleTickets->min('sale_price');
                    $on_sale = true;
                }
            }

            // ✅ Add computed fields and optionally hide internal fields
            foreach ($tickets as $ticket) {
                $ticket->on_sale = $on_sale;
                $ticket->lowest_sale_price = $lowest_sale_price;
            }
            return response()->json([
                'status' => true,
                'tickets' => $tickets
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error in Ticket Index: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }


    public function info($id)
    {
        // Fetch the ticket by ID
        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['status' => false, 'message' => 'Ticket not found'], 404);
        }

        // Calculate total available tickets
        $totalTickets = $ticket->ticket_quantity;

        // Calculate total booked tickets from all booking types
        $bookedTickets = $ticket->bookings->count() + $ticket->posBookings->count() + $ticket->complimentaryBookings->count();

        // Calculate remaining tickets
        $remainingTickets = $totalTickets - $bookedTickets;

        return response()->json([
            'status' => true,
            'ticket' => [
                'total' => $totalTickets,
                'remaining' => $remainingTickets,
            ]
        ], 200);
    }

    //kinjal
    public function create(Request $request, $id)
    {
        try {
            // ✅ Find Event by event_key
            $event = Event::where('event_key', $id)->first();

            if (!$event) {
                return response()->json([
                    'status'  => false,
                    'message' => "Event not found with event_key: $id"
                ], 404);
            }

            $bookingIds = $request->input('access_area');

            if (is_null($bookingIds)) {
                $bookingIds = [];
            } elseif (is_string($bookingIds)) {
                $bookingIds = explode(',', $bookingIds);
            }

            $bookingIds = array_map('intval', array_filter($bookingIds, fn($id) => trim($id) !== ''));

            $ticket = new Ticket();
            $ticket->event_id = $event->id;
            $ticket->event_key = $event->event_key;
            $ticket->name = $request->ticket_title;
            $ticket->currency = $request->currency;
            $ticket->price = $request->price;
            $ticket->ticket_quantity = $request->ticket_quantity;
            $ticket->booking_per_customer = $request->booking_per_customer;
            $ticket->user_booking_limit = $request->user_booking_limit;
            $ticket->description = $request->ticket_description;
            $ticket->taxes = $request->taxes;
            $ticket->sale = $this->normalizeBoolean($request->sale);
            $ticket->sale_date = $request->sale_date;
            $ticket->sale_price = $request->sale_price;
            $ticket->sold_out = $this->normalizeBoolean($request->sold_out);
            $ticket->allow_pos = $this->normalizeBoolean($request->allow_pos);
            $ticket->allow_agent = $this->normalizeBoolean($request->allow_agent);
            $ticket->booking_not_open = $this->normalizeBoolean($request->booking_not_open);
            $ticket->ticket_template = $request->ticket_template;
            $ticket->fast_filling = $this->normalizeBoolean($request->fast_filling);
            $ticket->status = $this->normalizeBoolean($request->status, true);
            $ticket->modify_as = $this->normalizeBoolean($request->modify_access_area);
            $ticket->access_area = $bookingIds;

            $ticket->batch_id = 'TICKET-' . $event->id . '-' . strtoupper(uniqid());

            // ✅ Background Image Upload
            if ($request->hasFile('background_image') && $request->file('background_image')->isValid()) {
                $file = $request->file('background_image');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/Ticket/backgrounds';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $ticket->background_image = $imagePath;
            }

            // ✅ Parse & Validate Promocode Codes
            if (isset($request->promocode_codes)) {
                $promocodeCodes = is_array($request->promocode_codes)
                    ? $request->promocode_codes
                    : explode(',', trim($request->promocode_codes, '[]'));

                $validPromocodeIds = [];
                foreach ($promocodeCodes as $code) {
                    $cleanedCode = trim($code, ' "');
                    $promocode = PromoCode::where('id', $cleanedCode)->first();

                    if (!$promocode) {
                        return response()->json([
                            'status' => false,
                            'message' => "Invalid promocode: $cleanedCode"
                        ], 400);
                    }

                    $validPromocodeIds[] = $promocode->id;
                }

                $ticket->promocode_ids = json_encode($validPromocodeIds);
            } else {
                $ticket->promocode_ids = null;
            }

            $ticket->save();
            $ticket->load('event');

            $tickets = Ticket::where('event_id', $event->id)->get();

            // ✅ Save History
            $history = new TicketHistory();
            $history->ticket_id = $ticket->id;
            $history->batch_id = $ticket->batch_id;
            $history->name = $ticket->name;
            $history->price = $ticket->price;
            $history->currency = $ticket->currency;
            $history->description = $ticket->ticket_description;
            $history->ticket_quantity = $ticket->ticket_quantity;
            $history->booking_per_customer = $ticket->booking_per_customer;
            $history->taxes = $ticket->taxes;
            $history->sale = $ticket->sale;
            $history->sale_date = $ticket->sale_date;
            $history->sale_price = $ticket->sale_price;
            $history->sold_out = $ticket->sold_out;
            $history->booking_not_open = $ticket->booking_not_open;
            $history->ticket_template = $ticket->ticket_template;
            $history->fast_filling = $ticket->fast_filling;
            $history->status = $ticket->status;
            $history->background_image = $ticket->background_image;
            $history->promocode_ids = $ticket->promocode_ids;
            $history->user_booking_limit = $ticket->user_booking_limit;

            $history->save();

            // ✅ Create Card Bookings
            // $this->createCardBookings($ticket->event_id, $ticket->id, $ticket->ticket_quantity);

            return response()->json([
                'status'  => true,
                'message' => 'Ticket Created Successfully',
                'tickets' => $tickets
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create Ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    // ✅ Card Booking Function
    private function createCardBookings($eventId, $ticketId, $quantity)
    {
        for ($i = 1; $i <= $quantity; $i++) {
            $token = 'CB-' . $this->generateHexadecimalCode(10);

            CardBooking::create([
                'event_id'     => $eventId,
                'ticket_id'    => $ticketId,
                'token'        => $token,
                'status'       => '0',      // pending by default
                'booking_type' => 'manual', // set your own logic
                'booking_id'   => null
            ]);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $ticket = Ticket::findOrFail($id);
            // $ticket = Ticket::where('event_key', $id)->first();
            $originalTicket = $ticket->replicate();
            $hasChanges = false;

            $bookingIds = $request->input('access_area');

            if (is_null($bookingIds)) {
                $bookingIds = [];
            } elseif (is_string($bookingIds)) {
                $bookingIds = explode(',', $bookingIds);
            }

            $bookingIds = array_map('intval', array_filter($bookingIds, fn($id) => trim($id) !== ''));

            // Basic Fields Update
            $newData = [
                'name' => $request->ticket_title,
                'currency' => $request->currency,
                'price' => $request->price,
                'ticket_quantity' => $request->ticket_quantity,
                'booking_per_customer' => $request->booking_per_customer,
                'description' => $request->ticket_description,
                'user_booking_limit' => $request->user_booking_limit,
                'taxes' => $request->taxes,
                'sale' => $this->normalizeBoolean($request->sale),
                'sale_date' => $request->sale_date,
                'sale_price' => $request->sale_price,
                'sold_out' => $this->normalizeBoolean($request->sold_out),
                'allow_pos' => $this->normalizeBoolean($request->allow_pos),
                'allow_agent' => $this->normalizeBoolean($request->allow_agent),
                'booking_not_open' => $this->normalizeBoolean($request->booking_not_open),
                'ticket_template' => $request->ticket_template,
                'fast_filling' => $this->normalizeBoolean($request->fast_filling),
                'status' => $this->normalizeBoolean($request->status, true),
                'modify_as' => $this->normalizeBoolean($request->modify_access_area),
                'access_area' => $bookingIds,
            ];

            // Check if any basic fields have changed
            foreach ($newData as $field => $value) {
                if ($ticket->$field != $value) {
                    $hasChanges = true;
                    break;
                }
            }

            // Background Image Change Check
            if ($request->hasFile('background_image') && $request->file('background_image')->isValid()) {
                $hasChanges = true;
            }

            // Promocode Change Check
            $validPromocodeIds = [];

            if ($request->filled('promocode_codes')) {

                $promocodeInput = $request->promocode_codes;

                // ✅ Handle multiple input types
                if (is_array($promocodeInput)) {
                    $promocodeIds = $promocodeInput;
                } elseif (is_string($promocodeInput)) {
                    // If it's a string like "[6,7]" or "6,7"
                    if (preg_match('/^\[.*\]$/', $promocodeInput)) {
                        $promocodeIds = json_decode($promocodeInput, true);
                    } else {
                        $promocodeIds = explode(',', $promocodeInput);
                    }
                } else {
                    $promocodeIds = [];
                }

                // ✅ Clean IDs like "[6", "7]" → "6", "7"
                $promocodeIds = array_map(fn($id) => trim($id, "[]\" "), $promocodeIds);

                foreach ($promocodeIds as $promoId) {
                    $promoId = trim($promoId);
                    $promo = PromoCode::find($promoId);
                    if (!$promo) {
                        return response()->json([
                            'status' => false,
                            'message' => "Invalid promocode ID: $promoId"
                        ], 400);
                    }
                    $validPromocodeIds[] = $promo->id;
                }

                // ✅ Compare promocodes
                $oldPromocodes = json_decode($ticket->promocode_ids ?? '[]', true);
                sort($oldPromocodes);
                sort($validPromocodeIds);

                if ($oldPromocodes !== $validPromocodeIds) {
                    $hasChanges = true;
                }
            } elseif ($ticket->promocode_ids !== null) {
                $hasChanges = true;
            }


            // Only proceed with updates if there are changes
            if ($hasChanges) {

                $oldQuantity = (int) $ticket->ticket_quantity;
                $newQuantity = (int) $request->ticket_quantity;

                // 1️⃣ Prevent reducing quantity when sold_out
                if ($ticket->sold_out == 1 && $newQuantity < $oldQuantity) {
                    return response()->json([
                        'status' => false,
                        'message' => "Cannot reduce ticket quantity while ticket is sold out. Current quantity: $oldQuantity"
                    ], 400);
                }

                // 2️⃣ If increasing, update remaining_count
                if ($newQuantity > $oldQuantity) {
                    $increase = $newQuantity - $oldQuantity;
                    $ticket->remaining_count = (int) ($ticket->remaining_count ?? 0) + $increase;
                }

                // 3️⃣ Always set updated quantity
                $ticket->ticket_quantity = $newQuantity;

                // Generate new batch ID only if there are changes
                $ticket->batch_id = 'TICKET-' . $ticket->event_id . '-' . strtoupper(uniqid());

                // Update basic fields
                $ticket->fill($newData);

                // Handle background image
                if ($request->hasFile('background_image') && $request->file('background_image')->isValid()) {
                    $file = $request->file('background_image');
                    $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                    $folder = 'uploads/Ticket/backgrounds';

                    if ($ticket->background_image) {
                        $oldImagePath = public_path(str_replace(url('/'), '', $ticket->background_image));
                        if (file_exists($oldImagePath)) {
                            unlink($oldImagePath);
                        }
                    }

                    $file->move(public_path($folder), $fileName);
                    $ticket->background_image = url($folder . '/' . $fileName);
                }

                // Update promocodes
                if (!empty($validPromocodeIds)) {
                    $ticket->promocode_ids = json_encode($validPromocodeIds);
                } else {
                    $ticket->promocode_ids = null;
                }

                $ticket->save();

                // Create history entry only if there are changes
                $history = new TicketHistory();
                $history->ticket_id = $ticket->id;
                $history->batch_id = $ticket->batch_id;

                foreach ($newData as $field => $newValue) {
                    if ($field !== 'access_area' && $originalTicket->$field != $newValue) {
                        $history->$field = $newValue;
                    }
                }

                if (isset($ticket->background_image) && $originalTicket->background_image !== $ticket->background_image) {
                    $history->background_image = $ticket->background_image;
                }

                if (!empty($validPromocodeIds)) {
                    $oldPromocodes = json_decode($originalTicket->promocode_ids ?? '[]', true);
                    sort($oldPromocodes);
                    sort($validPromocodeIds);

                    if ($oldPromocodes !== $validPromocodeIds) {
                        $history->promocode_ids = json_encode($validPromocodeIds);
                    }
                }

                $history->save();
            }

            $tickets = Ticket::where('event_id', $ticket->event_id)->get();

            // $this->syncCardBookings($ticket->event_id, $ticket->id, $ticket->ticket_quantity);

            return response()->json([
                'status' => true,
                'message' => $hasChanges ? 'Ticket Updated Successfully' : 'No changes were made to the ticket',
                'tickets' => $tickets
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update Ticket: ' . $e->getMessage()
            ], 500);
        }
    }

    // private function syncCardBookings($eventId, $ticketId, $newQuantity)
    // {

    //     $existingCount = CardBooking::where('event_id', $eventId)
    //         ->where('ticket_id', $ticketId)
    //         ->count();

    //     if ($newQuantity > $existingCount) {
    //         $toAdd = $newQuantity - $existingCount;

    //         for ($i = 1; $i <= $toAdd; $i++) {
    //             $token = 'CB-' . $this->generateHexadecimalCode(10);

    //             CardBooking::create([
    //                 'event_id'     => $eventId,
    //                 'ticket_id'    => $ticketId,
    //                 'token'        => $token,
    //                 'status'       => '0',        // pending by default
    //                 'booking_type' => 'manual',   // or other type as needed
    //                 'booking_id'   => null,
    //             ]);
    //         }
    //     }
    // }

    //store images
    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }

    public function destroy(string $id)
    {
        $Ticket = Ticket::where('id', $id)->firstOrFail();
        if (!$Ticket) {
            return response()->json(['status' => false, 'message' => 'Ticket not found'], 404);
        }

        $Ticket->delete();
        return response()->json(['status' => true, 'message' => 'Ticket deleted successfully'], 200);
    }

    public function userTicketInfo($user_id, $ticket_id)
    {

        $ticket = Ticket::find($ticket_id);

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Ticket not found.'
            ], 404);
        }

        $bookingCount = Booking::where('user_id', $user_id)
            ->where('ticket_id', $ticket->id)
            ->count();

        if ($bookingCount >= $ticket->user_booking_limit) {
            return response()->json([
                'status' => false,
                // 'message' => 'Your booking limit has been reached.',
                // 'current_bookings' => $bookingCount,
                // 'limit' => $ticket->user_booking_limit
            ], 200);
        }

        return response()->json([
            'status' => true,
            // 'message' => 'You can still book.',
            // 'current_bookings' => $bookingCount,
            // 'limit' => $ticket->user_booking_limit
        ], 200);
    }

    private function generateHexadecimalCode($length = 8)
    {
        $characters = '0123456789ABCDEF'; // Hexadecimal characters
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Normalize mixed boolean payloads (true/false, 1/0, "true"/"false", etc.)
     */
    private function normalizeBoolean($value, $default = false): int
    {
        if (is_null($value)) {
            return $default ? 1 : 0;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return ((int) $value) !== 0 ? 1 : 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default ? 1 : 0;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return 0;
            }
            if (is_numeric($normalized)) {
                return ((int) $normalized) !== 0 ? 1 : 0;
            }
        }

        return $default ? 1 : 0;
    }

    /**
     * Cast mixed values to strict boolean for read-time checks.
     */
    private function boolValue($value, $default = false): bool
    {
        if (is_null($value)) {
            return (bool) $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return (bool) $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (is_numeric($normalized)) {
                return (int) $normalized !== 0;
            }
        }

        return (bool) $value;
    }
}
