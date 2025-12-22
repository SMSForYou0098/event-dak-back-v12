<?php

namespace App\Http\Controllers;

use App\Jobs\SendComplimentaryBookingNotification;
use App\Models\ComplimentaryBookings;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WhatsappApi;
use Auth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ComplimentaryBookingController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = ComplimentaryBookings::query()->withTrashed();
            $user = Auth::user();

            // $dates = null;
            // if ($request->has('date')) {
            //     $dates = $request->date ? explode(',', $request->date) : null;
            // }

            if ($user->hasRole('Admin')) {
                $query->select('batch_id', 'reporting_user', 'deleted_at')
                    ->distinct()
                    ->with(['user', 'ticket.event']);
            } else {
                $query->select('batch_id', 'reporting_user', 'deleted_at')
                    ->distinct()
                    ->with(['user', 'ticket.event'])
                    ->where('reporting_user', $user->id)
                    ->orWhere('reporting_user', $user->reporting_user);
            }

            $batchBookings = $query->get();

            // 🔹 Group by batch_id so duplicates are handled
            $grouped = $batchBookings->groupBy('batch_id');

            $result = $grouped->map(function ($group) {
                // Prefer active (not deleted) record if it exists
                $batchBooking = $group->first(fn($item) => is_null($item->deleted_at))
                    ?? $group->first(); // if all deleted, take first deleted one

                // 🔹 Fetch all bookings under this batch_id
                $bookings = ComplimentaryBookings::withTrashed()
                    ->where('batch_id', $batchBooking->batch_id)
                    ->get();

                $bookingCount = $bookings->count();
                $ticketName = $bookings->first()->ticket->name ?? null;
                $eventName = $bookings->first()->ticket->event->name ?? null;
                $bookingDate = $bookings->first()->created_at;
                $bookingType = $bookings->first()->type;
                $data = $bookingType == 'imported' ? 1 : 0;
                $user = $batchBooking->reportingUser;
                $disable = !is_null($batchBooking->deleted_at);

                return [
                    'name' => $user?->name,
                    'number' => $user?->number,
                    'booking_count' => $bookingCount,
                    'ticket_name' => $ticketName,
                    'event_name' => $eventName,
                    'booking_date' => $bookingDate,
                    'batch_id' => $batchBooking->batch_id,
                    'type' => $data,
                    'is_deleted' => $disable,
                ];
            });

            // 🔹 Sort final results by booking_date
            $result = $result->sortByDesc('booking_date')->values();

            return response()->json([
                'status' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve bookings',
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function getTokensByBatchId(Request $request)
    {
        $batchId = $request->batch_id;

        $tokens = ComplimentaryBookings::where('batch_id', $batchId)
            ->with('ticket.event.user')
            ->get();

        return response()->json([
            'status' => true,
            'tokens' => $tokens,
        ]);
    }

    //number booking
    public function storeData(Request $request)
    {
        // Get the quantity
        $quantity = $request->input('quantity');
        $bookings = [];
        $batchId = uniqid();
        $ticket = Ticket::find($request->input('ticket_id'));

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid ticket ID.'
            ], 404);
        }
        // Loop through the quantity and create records
        for ($i = 0; $i < $quantity; $i++) {
            $bookings[] = [
                'reporting_user' => $request->input('user_id'),
                'batch_id' => $batchId,
                'ticket_id' => $request->input('ticket_id'),
                'event_id' => $ticket->event_id,
                'token' => $this->generateHexadecimalCode(),
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'number' => $request->input('number'),
                'type' => 'generated',
                'status' => 0,
                'created_at' => now(),
            ];
        }

        // Insert all records into the database
        ComplimentaryBookings::insert($bookings);

        $insertedUserIds = array_column($bookings, 'reporting_user');
        $bookingData = ComplimentaryBookings::where('batch_id', $batchId)
            ->with('ticket.event.user')
            ->get();

        // $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
        // $newRemaining = max(0, $newRemaining - $quantity);
        // $ticket->remaining_count = $newRemaining;
        // $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
        // $ticket->save();
        // if ($bookingData->isNotEmpty()) {
        //     $smsService = new \App\Services\SmsService();
        //     $whatsappService = new \App\Services\WhatsappService();

        //     $this->sentAlert($bookingData, $smsService, $whatsappService);
        // }
        // Return a response
        return response()->json([
            'status' => true,
            'message' => 'Complimentary bookings created successfully.',
            'bookings' => $bookings,
        ], 201);
    }

    public function store(Request $request)
    {
        $user = $request->user;
        $userId = $request->user_id;
        $ticketId = $request->ticket_id;
        $token = $request->token;

        $bookings = [];
        $batchId = uniqid();
        $ticket = Ticket::find($ticketId);
        $eventId = $ticket->event_id;

        if (!$ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid ticket ID.'
            ], 404);
        }


        foreach ($user as $userData) {
            $existingUserByNumber = User::where('number', $userData['number'])->first();

            if ($existingUserByNumber) {
                $existingUser = $existingUserByNumber;
            } else {
                $existingUserByEmail = User::where('email', $userData['email'])->first();

                if ($existingUserByEmail) {
                    $existingUser = $existingUserByEmail;
                } else {
                    $existingUser = new User();
                    $existingUser->name = $userData['name'];
                    $existingUser->email = $userData['email'];
                    $existingUser->number = $userData['number'];
                    $existingUser->password = Hash::make($userData['number']);
                    $existingUser->status = true;
                    $existingUser->reporting_user = $userId;

                    $existingUser->save();
                    $this->updateUserRole($request, $existingUser);
                }
            }


            $bookings[] = [
                'user_id' => $existingUser->id,
                'batch_id' => $batchId,
                'ticket_id' => $ticketId,
                'event_id' => $eventId,
                'token' => $userData['token'] ?? $this->generateHexadecimalCode(),
                'name' => $existingUser->name,
                'email' => $existingUser->email,
                'number' => $existingUser->number,
                'reporting_user' => $userId,
                'status' => 0,
                'created_at' => now(),
                'type' => 'imported',
            ];
        }

        // Insert multiple bookings
        ComplimentaryBookings::insert($bookings);

        // Retrieve inserted bookings with relationships
        $insertedUserIds = array_column($bookings, 'user_id');
        $bookingData = ComplimentaryBookings::where('batch_id', $batchId)
            ->with('ticket.event.user') // Load related data
            ->get();

        // $quantity = count($bookings);
        // $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
        // $newRemaining = max(0, $newRemaining - $quantity);
        // $ticket->remaining_count = $newRemaining;
        // $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
        // $ticket->save();


        if ($bookingData->isNotEmpty()) {
            $smsService = new \App\Services\SmsService();
            $whatsappService = new \App\Services\WhatsappService();

            // 🔁 Send alert individually for each booking (each user gets their own link)
            foreach ($bookingData as $booking) {
                $this->sentAlert(collect([$booking]), $smsService, $whatsappService);
            }
        }
        return response()->json([
            'status' => true,
            'message' => 'Complimentary bookings created successfully.',
            'users' => $insertedUserIds,
            'bookings' => $bookingData,
        ], 201);
    }

    public function checkUsers(Request $request)
    {

        $users = $request->input('users');

        if (!is_array($users)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid input. Expected an array of users.',
            ], 422);
        }

        $results = [];

        foreach ($users as $user) {
            $email = $user['email'] ?? null;
            $number = $user['number'] ?? null;

            if (!$email || !$number) {
                $results[] = [
                    'email' => $email,
                    'number' => $number,
                    'exists' => false,
                    'error' => 'Both email and number are required.',
                ];
                continue;
            }

            $existingUser = User::where('email', $email)
                ->orWhere('number', $number)
                ->first();

            if ($existingUser) {
                $results[] = [
                    'email' => $email,
                    'number' => $number,
                    'exists' => true,
                    'email_exists' => $existingUser->email == $email,
                    'number_exists' => $existingUser->number == $number,

                ];
            }
        }

        return response()->json([
            'status' => true,
            'results' => $results,
        ]);
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

    private function updateUserRole($request, $user)
    {
        $defaultRoleName = 'User';
        if ($request->has('role_id') && $request->role_id) {
            $role = Role::find($request->role_id);
            if ($role) {
                $user->syncRoles([]);
                $user->assignRole($role);
            }
        } else {
            $defaultRole = Role::where('name', $defaultRoleName)->first();
            if ($defaultRole) {
                $user->syncRoles([]);
                $user->assignRole($defaultRole);
            }
        }
    }

    public function export(Request $request)
    {

        $Name = $request->input('name');
        $batchId = $request->input('batch_id');
        $Number = $request->input('number');
        $eventName = $request->input('ticket_id');
        $status = $request->input('status');
        $dates = $request->input('date') ? explode(',', $request->input('date')) : null;

        $query = ComplimentaryBookings::query();

        if ($request->has('ticket_id')) {
            $query->where('ticket_id', $eventName);
        }

        if ($request->has('name')) {
            $query->where('name', $Name);
        }

        if ($request->has('number')) {
            $query->where('number', $Number);
        }

        if ($request->has('status')) {
            $query->where('status', $status);
        }

        if ($dates) {
            if (count($dates) === 1) {
                $singleDate = Carbon::parse($dates[0])->toDateString();
                $query->whereDate('created_at', $singleDate);
            } elseif (count($dates) === 2) {
                $startDate = Carbon::parse($dates[0])->startOfDay();
                $endDate = Carbon::parse($dates[1])->endOfDay();
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
        }

        $ComplimentaryBookings = $query->with([
            'ticket.event',
            'ticket'
        ])->get();
        return response()->json(['ComplimentaryBookings' => $ComplimentaryBookings]);
        // return Excel::download(new ComplimentaryBookingsExport($ComplimentaryBookings), 'ComplimentaryBookings_export.xlsx');
    }

    public function restoreComplimentaryBooking($id)
    {
        try {
            $normalBookings = ComplimentaryBookings::withTrashed()
                ->where('batch_id', $id)
                ->get();

            if ($normalBookings->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'ComplimentaryBookings not found.',
                ], 404);
            }

            foreach ($normalBookings as $booking) {
                // 🔍 Check related ticket
                $ticket = Ticket::withTrashed()->find($booking->ticket_id);

                if (!$ticket) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Related ticket not found for one or more bookings.',
                    ], 404);
                }

                // 🚫 If ticket is soft deleted, block restore
                if ($ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot restore — related ticket is deleted.',
                    ], 400);
                }

                // ✅ Safe to restore
                $booking->restore();
            }

            return response()->json([
                'status' => true,
                'message' => 'ComplimentaryBookings restored successfully.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error restoring ComplimentaryBookings: ' . $e->getMessage(),
            ], 500);
        }
    }


    public function destroy($id)
    {
        try {
            $normalBookings = ComplimentaryBookings::where('batch_id', $id)->get();

            if ($normalBookings->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'ComplimentaryBookings not found.'
                ], 404);
            }

            foreach ($normalBookings as $booking) {
                // 🔍 Check related ticket
                $ticket = Ticket::withTrashed()->find($booking->ticket_id);

                if (!$ticket) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Related ticket not found for one or more bookings.'
                    ], 404);
                }

                // 🚫 If ticket is deleted (soft deleted), block deletion
                if ($ticket->trashed()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Cannot delete — related ticket is deleted.',
                    ], 400);
                }

                // ✅ Safe to delete
                $booking->delete();
            }

            return response()->json([
                'status' => true,
                'message' => 'ComplimentaryBookings deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error deleting ComplimentaryBookings: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function sentAlert($bookings, $smsService, $whatsappService)
    {
        if (!empty($bookings)) {
            $firstBooking = $bookings[0];
            $ticket = $firstBooking->ticket;
            $event = $ticket->event;

            $whatsappTemplate = WhatsappApi::where('title', 'Agent Booking')->first();
            $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

            $orderId = $firstBooking->master_token ?? $firstBooking->token;
            $shortLinksms = "t.getyourticket.in/t/{$orderId}";

            $dates = explode(',', $event->date_range);
            $formattedDates = array_map(fn($d) => Carbon::parse($d)->format('d-m-Y'), $dates);
            $eventDateTime = implode(' | ', $formattedDates) . ' | ' . $event->start_time . ' - ' . $event->end_time;

            $ticketSummary = collect($bookings)
                ->groupBy('ticket_id')
                ->map(function ($items) {
                    $ticketName = $items->first()->ticket->name ?? 'Unknown Ticket';
                    $qty = $items->count();
                    return "{$ticketName} x{$qty}";
                })
                ->implode(' | ');

            $data = (object) [
                'name' => $firstBooking->name,
                'number' => $firstBooking->number,
                'templateName' => 'Agent Booking Template',
                'whatsappTemplateData' => $whatsappTemplateName,
                'shortLink' => $orderId,
                'insta_whts_url' => $event->insta_whts_url ?? 'helloinsta',
                'mediaurl' => $event->eventMedia->thumbnail,
                'values' => [
                    $firstBooking->name,
                    $firstBooking->number,
                    $event->name,
                    count($bookings),
                    $ticketSummary,
                    $event->venue->address,
                    $eventDateTime,
                    $event->whts_note ?? 'hello',
                ],
                'replacements' => [
                    ':C_Name' => $firstBooking->name,
                    ':T_QTY' => count($bookings),
                    ':Ticket_Name' => $ticketSummary,
                    ':Event_Name' => $event->name,
                    ':Event_Date' => $eventDateTime,
                    ':S_Link' => $shortLinksms,
                ],
            ];

            // 🔹 Instead of sending instantly, dispatch job to queue
            SendComplimentaryBookingNotification::dispatch($data);
        }
    }
}
