<?php

namespace App\Http\Controllers;

use App\Jobs\SendEmailJob;
use App\Jobs\StoreLoginHistoryJob;
use App\Http\Resources\AuthUserResource;
use App\Models\EmailTemplate;
use App\Models\SmsConfig;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\OtpRateLimiter;
use App\Services\LoginProtectionService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private OtpRateLimiter $otpRateLimiter,
        private LoginProtectionService $loginProtection
    ) {}
    public function verifyUser(Request $request)
    {
        try {

            $loginCredential = $request->input('data');
            //return $loginCredential;
            $userIp = $request->ip();
            $loginTime = now();
            if ($this->isSuspiciousInput($loginCredential)) {
                return response()->json([
                    'message' => 'Possible injection attempt blocked.',
                    'error' => 'Invalid input detected.'
                ], 400);
            }

            $normalizedNumber = preg_replace('/\D/', '', $loginCredential); // keep only digits

            $with91 = $without91 = null;
            if (strlen($normalizedNumber) === 12 && substr($normalizedNumber, 0, 2) === '91') {
                $with91    = $normalizedNumber;
                $without91 = substr($normalizedNumber, 2);
            } elseif (strlen($normalizedNumber) === 10) {
                $with91    = '91' . $normalizedNumber;
                $without91 = $normalizedNumber;
            }

            Cache::forget('login_attempt_' . $loginCredential);

            // --- Check user by email or number (with OR without 91) ---
            $user = User::where('email', $loginCredential)
                ->orWhere('number', $with91)
                ->orWhere('number', $without91)
                ->first();

            if (!$user) {
                return response()->json(['status' => false, 'error' => "Oops! We couldn't verify your login information", 'meta' => 404], 404);
            }

            if ($user->status != 1) {
                // if ($user->activity_status != true) {
                return response()->json(['error' => 'Your account has been blocked. Please contact administrator'], 404);
            }

            // Check email verification for Organizer role
            if ($user->hasRole('Organizer') && $user->email && !$user->hasVerifiedEmail()) {
                return response()->json([
                    'status' => false,
                    'error' => 'Please verify your email address before logging in.',
                    'email_not_verified' => true
                ], 403);
            }
            if ($user) {

                // Check rate limit
                $rateLimitCheck = $this->otpRateLimiter->canSendOtp($user);

                if (!$rateLimitCheck['allowed']) {
                    return response()->json([
                        'status' => false,
                        'message' => $rateLimitCheck['message'],
                        'retry_after' => $rateLimitCheck['retry_after']
                    ], 429); // 429 = Too Many Requests
                }


                if ($user->authentication == 0) {
                    $this->sendOTP($user, $loginCredential);

                    $this->otpRateLimiter->recordOtpSent($user);
                } else {
                    $sessionId = Str::random(40);

                    Cache::put('auth_session_' . $user->id, [
                        'session_id' => $sessionId,
                        'user_id' => $user->id,
                        'ip_address' => $userIp,
                        'login_time' => $loginTime,
                    ], now()->addMinutes(30));
                    return response()->json([
                        'status' => true,
                        'session_id' => $sessionId,
                        'pass_req' => true,
                        // 'user' => $user,
                        'auth_session' => $user->id,
                        'ip_address' => $userIp,
                        'login_time' => $loginTime,
                    ], 200);
                }
            }
            return response()->json(['status' => true], 200);
        } catch (\Exception $e) {
            // Return a generic error response
            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function sendOTP($user, $loginCredential)
    {
        $number = $user->number;
        $email = $user->email;
        $otp = $this->generateOTP();
        $smsConfig = SmsConfig::first();
        //return $smsConfig;
        $templateData = SmsTemplate::where('template_name', 'Login Template')->first();
        $apiKey = $smsConfig?->api_key;
        //return $apiKey;
        $templateID = $templateData?->template_id;
        $message = $templateData?->content;
        $finalMessage = str_replace(':OTP', $otp, $message);
        //  $message = "Please use this OTP : " . $otp . " to continue on login \nSevak Trust\nGet Your Ticket\nSMS4U";
        $encodedMessage = urlencode($finalMessage);
        $modifiedNumber = $this->modifyNumber($number);

        $otpApi = "https://login.smsforyou.biz/V2/http-api.php?apikey=$apiKey&senderid=GTIKET&number=" . $modifiedNumber . "&message=" . $encodedMessage . "&format=json&template_id=$templateID";

        try {
            $client = new Client();
            $response = $client->request('GET', $otpApi);
            $this->sendTemplateEmail('Login Tempplate', $email, [':OTP:' => $otp]);
            $responseBody = json_decode($response->getBody(), true);
            $cacheKey = 'otp_' . $loginCredential;
            Cache::put($cacheKey, $otp, now()->addMinutes(5));

            return response()->json(['message' => $responseBody, true]);
        } catch (RequestException $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    private function isSuspiciousInput($input): bool
    {
        $suspiciousPatterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|UNION|OR|AND|--|;)\b)/i',
            '/[\'"=;#%]/',
        ];

        if (is_bool($input)) return true; // block boolean
        if (is_array($input)) return true; // block arrays
        if (is_numeric($input)) return false;
        if (!is_string($input)) return false;

        $trimmed = strtolower(trim($input));

        $logicTricks = ['true', 'false', 'null', 'undefined', '1=1', '0=0', 'or 1=1', 'and 1=1'];

        foreach ($logicTricks as $trick) {
            if (str_contains($trimmed, $trick)) {
                return true;
            }
        }

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    public function verifyUserRequest(Request $request)
    {

        $inputsToCheck = [
            'session_id'       => $request->session_id,
            'password'         => $request->password,
            'auth_session'     => $request->auth_session,
            'number'           => $request->number,
            'otp'              => $request->otp,
        ];

        foreach ($inputsToCheck as $key => $value) {
            if ($this->isSuspiciousInput($value)) {
                return response()->json([
                    'message' => "Suspicious input in '$key'",
                    'error' => "Invalid input detected in '$key'",
                ], 400);
            }
        }



        $passwordRequired = $request->passwordRequired;
        if ($passwordRequired) {
            $usersessionId = $request->session_id;
            $password = $request->password;
            $authSession = $request->auth_session;

            $user =  $this->verifyPassword($usersessionId, $password, $authSession);
            if ($user && $request->auth_session) {
                StoreLoginHistoryJob::dispatch(
                    (int) $request->auth_session,
                    $request->ip(),
                    now()->toDateTimeString()
                );
            }

            return $user;
        } else {
            $number = $request->number;
            $otp = $request->otp;
            $user = $this->verifyOTP($number, $otp);

            if ($user && $request->auth_session) {
                StoreLoginHistoryJob::dispatch(
                    (int) $request->auth_session,
                    $request->ip(),
                    now()->toDateTimeString()
                );
            }
            return $user;
        }
    }



    public function verifyUserSession(Request $request)
    {
        $token = $request->bearerToken(); // Get token from Authorization header

        if (!$token) {
            return response()->json(['message' => 'No Token Provided'], 401);
        }

        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'Invalid Token'], 401);
        }

        // Check if session_key is provided in request
        $sessionKey = $request->session_key;
        if (!$sessionKey) {
            return response()->json(['message' => 'No Session Key Provided'], 401);
        }

        // Retrieve session from Cache
        $cachedSession = Cache::get('auth_session_key_' . $user->id);
        if (!$cachedSession) {
            return response()->json(['message' => 'Session Expired or Not Found'], 401);
        }

        // Validate session key
        if (!isset($cachedSession['session_key']) || $cachedSession['session_key'] !== $sessionKey) {
            return response()->json(['message' => 'Invalid Session Key'], 401);
        }

        // Session expiration check
        if (!isset($cachedSession['expires_at']) || now()->gt($cachedSession['expires_at'])) {
            return response()->json(['status' => false, 'message' => 'Session expired'], 401);
        }

        // IP Address Match Check
        if (!isset($cachedSession['ip_address']) || $cachedSession['ip_address'] !== $request->ip()) {
            return response()->json(['message' => 'IP Address Mismatch'], 401);
        }

        // return response()->json([
        //     'message' => 'Valid Token and Session',
        //     'status' => true
        // ], 200);
        $sessionData = [
            'session_key' => $cachedSession['session_key'],
            'ip_address' => $request->ip(),
            'login_time' => $cachedSession['login_time'] ?? now(),
        ];

        return $this->_generateAuthResponse($user, 'Login successful', $sessionData);
    }

    private function verifyOTP($number, $otp)
    {
        $loginCredential = $number;

        // Find user first to check blocked status
        $user = filter_var($loginCredential, FILTER_VALIDATE_EMAIL)
            ? User::where('email', $loginCredential)->first()
            : User::where('number', $loginCredential)->first();

        if (!$user) {
            return response()->json(['status' => false, 'error' => 'Invalid or expired OTP'], 401);
        }

        // Check if user is blocked
        if ($this->loginProtection->isUserBlocked($user)) {
            return $this->loginProtection->getBlockedResponse();
        }

        // Retrieve OTP from cache
        $cacheKey = 'otp_' . $loginCredential;
        $cachedOtp = Cache::get($cacheKey);

        if (!$cachedOtp || $cachedOtp != $otp) {
            return $this->loginProtection->handleFailedAttempt($user, 'otp');
        }

        // Reset failed attempts and OTP rate limit on success
        $this->loginProtection->resetFailedAttempts($user, 'otp');
        $this->otpRateLimiter->resetAttempts($user);
        Cache::forget($cacheKey); // Clear the used OTP

        // Create session and return response
        $sessionData = $this->createAuthSession($user);

        return $this->_generateAuthResponse($user, 'OTP verified successfully', $sessionData);
    }
    private function verifyPassword($usersessionId, $password, $auth_session)
    {
        if (!$usersessionId) {
            return response()->json(['status' => false, 'message' => 'Session ID is required'], 400);
        }

        $authSession = Cache::get('auth_session_' . $auth_session);

        if (!$authSession || !isset($authSession['session_id']) || $authSession['session_id'] !== $usersessionId) {
            return response()->json(['status' => false, 'message' => 'Session expired. Please refresh the page.'], 404);
        }

        $user = User::find($auth_session);
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        // Check if user is blocked
        if ($this->loginProtection->isUserBlocked($user)) {
            return $this->loginProtection->getBlockedResponse();
        }

        // Check password
        if (!Hash::check($password, $user->password)) {
            return $this->loginProtection->handleFailedAttempt($user, 'password');
        }

        // Reset failed attempts and OTP rate limit on success
        $this->loginProtection->resetFailedAttempts($user, 'password');
        $this->otpRateLimiter->resetAttempts($user);

        // Create session and return response
        $sessionData = $this->createAuthSession($user);

        return $this->_generateAuthResponse($user, 'Password verified successfully', $sessionData);
    }

    /**
     * Create authentication session for user
     */
    private function createAuthSession(User $user): array
    {
        $userIp = request()->ip();
        $loginTime = now();
        $sessionKey = $user->id . '_' . time() . '_' . Str::random(10) . '_' . str_replace('.', '_', $userIp);

        $sessionData = [
            'session_key' => $sessionKey,
            'user_id' => $user->id,
            'ip_address' => $userIp,
            'login_time' => $loginTime,
            'expires_at' => now()->addSeconds(30),
        ];

        Cache::put('auth_session_key_' . $user->id, $sessionData, now()->addSeconds(30));

        return $sessionData;
    }

    /**
     * Generate auth response using AuthUserResource
     */
    private function _generateAuthResponse(User $user, string $message, array $sessionData)
    {
        $token = $user->createToken('MyAppToken')->accessToken;

        return response()->json([
            'status' => true,
            'token' => $token,
            'user' => new AuthUserResource($user),
            'session_key' => $sessionData['session_key'],
            'user_ip' => $sessionData['ip_address'],
            'login_time' => $sessionData['login_time'],
            'message' => $message
        ], 200);
    }

    private function modifyNumber($number)
    {
        $mobNumber = (string) $number;
        if (strlen($mobNumber) === 10) {
            $mobNumber = '91' . $mobNumber;
            return $mobNumber;
        } else if (strlen($mobNumber) === 12) {
            return $number;
        }
        return null; // Handle invalid number lengths if needed
    }
    private function generateOTP($length = 6)
    {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= mt_rand(0, 9);
        }
        return $otp;
    }

    public function Backuplogin(Request $request)
    {
        try {
            $email = $request->input('email');
            $password = $request->input('password');
            $ip = file_get_contents('https://api.ipify.org');
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json(['emailError' => 'Wrong email', 'ip' => $ip], 401);
            }

            if ($user->status != 1) {
                return response()->json(['error' => 'Your account has been blocked. Please contact administrator'], 401);
            }

            if (!Hash::check($password, $user->password)) {
                $cacheKey = 'login_attempt_' . $email;
                $attemptData = Cache::get($cacheKey, ['count' => 0, 'last_attempt' => null]);
                $lastAttemptTime = $attemptData['last_attempt'];

                if ($lastAttemptTime && now()->diffInMinutes($lastAttemptTime) < 1) {
                    $attemptData['count']++;
                } else {
                    $attemptData['count'] = 1;
                    $attemptData['last_attempt'] = now();
                }

                Cache::put($cacheKey, $attemptData, now()->addMinutes(1));

                if ($attemptData['count'] >= 5) {
                    return $this->DisableUser($user);
                }

                return response()->json(['code' => 'WP', 'passwordError' => 'Wrong password', 'ip' => $ip], 401);
            }

            Cache::forget('login_attempt_' . $email);
            $token = $user->createToken('MyAppToken')->accessToken;

            $userArray = $user->toArray();

            if ($user->ip_auth === 'true') {
                $userIPs = json_decode($user->ip_addresses);
                if (in_array($ip, $userIPs)) {
                    if ($user->two_fector_auth === 'true') {
                        return response()->json(['token' => $token, 'user' => $userArray, 'ip' => $ip, 'two_factor_auth' => true], 200);
                    } else {
                        return response()->json(['message' => 'Login By Ip', 'token' => $token, 'user' => $userArray, 'ip' => $ip], 200);
                    }
                } else {
                    return response()->json(['ipAuthError' => 'IP authentication failed', 'ip' => $ip], 401);
                }
            }

            if ($user->two_fector_auth === 'true') {
                return response()->json(['token' => $token, 'user' => $userArray, 'ip' => $ip, 'two_factor_auth' => true], 200);
            }

            return response()->json(['status' => true, 'token' => $token, 'user' => $userArray, 'ip' => $ip], 200);
        } catch (\Exception $e) {
            // Return a generic error response
            return response()->json([
                'message' => 'An unexpected error occurred. Please try again later.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    protected function DisableUser($user)
    {
        $user->status = 'inactive';
        $user->save();
        return response()->json(['error' => 'Your account has been blocked. Please contact administrator.'], 429);
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'email' => 'required|email|unique:users,email',
                'number' => 'required|number|unique:users,number',
                'password' => 'required|string|min:6',
            ]);

            $user = new User([
                'name' => $request->name,
                'email' => $request->email,
                'number' => $request->number,
                'password' => Hash::make($request->password),
            ]);

            $user->save();

            return response()->json(['status' => true, 'message' => 'User registered successfully'], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500); // 500 for internal server error
        }
    }

    public function changePassword(Request $request, $id)
    {
        try {
            // Validate request data
            $request->validate([
                'current_password' => 'required',
                'password' => 'required|min:8',
            ]);
            // Get the authenticated user
            $user = User::findOrFail($id);

            // Check if the current password matches the one provided
            if (!Hash::check($request->current_password, $user->password)) {
                throw new \Exception('Current password is incorrect');
            }

            // Update the user's password
            $user->password = Hash::make($request->password);
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully',
                'email' => $user->email
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Send password reset link to user's email
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Return success even if user not found (security best practice)
            return response()->json([
                'status' => true,
                'message' => 'If your email exists in our system, you will receive a password reset link.'
            ]);
        }

        // Generate unique reset token
        $token = Str::random(64);
        $expiryMinutes = 15; // Token valid for 15 minutes

        // Store token in cache
        Cache::put(
            'password_reset_' . $token,
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'created_at' => now(),
            ],
            now()->addMinutes($expiryMinutes)
        );

        // Build reset link
        $frontendUrl = config('app.frontend_url', 'http://192.168.126:3000/auth/reset-password');
        $resetLink = "{$frontendUrl}?token={$token}&email={$user->email}";

        // Send email
        $this->sendForgotPasswordMail($user, $resetLink, $expiryMinutes);

        return response()->json([
            'status' => true,
            'message' => 'If your email exists in our system, you will receive a password reset link.'
        ]);
    }

    /**
     * Reset password using token
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $cacheKey = 'password_reset_' . $request->token;
        $resetData = Cache::get($cacheKey);

        if (!$resetData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired reset token.'
            ], 400);
        }

        // Verify email matches
        if ($resetData['email'] !== $request->email) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid reset token.'
            ], 400);
        }

        $user = User::find($resetData['user_id']);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        // Update password
        $user->password = Hash::make($request->password);
        $user->save();

        // Remove the used token
        Cache::forget($cacheKey);

        // Reset any login protection blocks
        $this->loginProtection->resetFailedAttempts($user, 'password');
        $this->loginProtection->resetFailedAttempts($user, 'otp');

        return response()->json([
            'status' => true,
            'message' => 'Password has been reset successfully. You can now login with your new password.'
        ]);
    }

    /**
     * Send forgot password email using template
     */
    private function sendForgotPasswordMail(User $user, string $resetLink, int $expiryMinutes): void
    {
        // Calculate expiry time display
        $expiryTime = $expiryMinutes >= 60
            ? ($expiryMinutes / 60) . ' hour' . ($expiryMinutes >= 120 ? 's' : '')
            : $expiryMinutes . ' minutes';

        $this->sendTemplateEmail('Forgot Password', $user->email, [
            '{{username}}' => $user->name,
            '{{email}}' => $user->email,
            '{{expiry_time}}' => $expiryTime,
            '{{reset_link}}' => $resetLink,
        ]);
    }

    /**
     * Send email using template with placeholder replacement
     * 
     * @param string $templateId - Template ID/name in email_templates table
     * @param string $email - Recipient email address
     * @param array $placeholders - Key-value pairs for placeholder replacement
     * @param bool $logErrors - Whether to log errors (silent mode for background emails)
     * @return \Illuminate\Http\JsonResponse|void
     */
    private function sendTemplateEmail(string $templateId, string $email, array $placeholders = [], bool $logErrors = true)
    {
        try {
            $emailTemplate = EmailTemplate::where('template_id', $templateId)->first();

            if (!$emailTemplate) {
                if ($logErrors) {
                    Log::error("Email template \"{$templateId}\" not found");
                }
                return response()->json([
                    'status' => false,
                    'message' => 'Email template not found'
                ], 500);
            }

            // Replace placeholders in body
            $body = $emailTemplate->body;
            foreach ($placeholders as $key => $value) {
                $body = str_replace($key, $value, $body);
            }

            // Replace placeholders in subject
            $subject = $emailTemplate->subject;
            foreach ($placeholders as $key => $value) {
                $subject = str_replace($key, $value, $subject);
            }

            $details = [
                'email' => $email,
                'title' => $subject,
                'body' => $body,
            ];

            dispatch(new SendEmailJob($details));

            if ($logErrors) {
                Log::info("Email queued for: {$email} using template: {$templateId}");
            }

            return response()->json([
                'status' => true,
                'message' => 'Email has been queued successfully.'
            ], 200);
        } catch (\Exception $e) {
            if ($logErrors) {
                Log::error("Failed to queue email for template {$templateId}: " . $e->getMessage());
            }
            return response()->json([
                'status' => false,
                'message' => 'Failed to send email.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
