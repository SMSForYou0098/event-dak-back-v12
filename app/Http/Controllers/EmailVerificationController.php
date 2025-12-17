<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    private const RESEND_COOLDOWN = 60; // seconds

    /**
     * Verify email address
     */
    public function verify(Request $request, $id, $hash)
    {
        $user = User::find($id);
        $frontendUrl = rtrim(env('ALLOWED_DOMAIN'), '/');
        $url = $frontendUrl . '/auth/login?set=';

        if (!$user) {
            return $this->redirectToFrontend($url, 'email-verification-failed');
        }

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->redirectToFrontend($url, 'email-verification-failed');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->redirectToFrontend($url, 'email-already-verified');
        }

        if (!$request->hasValidSignature()) {
            return $this->redirectToFrontend($url, 'verification-expired');
        }

        $user->markEmailAsVerified();

        return $this->redirectToFrontend($url, 'email-verified-success');
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }
        if ($user->status === 0) {
            return response()->json([
                'status' => false,
                'message' => 'User account has been suspended.'
            ], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => false,
                'message' => 'Email is already verified.'
            ], 400);
        }

        // Rate limit check
        $rateLimitResponse = $this->checkRateLimit($user);
        if ($rateLimitResponse) {
            return $rateLimitResponse;
        }

        // Send verification email
        $this->sendVerificationEmail($user);

        // Set cooldown
        $this->setRateLimit($user);

        return response()->json([
            'status' => true,
            'message' => 'Verification email sent.',
            //'resend_available_in' => self::RESEND_COOLDOWN
        ]);
    }

    /**
     * Check verification status
     */
    public function status(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $cacheKey = 'verification_resend_' . $user->id;
        $canResend = !Cache::has($cacheKey);
        $retryAfter = $canResend ? 0 : Cache::get($cacheKey) - time();

        return response()->json([
            'status' => true,
            'is_verified' => $user->hasVerifiedEmail(),
            'can_resend' => $canResend,
            'retry_after' => max(0, $retryAfter)
        ]);
    }

    /**
     * Generate verification URL
     */
    public function generateVerificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification())
            ]
        );
    }

    /**
     * Send verification email
     */
    private function sendVerificationEmail(User $user): void
    {
        app(UserController::class)->sendRegisterMail($user);
    }

    /**
     * Check rate limit
     */
    private function checkRateLimit(User $user)
    {
        $cacheKey = 'verification_resend_' . $user->id;

        if (Cache::has($cacheKey)) {
            $remaining = Cache::get($cacheKey) - time();

            return response()->json([
                'status' => false,
                'message' => "Please wait {$remaining} seconds.",
                'retry_after' => $remaining
            ], 429);
        }

        return null;
    }

    /**
     * Set rate limit
     */
    private function setRateLimit(User $user): void
    {
        $cacheKey = 'verification_resend_' . $user->id;
        Cache::put($cacheKey, time() + self::RESEND_COOLDOWN, self::RESEND_COOLDOWN);
    }

    /**
     * Redirect to frontend
     */
    private function redirectToFrontend(string $baseUrl, string $status)
    {
        return redirect()->away($baseUrl . $status);
    }
}
