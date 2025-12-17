<?php

namespace App\Http\Controllers;
use App\Models\EasebuzzConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Services\WhatsappService;
use App\Services\WebhookService;

class WebhookController extends Controller
{

    protected $smsService, $whatsappService;

    protected $config;
    protected $url;
    public function __construct(SmsService $smsService, WhatsappService $whatsappService)
    {
        // Retrieve configuration from the database
        $config = EasebuzzConfig::first();

        if (!$config) {
            throw new \Exception('Configuration not found');
        }
        $this->url = 'https://testpay.easebuzz.in/';

        $this->smsService = $smsService;
        $this->whatsappService = $whatsappService;
    }

    public function handlePaymentResponse(Request $request, $gateway, $id, $session_id)
    {
        try {
            $status = $request->input('status');
            $category = $params['category'] ?? null;
            return redirect()->away(env('ALLOWED_DOMAIN') . 'events/' . $id . '/process?status=' . $status . '&session_id=' . $session_id . '&category=' . $category);
        } catch (\Exception $e) {
            Log::error('Payment Response Error', ['gateway' => $gateway, 'error' => $e->getMessage()]);
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Update the main handleWebhook method
    // Update the main handleWebhook method
    public function handleWebhook(Request $request, $gateway)
    {
      // return response()->json($request->all());
        Log::info("[$gateway] Webhook received1212", $request->all());

        try {
            // request & gateway સાથે background job dispatch કરો
            // \App\Jobs\ProcessWebhookJob::dispatch($gateway, $request->all());
		    return app(WebhookService::class)->process($gateway, $request->all());
            // તરત 200 OK return કરો
            return response()->json(['message' => 'Webhook received'], 200);

        } catch (\Exception $e) {
            Log::error("[$gateway] Webhook dispatch failed: " . $e->getMessage());
            return response()->json(['error' => 'Dispatch failed'], 500);
        }
    }
}
