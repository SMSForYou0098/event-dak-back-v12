<?php

namespace App\Http\Controllers;


use App\Models\Razorpay;
use App\Models\User;
use App\Services\BookingService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Razorpay\Api\Api;
use Illuminate\Support\Str;

class RazorPayController extends Controller
{
    protected $api;
    protected $bookingService, $WebhookService;
    public function __construct(BookingService $bookingService, WebhookService $WebhookService)
    {
        $this->bookingService = $bookingService;
        $this->WebhookService = $WebhookService;
    }

    private function generateEncryptedSessionId()
    {
        // Generate a random session ID
        $originalSessionId = \Str::random(32);
        // Encrypt it
        $encryptedSessionId = encrypt($originalSessionId);

        return [
            'original' => $originalSessionId,
            'encrypted' => $encryptedSessionId
        ];
    }

    public function initiatePayment(Request $request)
    {
        try {
            $config = Razorpay::where('user_id', $request->organizer_id)
                ->first();

            if (! $config) {
                $adminId = User::role('Admin')->value('id');
                $config = Razorpay::where('user_id', $adminId)->first();
            }
            // $config = Razorpay::where('user_id', $request->organizer_id)->first();
            // $adminConfig = Razorpay::where('user_id', User::role('Admin')->value('id'))->first();              
            // $apiKey = $config->razorpay_key ?? $adminConfig->razorpay_key;
            // $apiSecret = $config->razorpay_secret ?? $adminConfig->razorpay_secret;
            $apiKey = $config->razorpay_key;
            $apiSecret = $config->razorpay_secret;

            $txnid = random_int(100000000000, 999999999999);

            $categoryData = $request->category;
            $session = $this->generateEncryptedSessionId()['original'];
            $sessionId = $this->generateEncryptedSessionId()['encrypted'];
            $setId = strtoupper('SET-' . Str::random(10));
            $gateway = 'razorpay';
            $request->merge(['gateway' => $gateway]);
            $api = new Api($apiKey, $apiSecret);

            $amountInPaisa = $request->totalFinalAmount * 100;

            $orderData = [
                'receipt' => 'receipt_' . $txnid,
                'amount' => $amountInPaisa,
                'currency' => 'INR',
                'payment_capture' => 1,
                'notes' => [
                    'name' => $request->firstname,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'event_id' => $request->event_id,
                    'category' => $categoryData,
                    'session_id' => $session
                ]
            ];

            $razorpayOrder = $api->order->create($orderData);

            if (!$razorpayOrder || !isset($razorpayOrder['id'])) {
                return response()->json(['status' => false, 'message' => 'Failed to create Razorpay order'], 500);
            }

            // ✅ FIRST save as pending booking
            $this->WebhookService->store($request, $session, $txnid,$setId, 'razorpay');
            // $this->bookingService->storePendingBookings($request, $session, $txnid, 'razorpay');

            // ✅ THEN return order details to frontend
            return response()->json([
                'status'   => true,
                'order_id' => $razorpayOrder['id'],
                'txnid'    => $txnid,
                'amount'   => $amountInPaisa,
                'currency' => 'INR',
                'key'      => $apiKey,
                'prefill'  => [
                    'name'    => $request->firstname,
                    'email'   => $request->email,
                    'contact' => $request->phone,
                ],
                'callback_url' => url('/api/payment-response/razorpay/' . $request->event_id . '/' . $session . '?status=success&category=' . $categoryData),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Configuration not found'], 404);
        } catch (\Throwable $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
