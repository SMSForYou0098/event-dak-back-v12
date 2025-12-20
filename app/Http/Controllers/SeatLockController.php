<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SeatLockingService;
use App\Services\EventSeatStatusService;
use Illuminate\Support\Facades\Validator;
use App\Services\SessionIdService;

class SeatLockController extends Controller
{
    protected $seatLockingService;
    protected $eventSeatStatusService;
    protected $sessionIdService;

    public function __construct(
        SeatLockingService $seatLockingService,
        EventSeatStatusService $eventSeatStatusService,
        SessionIdService $sessionIdService
    ) {
        $this->seatLockingService = $seatLockingService;
        $this->eventSeatStatusService = $eventSeatStatusService;
        $this->sessionIdService = $sessionIdService;
    }

    /**
     * Lock seats for a temporary period
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function lock(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'event_id' => 'required|integer',
            'seats' => 'required|array|min:1',
            'seats.*' => 'required', // Can be int or string (seat_123)
            'session_id' => 'nullable|string', // Optional now
            'duration' => 'nullable|integer|min:60|max:1800' // Optional duration (1-30 mins)
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $eventId = $request->event_id;
        $seatIds = $request->seats;
        // Generate session ID if not provided
        $sessionId = $request->session_id ?? $this->sessionIdService->generateEncryptedSessionId()['original'];
        $duration = $request->duration ?? 600; // Default 10 minutes

        // 1. Check availability first (Database Status + Redis Lock by OTHERS)
        $unavailableSeats = [];
        $numericSeatIds = [];

        foreach ($seatIds as $seatId) {
            // Extract numeric ID for consistent processing
            // We use a helper or simple regex here if the service method isn't public static
            // But since we have the service, let's use its logic via isSeatAvailable

            // Note: isSeatAvailable expects a single seat ID. 
            // We pass $sessionId to allow re-locking if we already hold the lock (extend duration)
            if (!$this->eventSeatStatusService->isSeatAvailable($seatId, $eventId, $this->seatLockingService, $sessionId)) {
                $unavailableSeats[] = $seatId;
            } else {
                // Extract numeric ID for locking service
                if (preg_match('/(\d+)/', $seatId, $matches)) {
                    $numericSeatIds[] = (int) $matches[1];
                } elseif (is_numeric($seatId)) {
                    $numericSeatIds[] = (int) $seatId;
                }
            }
        }

        if (!empty($unavailableSeats)) {
            return response()->json([
                'status' => false,
                'message' => 'Some seats are no longer available',
                'unavailable_seats' => $unavailableSeats
            ], 409);
        }

        // 2. Attempt to lock all seats
        $result = $this->seatLockingService->acquireBatchSeatLocks(
            $eventId,
            $numericSeatIds,
            $sessionId,
            $duration
        );

        if ($result['success']) {
            return response()->json([
                'status' => true,
                'message' => 'Seats locked successfully',
                'session_id' => $sessionId, // Return the generated/used session ID
                'locked_seats' => $seatIds,
                'expires_in' => $duration
            ]);
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Failed to lock seats. They may have been taken just now.',
                'failed_seats' => $result['failed_seats']
            ], 409);
        }
    }
}
