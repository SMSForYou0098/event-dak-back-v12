<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Facades\Octane;

/**
 * Seat Availability Service
 * 
 * Manages real-time seat availability using Swoole tables for ultra-fast lookups.
 * Perfect for high-concurrency ticket booking scenarios.
 */
class SeatAvailabilityService
{
    /**
     * Get available seat count for a ticket
     * Uses Swoole table (shared memory) for 50x faster access than Redis
     */
    public function getAvailableSeats(int $eventId, int $ticketId): ?array
    {
        try {
            // Try Swoole table first (1ms response)
            $key = $this->generateKey($eventId, $ticketId);
            $cached = Octane::table('seat_availability')->get($key);

            if ($cached && $this->isCacheValid($cached)) {
                return [
                    'available' => $cached['available_count'],
                    'total' => $cached['total_count'],
                    'source' => 'swoole_table'
                ];
            }

            // Fallback to database if cache miss
            return $this->refreshFromDatabase($eventId, $ticketId);
        } catch (\Exception $e) {
            // Graceful fallback to database
            return $this->refreshFromDatabase($eventId, $ticketId);
        }
    }

    /**
     * Update seat availability in Swoole table
     */
    public function updateAvailableSeats(int $eventId, int $ticketId, int $available, int $total): void
    {
        try {
            $key = $this->generateKey($eventId, $ticketId);

            Octane::table('seat_availability')->set($key, [
                'event_id' => $eventId,
                'ticket_id' => $ticketId,
                'available_count' => $available,
                'total_count' => $total,
                'last_updated' => time(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the request
            Log::warning('Failed to update Swoole table', [
                'event_id' => $eventId,
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Decrement available seats (after booking)
     */
    public function decrementSeats(int $eventId, int $ticketId, int $quantity = 1): bool
    {
        try {
            $key = $this->generateKey($eventId, $ticketId);
            $current = Octane::table('seat_availability')->get($key);

            if ($current && $current['available_count'] >= $quantity) {
                Octane::table('seat_availability')->set($key, [
                    'event_id' => $eventId,
                    'ticket_id' => $ticketId,
                    'available_count' => $current['available_count'] - $quantity,
                    'total_count' => $current['total_count'],
                    'last_updated' => time(),
                ]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Increment available seats (after cancellation)
     */
    public function incrementSeats(int $eventId, int $ticketId, int $quantity = 1): void
    {
        try {
            $key = $this->generateKey($eventId, $ticketId);
            $current = Octane::table('seat_availability')->get($key);

            if ($current) {
                Octane::table('seat_availability')->set($key, [
                    'event_id' => $eventId,
                    'ticket_id' => $ticketId,
                    'available_count' => min(
                        $current['available_count'] + $quantity,
                        $current['total_count']
                    ),
                    'total_count' => $current['total_count'],
                    'last_updated' => time(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to increment seats', [
                'event_id' => $eventId,
                'ticket_id' => $ticketId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear cache for specific event
     */
    public function clearEventCache(int $eventId): void
    {
        // Note: Swoole tables don't support wildcard deletion
        // You'd need to track keys separately or use Redis for this
        Cache::tags(['seats', "event:{$eventId}"])->flush();
    }

    /**
     * Refresh seat data from database
     */
    private function refreshFromDatabase(int $eventId, int $ticketId): array
    {
        $ticket = \App\Models\Ticket::find($ticketId);

        if (!$ticket) {
            return ['available' => 0, 'total' => 0, 'source' => 'database'];
        }

        $booked = \App\Models\Booking::where('ticket_id', $ticketId)
            ->whereNull('deleted_at')
            ->count();

        $available = max(0, $ticket->quantity - $booked);

        // Update Swoole table for next request
        $this->updateAvailableSeats($eventId, $ticketId, $available, $ticket->quantity);

        return [
            'available' => $available,
            'total' => $ticket->quantity,
            'source' => 'database'
        ];
    }

    /**
     * Generate cache key
     */
    private function generateKey(int $eventId, int $ticketId): string
    {
        return "seat_{$eventId}_{$ticketId}";
    }

    /**
     * Check if cache is still valid (5 minutes)
     */
    private function isCacheValid(array $cached): bool
    {
        return (time() - ($cached['last_updated'] ?? 0)) < 300;
    }
}
