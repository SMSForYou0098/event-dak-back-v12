<?php

namespace App\Http\Controllers;

use App\Events\SeatStatusUpdated;
use App\Services\SeatLockingService;
use App\Services\EventSeatStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SeatLockController extends Controller
{
    private const int MAX_SEATS_PER_USER = 10;
    private const int DEFAULT_LOCK_DURATION = 600;

    public function __construct(
        private readonly SeatLockingService $seatLockingService,
        private readonly EventSeatStatusService $eventSeatStatusService
    ) {}

    /**
     * Lock seats with diff logic
     */
    public function lock(Request $request): JsonResponse
    {
        $validated = $this->validateLockRequest($request);

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $userId = $user->id;
        $sessionId = "user_{$userId}";
        $eventId = $validated['event_id'];
        $duration = $validated['duration'] ?? self::DEFAULT_LOCK_DURATION;

        $newSeats = $this->normalizeSeats($validated['seats']);

        if ($newSeats->isEmpty()) {
            return $this->errorResponse('No valid seat IDs provided', 422);
        }

        // Get user's current locks
        $currentLocks = collect($this->seatLockingService->getUserLocks($eventId, $sessionId));

        // Calculate diff
        [$toRelease, $toAcquire, $toKeep] = $this->calculateSeatDiff($currentLocks, $newSeats);

        // Step 1: Batch check availability
        if ($toAcquire->isNotEmpty()) {
            $availability = $this->eventSeatStatusService->areSeatsAvailable(
                $toAcquire->all(),
                $eventId,
                $this->seatLockingService,
                $sessionId
            );

            if (!$availability['all_available']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Some seats are not available',
                    'unavailable_seats' => $availability['unavailable'],
                    'booked_seats' => $availability['booked'],
                    'locked_by_others' => $availability['locked_by_others'],
                    'your_current_seats' => $currentLocks->values()->all()
                ], 409);
            }
        }

        // Step 2: Release seats user no longer wants
        $this->releaseSeats($eventId, $toRelease, $sessionId, $userId);

        // Step 3: Extend TTL on seats user is keeping
        $this->extendSeats($eventId, $toKeep, $sessionId, $duration);

        // Step 4: Acquire new seats
        $failedSeats = $this->acquireSeats($eventId, $toAcquire, $sessionId, $duration, $userId);

        // Build final response
        $finalLockedSeats = $toKeep->merge($toAcquire->diff(collect($failedSeats)))->values();

        if (!empty($failedSeats)) {
            return response()->json([
                'status' => false,
                'message' => 'Some seats were taken just now',
                'failed_seats' => $failedSeats,
                'locked_seats' => $finalLockedSeats->all(),
                'released_seats' => $toRelease->all(),
                'expires_in' => $duration
            ], 409);
        }

        return response()->json([
            'status' => true,
            'message' => 'Seats locked successfully',
            'locked_seats' => $finalLockedSeats->all(),
            'released_seats' => $toRelease->all(),
            'expires_in' => $duration
        ]);
    }

    /**
     * Release all seats for user
     */
    public function releaseAll(Request $request): JsonResponse
    {
        $validated = validator($request->all(), [
            'event_id' => 'required|integer|exists:events,id'
        ])->validate();

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $userId = $user->id;
        $sessionId = "user_{$userId}";
        $eventId = $validated['event_id'];

        $currentLocks = $this->seatLockingService->getUserLocks($eventId, $sessionId);

        if (!empty($currentLocks) && env('ENABLE_SEAT_STATUS_UPDATES', true)) {
            $this->seatLockingService->releaseBatchLocks($eventId, $currentLocks, $sessionId);
            if (env('ENABLE_SEAT_STATUS_UPDATES', true)) {
                event(new SeatStatusUpdated($eventId, $currentLocks, 'available', $userId));
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'All seats released',
            'released_seats' => $currentLocks
        ]);
    }

    /**
     * Get user's current locked seats
     */
    public function myLocks(Request $request): JsonResponse
    {
        $validated = validator($request->all(), [
            'event_id' => 'required|integer|exists:events,id'
        ])->validate();

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $sessionId = "user_{$user->id}";
        $eventId = $validated['event_id'];

        $locks = $this->seatLockingService->getUserLocks($eventId, $sessionId);
        $ttl = $this->seatLockingService->getUserLocksTTL($eventId, $sessionId);

        return response()->json([
            'status' => true,
            'locked_seats' => $locks,
            'expires_in' => $ttl
        ]);
    }

    /**
     * Extend lock duration
     */
    public function extendLocks(Request $request): JsonResponse
    {
        $validated = validator($request->all(), [
            'event_id' => 'required|integer|exists:events,id',
            'duration' => 'nullable|integer|min:60|max:1800'
        ])->validate();

        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        $sessionId = "user_{$user->id}";
        $eventId = $validated['event_id'];
        $duration = $validated['duration'] ?? self::DEFAULT_LOCK_DURATION;

        $currentLocks = $this->seatLockingService->getUserLocks($eventId, $sessionId);

        if (empty($currentLocks)) {
            return $this->errorResponse('No active locks to extend', 404);
        }

        $this->seatLockingService->extendBatchLocks($eventId, $currentLocks, $sessionId, $duration);

        return response()->json([
            'status' => true,
            'message' => 'Locks extended',
            'locked_seats' => $currentLocks,
            'expires_in' => $duration
        ]);
    }

    /**
     * Validate lock request
     */
    private function validateLockRequest(Request $request): array
    {
        return validator($request->all(), [
            'event_id' => 'required|integer|exists:events,id',
            'seats' => 'required|array|min:1|max:' . self::MAX_SEATS_PER_USER,
            'seats.*' => 'required',
            'duration' => 'nullable|integer|min:60|max:1800'
        ])->validate();
    }

    /**
     * Calculate seat diff
     * 
     * @return array{Collection, Collection, Collection}
     */
    private function calculateSeatDiff(Collection $currentLocks, Collection $newSeats): array
    {
        return [
            $currentLocks->diff($newSeats)->values(),  // toRelease
            $newSeats->diff($currentLocks)->values(),  // toAcquire
            $newSeats->intersect($currentLocks)->values()  // toKeep
        ];
    }

    /**
     * Release seats
     */
    private function releaseSeats(int $eventId, Collection $seats, string $sessionId, int $userId): void
    {
        if ($seats->isEmpty()) {
            return;
        }

        $this->seatLockingService->releaseBatchLocks($eventId, $seats->all(), $sessionId);
        if (env('ENABLE_SEAT_STATUS_UPDATES', true)) {
            event(new SeatStatusUpdated($eventId, $seats->all(), 'available', $userId));
        }
    }

    /**
     * Extend seat locks
     */
    private function extendSeats(int $eventId, Collection $seats, string $sessionId, int $duration): void
    {
        if ($seats->isEmpty()) {
            return;
        }

        $this->seatLockingService->extendBatchLocks($eventId, $seats->all(), $sessionId, $duration);
    }

    /**
     * Acquire new seats
     */
    private function acquireSeats(
        int $eventId,
        Collection $seats,
        string $sessionId,
        int $duration,
        int $userId
    ): array {
        if ($seats->isEmpty()) {
            return [];
        }

        $result = $this->seatLockingService->acquireBatchSeatLocks(
            $eventId,
            $seats->all(),
            $sessionId,
            $duration
        );

        $failedSeats = $result['success'] ? [] : $result['failed_seats'];

        $lockedNew = $seats->diff(collect($failedSeats));
        if ($lockedNew->isNotEmpty() && env('ENABLE_SEAT_STATUS_UPDATES', true)) {
            event(new SeatStatusUpdated($eventId, $lockedNew->all(), 'locked', $userId));
        }

        return $failedSeats;
    }

    /**
     * Normalize seat IDs to integers
     */
    private function normalizeSeats(array $seats): Collection
    {
        return collect($seats)
            ->filter(fn(mixed $seat): bool => filled($seat))
            ->map(fn(mixed $seat): ?int => match (true) {
                is_numeric($seat) => (int) $seat,
                is_string($seat) && preg_match('/(\d+)/', $seat, $m) => (int) $m[1],
                default => null
            })
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Error response helper
     */
    private function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message
        ], $status);
    }
}
