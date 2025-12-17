<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

class DateRangeService
{
    /**
     * Parse date range from request
     * Handles comma-separated date strings (single date or date range)
     * 
     * @param Request|null $request The request object
     * @param string|null $dateString Optional date string (comma-separated dates)
     * @param bool $defaultToToday Whether to default to today if no date provided
     * @return array Returns ['startDate' => Carbon, 'endDate' => Carbon] or null on error
     * @throws \Exception On invalid date format
     */
    public function parseDateRange(?Request $request = null, ?string $dateString = null, bool $defaultToToday = true): ?array
    {
        // Get date from request or direct string
        $date = $request?->input('date') ?? $request?->date ?? $dateString;

        // Treat JavaScript's 'undefined' or 'null' string literals as empty
        if ($date === 'undefined' || $date === 'null') {
            $date = null;
        }

        // If no date provided and defaultToToday is true, return today's range
        if (empty($date) && $defaultToToday) {
            return [
                'startDate' => Carbon::today()->startOfDay(),
                'endDate' => Carbon::today()->endOfDay(),
            ];
        }

        // If no date provided and defaultToToday is false, return null
        if (empty($date) && !$defaultToToday) {
            return null;
        }

        // Parse comma-separated dates
        $dates = explode(',', $date);
        $dates = array_map('trim', $dates); // Trim whitespace

        // Validate date count
        if (count($dates) > 2) {
            throw new \InvalidArgumentException('Invalid date format. Maximum 2 dates allowed (single date or date range).');
        }

        // Single date or same dates (date,date with same value)
        if (count($dates) === 1 || ($dates[0] === $dates[1])) {
            $startDate = Carbon::parse($dates[0])->startOfDay();
            $endDate = Carbon::parse($dates[0])->endOfDay();
        }
        // Date range (start,end)
        elseif (count($dates) === 2) {
            $startDate = Carbon::parse($dates[0])->startOfDay();
            $endDate = Carbon::parse($dates[1])->endOfDay();
        } else {
            throw new \InvalidArgumentException('Invalid date format.');
        }

        // Validate that start date is before or equal to end date
        if ($startDate->gt($endDate)) {
            throw new \InvalidArgumentException('Start date cannot be greater than end date.');
        }

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
    }

    /**
     * Parse date range and return as array response (for API error handling)
     * 
     * @param Request|null $request The request object
     * @param string|null $dateString Optional date string
     * @param bool $defaultToToday Whether to default to today
     * @return array Returns ['startDate' => Carbon, 'endDate' => Carbon] or ['error' => message]
     */
    public function parseDateRangeSafe(?Request $request = null, ?string $dateString = null, bool $defaultToToday = true): array
    {
        try {
            $result = $this->parseDateRange($request, $dateString, $defaultToToday);

            if ($result === null) {
                return ['error' => 'No date provided'];
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get date range from request (with automatic error response)
     * Returns null on success, or array with error response on failure
     * 
     * @param Request $request The request object
     * @param bool $defaultToToday Whether to default to today
     * @return array|null Returns ['startDate', 'endDate'] on success, null on error (error response already sent)
     */
    public function getDateRangeFromRequest(Request $request, bool $defaultToToday = true): ?array
    {
        $result = $this->parseDateRangeSafe($request, null, $defaultToToday);

        if (isset($result['error'])) {
            return null;
        }

        return $result;
    }
}
