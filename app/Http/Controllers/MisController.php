<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Promocode;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MISReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MisController extends Controller
{

    public function misData(Request $request)
    {
        $date = $request->query('date'); // Format: Y-m-d

        if (!$date) {
            return response()->json(['error' => 'Date parameter is required.'], 422);
        }

        $report = [];

        // ------------------------------------------
        // 1. ONLINE BOOKINGS
        // ------------------------------------------
        $onlineBookings = Booking::whereDate('created_at', $date)
            ->with(['ticket.event', 'promocode', 'user']);

        if ($request->query('only_promocode') == '1') {
            $onlineBookings->whereNotNull('promocode_id');
        }

        foreach ($onlineBookings->get() as $booking) {
            $key = 'Online-' . ($booking->promocode_id ?? 'NA') . '-' . $booking->ticket->event->id;

            if (!isset($report[$key])) {
                $report[$key] = [
                    'Type' => 'Online',
                    'Date' => $date,
                    'Event Name' => $booking->ticket->event->name,
                    'Ticket Name' => $booking->ticket->name,
                    'Ticket Price' => $booking->ticket->price,
                    'Promocode' => $booking->promocode_id ?? 'N/A',
                    'Agent Name' => 'N/A',
                    'Total Bookings' => 0,
                    'Cash' => 0,
                    'UPI' => 0,
                    'Net Banking' => 0,
                    'Total Discount' => 0,
                    'Users' => [],
                ];
            }

            $report[$key]['Total Bookings'] += 1;
            $report[$key]['Total Discount'] += $booking->discount ?? 0;

            $report[$key]['Users'][] = [
                'User Name' => $booking->user->name ?? 'N/A',
                'User Email' => $booking->user->email ?? 'N/A',
                'Booking ID' => $booking->id,
                'Discount' => $booking->discount ?? 0,
            ];
        }

        // ------------------------------------------
        // 2. OFFLINE BOOKINGS (Agent bookings from unified table)
        // ------------------------------------------
        $offlineBookings = Booking::whereDate('created_at', $date)
            ->where('booking_type', 'agent')
            ->with(['ticket.event', 'agentUser', 'user']);

        foreach ($offlineBookings->get() as $booking) {
            $key = 'Offline-' . $booking->ticket->event->id . '-' . ($booking->booking_by ?? 'NA');

            if (!isset($report[$key])) {
                $report[$key] = [
                    'Type' => 'Offline',
                    'Date' => $date,
                    'Event Name' => $booking->ticket->event->name,
                    'Ticket Name' => $booking->ticket->name,
                    'Ticket Price' => $booking->ticket->price,
                    'Promocode' => 'N/A',
                    'Agent Name' => $booking->agentUser->name ?? 'N/A',
                    'Total Bookings' => 0,
                    'Cash' => 0,
                    'UPI' => 0,
                    'Net Banking' => 0,
                    'Total Discount' => 0,
                    'Users' => [],

                ];
            }

            $report[$key]['Total Bookings'] += 1;
            $report[$key]['Cash'] += $booking->cash_amount ?? 0;
            $report[$key]['UPI'] += $booking->upi_amount ?? 0;
            $report[$key]['Net Banking'] += $booking->netbanking_amount ?? 0;
            $report[$key]['Total Discount'] += $booking->discount ?? 0;

            $report[$key]['Users'][] = [
                'User Name' => $booking->user->name ?? 'N/A',
                'User Email' => $booking->user->email ?? 'N/A',
                'Booking ID' => $booking->id,
                'Discount' => $booking->discount ?? 0,
            ];
        }

        $reportData = array_values($report);

        // Export as Excel if requested
        if ($request->query('export') == '1') {
            return Excel::download(new MISReportExport($reportData), 'mis-report-' . $date . '.xlsx');
        }

        return response()->json(['data' => $reportData]);
    }
}
