<?php

namespace App\Http\Controllers;;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EventContactController extends Controller
{
    public function getContacts(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|integer|exists:events,id',
            'type' => 'required|in:user,attendee,both',
            'date_range' => 'nullable|string',
            'booking_type' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:100|max:10000',
        ]);

        $eventId = $validated['event_id'];
        $type = $validated['type'];
        $dateRange = $validated['date_range'] ?? null;
        $bookingType = $validated['booking_type'] ?? null;
        $page = $validated['page'] ?? null;
        $perPage = $validated['per_page'] ?? 5000;

        $requestedBookingTypes = $bookingType
            ? array_map('trim', explode(',', $bookingType))
            : [];

        // Build a UNION query to fetch all numbers at database level
        $unionQueries = [];

        // 1. Bookings table (online, agent, sponsor)
        $bookingTypes = ['online', 'agent', 'sponsor'];
        $validBookingTypes = empty($requestedBookingTypes)
            ? $bookingTypes
            : array_intersect($requestedBookingTypes, $bookingTypes);

        if (!empty($validBookingTypes)) {
            $unionQueries = array_merge(
                $unionQueries,
                $this->buildNumberQueries('bookings', $eventId, $type, $dateRange, $validBookingTypes)
            );
        }

        // 2. POS Bookings
        if (empty($requestedBookingTypes) || in_array('pos', $requestedBookingTypes)) {
            $unionQueries = array_merge(
                $unionQueries,
                $this->buildNumberQueries('pos_bookings', $eventId, $type, $dateRange)
            );
        }

        // 3. Complimentary Bookings
        if (empty($requestedBookingTypes) || in_array('complimentary', $requestedBookingTypes)) {
            $unionQueries = array_merge(
                $unionQueries,
                $this->buildNumberQueries('complimentary_bookings', $eventId, $type, $dateRange)
            );
        }

        if (empty($unionQueries)) {
            return response()->json([
                'status' => true,
                'count' => 0,
                'numbers' => []
            ]);
        }

        // Combine all queries with UNION and get distinct numbers
        $combinedQuery = $this->combineQueries($unionQueries);

        // Get total count first (for pagination info)
        $countQuery = DB::table(DB::raw("({$combinedQuery->toSql()}) as combined"))
            ->mergeBindings($combinedQuery)
            ->selectRaw('COUNT(DISTINCT number) as total');

        $totalCount = $countQuery->value('total');

        // Build final query with deduplication at DB level
        $finalQuery = DB::table(DB::raw("({$combinedQuery->toSql()}) as combined"))
            ->mergeBindings($combinedQuery)
            ->select('number')
            ->distinct()
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->orderBy('number');

        // Apply pagination if requested
        if ($page) {
            $numbers = $finalQuery
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->pluck('number');

            return response()->json([
                'status' => true,
                'total_count' => $totalCount,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($totalCount / $perPage),
                'count' => $numbers->count(),
                'numbers' => $numbers
            ]);
        }

        // For non-paginated: use cursor for memory efficiency
        $numbers = $finalQuery->pluck('number');

        return response()->json([
            'status' => true,
            'count' => $numbers->count(),
            'numbers' => $numbers
        ]);
    }

    /**
     * Build optimized queries to extract numbers directly from DB
     */
    private function buildNumberQueries(
        string $table,
        int $eventId,
        string $type,
        ?string $dateRange,
        ?array $bookingTypes = null
    ): array {
        $queries = [];
        $dateConditions = $this->buildDateConditions($dateRange);

        // Base conditions
        $baseConditions = function ($query) use ($eventId, $dateConditions, $bookingTypes, $table) {
            $query->where("{$table}.event_id", $eventId);

            if ($dateConditions) {
                if (isset($dateConditions['single'])) {
                    $query->whereDate("{$table}.created_at", $dateConditions['single']);
                } else {
                    $query->whereBetween("{$table}.created_at", [
                        $dateConditions['start'],
                        $dateConditions['end']
                    ]);
                }
            }

            if ($bookingTypes && $table === 'bookings') {
                $query->whereIn("{$table}.booking_type", $bookingTypes);
            }
        };

        // User numbers
        if ($type === 'user' || $type === 'both') {
            // From users table via relationship
            $queries[] = DB::table($table)
                ->join('users', "{$table}.user_id", '=', 'users.id')
                ->where(function ($q) use ($baseConditions) {
                    $baseConditions($q);
                })
                ->whereNotNull('users.number')
                ->select('users.number');

            // Direct number on booking
            $queries[] = DB::table($table)
                ->where(function ($q) use ($baseConditions) {
                    $baseConditions($q);
                })
                ->whereNotNull("{$table}.number")
                ->select("{$table}.number");
        }

        // Attendee numbers
        if ($type === 'attendee' || $type === 'both') {
            $queries[] = DB::table($table)
                ->join('attendees', "{$table}.attendee_id", '=', 'attendees.id')
                ->where(function ($q) use ($baseConditions) {
                    $baseConditions($q);
                })
                ->whereNotNull('attendees.number')
                ->select('attendees.number');
        }

        return $queries;
    }

    private function buildDateConditions(?string $dateRange): ?array
    {
        if (!$dateRange) {
            return null;
        }

        $dates = explode(',', $dateRange);

        if (count($dates) === 1) {
            return ['single' => Carbon::parse(trim($dates[0]))->toDateString()];
        }

        return [
            'start' => Carbon::parse(trim($dates[0]))->startOfDay(),
            'end' => Carbon::parse(trim($dates[1]))->endOfDay(),
        ];
    }

    private function combineQueries(array $queries)
    {
        $first = array_shift($queries);

        foreach ($queries as $query) {
            $first->union($query);
        }

        return $first;
    }

    /**
     * Alternative: Streaming endpoint for very large datasets
     */
    public function streamContacts(Request $request)
    {
        // Same validation...

        return response()->stream(function () use ($request) {
            echo '{"status":true,"numbers":[';

            $first = true;

            // Use lazy() for memory-efficient iteration
            $this->getNumbersLazy($request)->each(function ($number) use (&$first) {
                if (!$first) echo ',';
                echo json_encode($number);
                $first = false;

                // Flush output buffer periodically
                if (ob_get_level() > 0) ob_flush();
                flush();
            });

            echo ']}';
        }, 200, [
            'Content-Type' => 'application/json',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
