<?php

namespace App\Http\Controllers;

use App\Exports\CorporateExport;
use App\Models\CorporateBooking;
use App\Models\Ticket;
use App\Services\DateRangeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class CorporateBookingController extends Controller
{
    public function index(Request $request, $id, DateRangeService $dateRangeService)
    {
        $loggedInUser = Auth::user();
        $isAdmin = $loggedInUser->hasRole('Admin');
        $userIds = collect();

        // Date Filtering Logic
        $dateRange = $dateRangeService->parseDateRangeSafe($request);
        
        if (isset($dateRange['error'])) {
            return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
        }

        $startDate = $dateRange['startDate'];
        $endDate = $dateRange['endDate'];

        $query = CorporateBooking::whereBetween('created_at', [$startDate, $endDate]);

        if ($isAdmin) {
            $bookingsQuery = $query->withTrashed();
            $activeBookingsQuery = clone $query;
        } else {
            $underUserIds = $loggedInUser->usersUnder()->pluck('id');
            $userIds = $underUserIds->push($loggedInUser->id);
            $bookingsQuery = $query->withTrashed()->whereIn('user_id', $userIds);
            $activeBookingsQuery = clone $query->whereIn('user_id', $userIds);
        }

        $bookings = $bookingsQuery->latest()
            ->with([
                'ticket.event',
                'user:id,name,reporting_user,payment_method',
                'user.reportingUser:id,name',
                'CorporateUser'
            ])
            ->get()
            ->map(function ($booking) {
                $booking->is_deleted = $booking->trashed();
                $booking->user_name = $booking->user->name ?? 'Unknown User';
                $booking->payment_method = $booking->user->payment_method ?? '';
                $booking->reporting_user_name = $booking->user->reportingUser->name ?? 'No Reporting User';
                $booking->attendee_data = $booking->CorporateUser ?? null; 
                return $booking;
            });

        $amount = $activeBookingsQuery->sum('amount');
        $discount = $activeBookingsQuery->sum('discount');

        return response()->json([
            'status' => $bookings->isNotEmpty(),
            'bookings' => $bookings,
            'amount' => $amount,
            'discount' => $discount,
            'message' => $bookings->isNotEmpty() ? null : 'No Bookings Found',
        ], 200);
    }

    public function create(Request $request)
    {
        try {
            // return response()->json(['bookings' => $request->tickets[0]['id']], 201);
            $booking = new CorporateBooking();

            $ticket = Ticket::findOrFail($request->tickets[0]['id']);
            $event = $ticket->event;

            $booking->token = $this->generateHexadecimalCode();
            $booking->user_id = $request->user_id;
            $booking->ticket_id = $request->tickets[0]['id'];
            $booking->name = $request->name;
            $booking->attendee_id = $request->attendee_id;
            $booking->number = $request->number;
            $booking->email = $request->email;
            $booking->base_amount = $request->base_amount;
            $booking->convenience_fee = $request->convenience_fee;
            $booking->quantity = $request->tickets[0]['quantity'];
            $booking->discount = $request->discount;
            $booking->amount = $request->amount;
            $booking->payment_method = $request->payment_method;
            $booking->booking_date = now();
            $booking->status = 0;
            $booking->save();
            $booking->load('ticket');
            $booking->load('CorporateUser');
            return response()->json(['status' => true, 'message' => 'Post Tickets Booked Successfully', 'bookings' => $booking], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to book tickets', 'error' => $e->getMessage()], 500);
        }
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

    public function destroy($id)
    {
        $booking = CorporateBooking::findOrFail($id);
        $booking->delete();
        return response()->json(['status' => true], 200);
    }

    public function restoreBooking($id)
    {
        $bookings = CorporateBooking::withTrashed()->findOrFail($id);

        if ($bookings) {
            $bookings->restore();
            return response()->json(['status' => true, 'message' => 'Booking restored successfully']);
        } else {
            return response()->json(['message' => 'Booking not found']);
        }
    }

    public function export(Request $request, DateRangeService $dateRangeService)
    {

        $Attendee = $request->input('user_id');
        $eventName = $request->input('ticket_id');
        $status = $request->input('status');

        $query = CorporateBooking::query();

        if ($request->has('ticket_id')) {
            $query->where('ticket_id', $eventName);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $Attendee);
        }

        if ($request->has('status')) {
            $query->where('status', $status);
        }

        // Date filtering
        if ($request->has('date')) {
            $dateRange = $dateRangeService->parseDateRange($request, null, false);
            
            if ($dateRange) {
                $startDate = $dateRange['startDate'];
                $endDate = $dateRange['endDate'];
                
                // Use whereDate for single day, whereBetween for range
                if ($startDate->isSameDay($endDate)) {
                    $query->whereDate('created_at', $startDate->toDateString());
                } else {
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                }
            }
        }

        // $PosBooking = $query->get();
        $CorporateBooking = $query->with([
            'ticket.event.user',
            'user'
        ])->get();
        // return response()->json(['Booking' => $PosBooking]);
        return Excel::download(new CorporateExport($CorporateBooking), 'CorporateExport_export.xlsx');
    }
    
    public function posDataByNumber($number)
    {
        $booking = CorporateBooking::select('name', 'number')
        ->where('number', $number)
        ->latest()
        ->first();    

        if ($booking) {
            return response()->json(['status' => true, 'data' => $booking], 200);
        } else {
            return response()->json(['status' => false, 'message' => 'No booking found for this number'], 404);
        }
    }

}
