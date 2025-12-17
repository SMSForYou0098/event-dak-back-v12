<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

class OtpRateLimiter
{
    // Minimum seconds between OTP requests
    private const RESEND_COOLDOWN = 30;

    // Maximum OTP requests before blocking
    private const MAX_ATTEMPTS = 5;

    // Block duration in minutes after max attempts
    private const BLOCK_DURATION = 15;

    /**
     * Check if user can request a new OTP
     */
    public function canSendOtp(User $user): array
    {
        // Check if user is blocked
        if ($user->otp_blocked_until && Carbon::now()->lt($user->otp_blocked_until)) {
            $remainingSeconds = Carbon::now()->diffInSeconds($user->otp_blocked_until);

            return [
                'allowed' => false,
                'reason' => 'blocked',
                'retry_after' => $remainingSeconds,
                'message' => "Too many attempts. Please try again after " . ceil($remainingSeconds / 60) . " minutes."
            ];
        }

        // Reset attempts if block period has passed
        if ($user->otp_blocked_until && Carbon::now()->gte($user->otp_blocked_until)) {
            $user->update([
                'otp_attempts' => 0,
                'otp_blocked_until' => null
            ]);
        }

        // Check cooldown period
        if ($user->otp_sent_at) {
            $secondsSinceLastOtp = Carbon::now()->diffInSeconds($user->otp_sent_at);

            if ($secondsSinceLastOtp < self::RESEND_COOLDOWN) {
                $remainingSeconds = self::RESEND_COOLDOWN - $secondsSinceLastOtp;

                return [
                    'allowed' => false,
                    'reason' => 'cooldown',
                    'retry_after' => $remainingSeconds,
                    'message' => "Please wait {$remainingSeconds} seconds before a new request."
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * Record OTP sent and update attempts
     */
    public function recordOtpSent(User $user): void
    {
        $attempts = $user->otp_attempts + 1;

        $updateData = [
            'otp_sent_at' => Carbon::now(),
            'otp_attempts' => $attempts
        ];

        // Block user if max attempts reached
        if ($attempts >= self::MAX_ATTEMPTS) {
            $updateData['otp_blocked_until'] = Carbon::now()->addMinutes(self::BLOCK_DURATION);
        }

        $user->update($updateData);
    }

    /**
     * Reset attempts after successful verification
     */
    public function resetAttempts(User $user): void
    {
        $user->update([
            'otp_attempts' => 0,
            'otp_sent_at' => null,
            'otp_blocked_until' => null
        ]);
    }
}
