<?php

namespace App\Http\Controllers;

use App\Models\PhonePe;
use App\Models\User;
use App\Services\BookingService;
use App\Services\PhonePeService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\SessionIdService;
use Illuminate\Support\Str;

class PhonePeController extends Controller
{
    protected $phonePeService;
    protected $bookingService, $WebhookService, $sessionIdService;

    public function __construct(PhonePeService $phonePeService, BookingService $bookingService, WebhookService $WebhookService, SessionIdService $sessionIdService)
    {

        $this->bookingService = $bookingService;
        // Inject PhonePeService
        $this->WebhookService = $WebhookService;
        $this->phonePeService = $phonePeService;
        $this->sessionIdService = $sessionIdService;
    }

    public function initiatePayment(Request $request)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:1',
                'user_id' => 'required|string',
                'mobile_number' => 'nullable|string|size:10',
            ]);
            $config = PhonePe::where('user_id', $request->organizer_id)
                ->first();

            if (! $config) {
                $adminId = User::role('Admin')->value('id');
                $config = PhonePe::where('user_id', $adminId)->first();
            }

            $clientId = $config->client_id;
            $clientSecret = $config->client_secret;
            $gateway = 'phonepe';
            $getSession = $this->sessionIdService->generateEncryptedSessionId();
            $session = $getSession['original'];
            $setId = strtoupper('SET-' . Str::random(10));
            // $redirectUrl = url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session);
            $successUrl = url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session . '?status=success');
            $failureUrl = url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session . '?status=failed');
            $cancelUrl = url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session . '?status=cancelled');
            $txnid = 'TXN_' . time() . '_' . Str::random(6);
            $paymentData = [
                'transaction_id' => $txnid,
                'user_id' => $request->user_id,
                'amount' => $request->amount,
                'mobile_number' => $request->mobile_number,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_url' => $successUrl,
                "message" => $session,
                'failure_url' => $failureUrl,
                'cancel_url' => $cancelUrl,
                'environment' => 'production',
                'context' => [
                    'user_id' => $request->user_id,
                    'mobile_number' => $request->mobile_number,
                ]
            ];
            //return $this->config = config('phonepe');
            $response = $this->phonePeService->createPayment($paymentData);

            $bookings = $this->WebhookService->store($request, $session, $txnid, $setId, 'phonepe');
            // $bookings = $this->bookingService->storePendingBookings($request, $session, $txnid,'phonepe');
            if ($bookings['status'] == true) {
                if ($response['success']) {
                    return response()->json([
                        'status' => true,
                        'message' => 'Payment initiated successfully',
                        'transaction_id' => $paymentData['transaction_id'],
                        'url' => $response['data']['redirect_url'] ?? null,
                        'data' => $response['data']
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to initiate payment',
                        'full_message' => $response['message'] ?? 'Unknown error',
                        'error' => $response['error']
                    ], 400);
                }
            } else {
                return response()->json(['status' => false, 'message' => 'Payment Failed'], 400);
            }
        } catch (\Exception $e) {
            Log::error('Payment initiation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handleCallback(Request $request)
    {
        try {
            $callbackData = $request->all();
            Log::info('PhonePe Callback received:', $callbackData);

            $response = $this->phonePeService->validateCallback($callbackData);

            if ($response['success']) {
                // Handle successful payment
                // Update your database, send notifications, etc.

                return response()->json([
                    'success' => true,
                    'message' => 'Callback processed successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Callback validation failed'
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Callback handling error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Callback processing failed'
            ], 500);
        }
    }

    public function handleRedirect(Request $request)
    {
        try {
            $transactionId = $request->get('transactionId');

            if ($transactionId) {
                $status = $this->phonePeService->checkPaymentStatus($transactionId);

                if ($status['success']) {
                    // Redirect to success or failure page based on status
                    $paymentStatus = $status['status'];

                    if ($paymentStatus === 'PAYMENT_SUCCESS') {
                        return redirect('/payment/success')->with('transaction_id', $transactionId);
                    } else {
                        return redirect('/payment/failed')->with('transaction_id', $transactionId);
                    }
                }
            }

            return redirect('/payment/failed')->with('error', 'Invalid transaction');
        } catch (\Exception $e) {
            Log::error('Redirect handling error: ' . $e->getMessage());
            return redirect('/payment/failed')->with('error', 'Payment processing failed');
        }
    }

    public function checkStatus(Request $request)
    {
        try {
            $request->validate([
                'transaction_id' => 'required|string'
            ]);

            $response = $this->phonePeService->checkPaymentStatus($request->transaction_id);

            return response()->json([
                'success' => $response['success'],
                'data' => $response['data'] ?? null,
                'status' => $response['status'] ?? 'UNKNOWN',
                'error' => $response['error'] ?? null
            ]);
        } catch (\Exception $e) {
            Log::error('Status check error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function debugClient()
    {
        try {
            $clientInfo = $this->phonePeService->getClientInfo();
            return response()->json([
                'success' => true,
                'message' => 'PhonePe client debug information',
                'client_info' => $clientInfo
            ]);
        } catch (\Exception $e) {
            Log::error('Debug client error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
