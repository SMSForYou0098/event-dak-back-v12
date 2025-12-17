<?php

namespace App\Http\Controllers;

use App\Models\EasebuzzConfig;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\SmsService;
use App\Services\WebhookService;
use App\Services\WhatsappService;
use Illuminate\Support\Str;


class EasebuzzController extends Controller
{

    protected $smsService, $whatsappService, $WebhookService;

    protected $config;
    protected $url;
    public function __construct(SmsService $smsService, WhatsappService $whatsappService, WebhookService $WebhookService)
    {
        // Retrieve configuration from the database
        $config = EasebuzzConfig::first();

        if (!$config) {
            throw new \Exception('Configuration not found');
        }
        $this->url = 'https://testpay.easebuzz.in/';

        $this->smsService = $smsService;
        $this->whatsappService = $whatsappService;
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
        // return response()->json("hello");

        try {
            include_once(app_path('Services/easebuzz_helper.php'));

            $getSession = $this->generateEncryptedSessionId();
            $session = $getSession['original'];
            $setId = strtoupper('SET-' . Str::random(10));

            $config = EasebuzzConfig::where('user_id', $request->organizer_id)
                ->first();

            if (! $config) {
                $adminId = User::role('Admin')->value('id');
                $config = EasebuzzConfig::where('user_id', $adminId)->first();
            }


            $env = $config->env;
            $key = $config->merchant_key;
            $salt = $config->salt;



            $prod_url = $config->prod_url;
            $test_url = $config->test_url;
            $categoryData = $request->category;

            $headers = [
                "Accept: application/json",
                "Content-Type: application/x-www-form-urlencoded",
            ];

            function generateTxnId()
            {
                return random_int(100000000000, 999999999999);
            }

            $sesstionId = $getSession['encrypted'];
            $txnid = generateTxnId();

            // If amount is 0, directly mark booking as successful
            if ((float)$request->totalFinalAmount <= 0 && $request->quantity > 0) {
                return $this->WebhookService->handleZeroAmountBooking($request, $session, $txnid, $setId);
            }

            $gateway = 'easebuzz';
            $request->merge(['gateway' => $gateway]);
            // return response()->json($gateway);
            $params = [
                "key" => $key,
                "txnid" => $txnid,
                "amount" => number_format($request->totalFinalAmount, 2, '.', ''),
                "productinfo" => $request->event_name,
                "firstname" => $request->user_name,
                "email" => $request->user_email,
                "udf1" => "",
                "udf2" => "",
                "udf3" => "",
                "udf4" => "",
                "udf5" => "",
                "udf6" => "",
                "udf7" => "",
                "udf8" => "",
                "udf9" => "",
                "udf10" => "",
                'phone' => $request->user_phone,
                'furl' => 'https://t.getyourticket.in/events/cart/' . $request->event_id,
                'surl' => 'https://t.getyourticket.in/events/summary/' . $request->event_id
                    . '?status=success'
                    . '&session_id=' . $session
                    . '&category=' . urlencode($categoryData),



                // 'furl' => url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session . '?status=failure&category=' . $categoryData),
                // 'surl' => url('/api/payment-response/' . $gateway . '/' . $request->event_id . '/' . $session . '?status=success&category=' . $categoryData)
            ];

            // Initiate payment
            $paymentParams = initiate_payment($params, false, $key, $salt, $env);

            // Store booking information
            if ($request->category === 'Amusement') {
                $bookings = $this->WebhookService->storeEmusment($request, $session, $txnid, $setId);
            } else {
                //return response()->json($request->all());
                $bookings = $this->WebhookService->store($request, $session, $txnid, $setId);
                // return $bookings;
            }

            $url = ($env == 'test')
                ? ($config->test_url ?? $adminConfig->test_url ?? null)
                : ($config->prod_url ?? $adminConfig->prod_url ?? null);

            if ($bookings instanceof \Illuminate\Http\JsonResponse) {
                $bookings = $bookings->getData(true);
            }

            // Check booking status
            if (isset($bookings['status']) && $bookings['status'] === true) {
                // if ($bookings->original['status'] == true) {
                // return $bookings;
                return response()->json(['result' => $paymentParams, 'txnid' => $txnid, 'url' => $url . $paymentParams['data']]);
            } else {
                return response()->json(['status' => false, 'message' => 'Payment Failed'], 400);
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => false, 'message' => 'Configuration not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
