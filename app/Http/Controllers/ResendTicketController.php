<?php

namespace App\Http\Controllers;

use App\Jobs\SendBookingAlertJob;
use App\Models\Booking;
use App\Models\MasterBooking;
use Exception;
use Illuminate\Http\Request;

class ResendTicketController extends Controller
{

    public function resendTicket(Request $request)
    {
        try {
            $orderId = $request->input('order_id');
            $tableName = $request->input('table_name'); // booking, agent, sponsor
            $isMaster = $request->input('is_master', false);

            if (!$orderId || !$tableName) {
                return response()->json([
                    'status' => false,
                    'message' => 'order_id and table_name are required',
                ], 400);
            }

            // ✅ Determine booking type from table_name
            $bookingType = match($tableName) {
                'booking' => 'online',
                'agent' => 'agent',
                'sponsor' => 'sponsor',
                default => null
            };

            if (!$bookingType) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid table_name. Allowed: booking, agent, sponsor',
                ], 400);
            }

            $bookingIds = [];

            // ✅ If is_master = true, fetch from MasterBooking table
            if ($isMaster === true) {
                $masterBooking = MasterBooking::where('order_id', $orderId)->first();

                if (!$masterBooking) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Master booking not found',
                    ], 404);
                }

                // Get booking IDs from master_booking.booking_id (array)
                $bookingIds = $masterBooking->booking_id ?? [];

                if (empty($bookingIds)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'No bookings found in master booking',
                    ], 404);
                }
            } 
            // ✅ If is_master = false or not specified, fetch directly from Booking table
            else {
                $bookingQuery = Booking::where('token', $orderId);
                
                if ($bookingType !== 'online') {
                    $bookingQuery->where('booking_type', $bookingType);
                }

                // If is_master = false, get child bookings only
                if ($isMaster === false) {
                    $bookingQuery->whereNotNull('set_id'); // Child bookings have set_id
                }

                $bookings = $bookingQuery->get();

                if ($bookings->isEmpty()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Booking not found',
                    ], 404);
                }

                $bookingIds = $bookings->pluck('id')->toArray();
            }

            // ✅ Dispatch job with booking IDs - same as AgentController!
            SendBookingAlertJob::dispatch($bookingIds, $bookingType);

            return response()->json([
                'status' => true,
                'message' => 'Resend job dispatched successfully',
                'booking_ids' => $bookingIds,
                'booking_type' => $bookingType,
                'is_master' => $isMaster
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Resend failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
