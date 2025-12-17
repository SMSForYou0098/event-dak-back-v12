<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class LoginProtectionService
{
    // Maximum failed attempts before blocking
    private const MAX_FAILED_ATTEMPTS = 5;

    // Duration to remember failed attempts (in minutes)
    private const FAILED_ATTEMPTS_TTL = 30;

    /**
     * Check if user account is blocked
     */
    public function isUserBlocked(User $user): bool
    {
        return !$user->status;
    }

    /**
     * Get blocked user response
     */
    public function getBlockedResponse(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'Your account is blocked due to multiple failed attempts. Please contact support.'
        ], 403);
    }

    /**
     * Handle failed verification attempt (password or OTP)
     * 
     * @param User $user
     * @param string $type - 'password' or 'otp'
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleFailedAttempt(User $user, string $type = 'password'): \Illuminate\Http\JsonResponse
    {
        $failedAttemptsKey = "failed_{$type}_attempts_{$user->id}";
        $failedAttempts = Cache::get($failedAttemptsKey, 0) + 1;

        // Block user if max attempts reached
        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $user->update(['status' => 0]);
            Cache::forget($failedAttemptsKey);

            return response()->json([
                'status' => false,
                'message' => 'Too many failed attempts. Your account is now blocked.'
            ], 403);
        }

        // Store failed attempts count
        Cache::put($failedAttemptsKey, $failedAttempts, now()->addMinutes(self::FAILED_ATTEMPTS_TTL));

        $remainingAttempts = self::MAX_FAILED_ATTEMPTS - $failedAttempts;
        $message = $type === 'otp'
            ? "Invalid or expired OTP. Attempts remaining: {$remainingAttempts}"
            : "Invalid password. Attempts remaining: {$remainingAttempts}";

        return response()->json([
            'status' => false,
            'message' => $message,
        ], 401);
    }

    /**
     * Reset failed attempts on successful verification
     * 
     * @param User $user
     * @param string $type - 'password' or 'otp'
     */
    public function resetFailedAttempts(User $user, string $type = 'password'): void
    {
        $failedAttemptsKey = "failed_{$type}_attempts_{$user->id}";
        Cache::forget($failedAttemptsKey);
    }

    /**
     * Get current failed attempts count
     */
    public function getFailedAttempts(User $user, string $type = 'password'): int
    {
        $failedAttemptsKey = "failed_{$type}_attempts_{$user->id}";
        return Cache::get($failedAttemptsKey, 0);
    }
}
