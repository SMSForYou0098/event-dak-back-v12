<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Booking;
use App\Models\PenddingBooking;
use App\Models\Attndy;
use App\Models\BookingTax;
use App\Models\CardBooking;
use App\Models\EasebuzzConfig;
use App\Models\EventSeatStatus;
use App\Models\MasterBooking;
use App\Models\PaymentLog;
use App\Models\PenddingBookingsMaster;
use App\Models\PromoCode;
use App\Models\Ticket;
use App\Models\WhatsappApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebhookService
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


    public function process($gateway, $params)
    {
        Log::info("[$gateway] Processing webhook in service...", $params);

        try {
            $sessionId = null;
            $category = null;
            $status = null;
            $paymentId = null;
            $urlData = null;

            // ---- PhonePe ----
            if ($gateway === 'phonepe') {
                $webhookData = $this->extractPhonePeWebhookData($params);

                $sessionId = $webhookData['session_id'];
                $category = $webhookData['category'];
                $status = $webhookData['status'];
                $paymentId = $webhookData['payment_id'];

                // Format params for consistent logging
                $params = array_merge($params, [
                    'status' => $status,
                    'amount' => $webhookData['amount'],
                    'mode' => $webhookData['mode'],
                    'merchant_order_id' => $webhookData['merchant_order_id'],
                    'order_id' => $webhookData['order_id'],
                    'utr' => $webhookData['utr'],
                    'category' => $category
                ]);
            }

            // ---- Razorpay ----
            elseif ($gateway === 'razorpay') {
                $webhookData = $this->extractRazorpayWebhookData($params);

                $sessionId = $webhookData['session_id'];
                $category = $webhookData['category'];
                $status = $webhookData['status'];
                $paymentId = $webhookData['payment_id'];

                // Format params for consistent logging
                $params = array_merge($params, [
                    'status' => $status,
                    'amount' => $webhookData['amount'],
                    'order_id' => $webhookData['order_id'],
                    'payment_id' => $webhookData['payment_id'],
                    'method' => $webhookData['method'],
                    'event' => $webhookData['event'],
                    'category' => $category
                ]);
            }
            // ---- Cashfree ----
            elseif ($gateway === 'cashfree') {
                $webhookData = $this->extractCashfreeWebhookData(new Request($params));
                $sessionId = $webhookData['session_id'];
                $category = $webhookData['category'];
                $status = $webhookData['status'];
                $paymentId = $webhookData['payment_id'];

                $params = array_merge($params, [
                    'status' => $status,
                    'amount' => $webhookData['amount'],
                    'order_id' => $webhookData['order_id'],
                    'payment_id' => $webhookData['payment_id'],
                    'mode' => $webhookData['mode'],
                    'category' => $category,
                    'raw_payload' => $webhookData['raw_payload'] ?? null
                ]);
            }

            // ---- Easebuzz ----
            elseif ($gateway === 'easebuzz') {
                $statusRaw = $params['status'] ?? null;
                $status = strtolower(trim($statusRaw));
                if (!$status) {
                    Log::warning("[$gateway] Missing 'status' in webhook.");
                    return response()->json(['error' => 'Missing status field'], 400);
                }

                $paymentId = $params['easepayid'] ?? null;

                //return response()->json(['$paymentId' => $paymentId], 400);
                // Extract session ID from surl for Easebuzz
                if (!isset($params['surl'])) {
                    Log::warning("[$gateway] Missing 'surl' in webhook.");
                    return response()->json(['error' => 'Missing surl'], 400);
                }
                $urlData = $this->extractLastPathSegment($params['surl']);
                //return $urlData;
            }
            // ---- Instamojo ----
            elseif ($gateway === 'instamojo') {
                $statusRaw = $params['status'] ?? null;
                $status = strtolower(trim($statusRaw));
                $paymentId = $params['payment_id'] ?? null;

                // Extract session ID and category from URL parameters
                if (!isset($params['sessionId']) || !isset($params['category'])) {
                    Log::warning("[$gateway] Missing session_id or category in webhook URL.");
                    return response()->json(['error' => 'Missing required parameters'], 400);
                }

                try {
                    $urlData = [
                        'session_id' => $params['sessionId'],
                        'category' => urldecode($params['category'])
                    ];
                } catch (\Exception $e) {
                    Log::error("[$gateway] Parameter processing failed: " . $e->getMessage());
                    return response()->json(['error' => 'Invalid parameters'], 400);
                }
            }
            //return $sessionId;
            // ---- Status normalize for non-phonepe/razorpay/cashfree ----
            if (!in_array($gateway, ['phonepe', 'razorpay', 'cashfree'])) {
                $successStatuses = ['success', 'credit', 'completed', 'paid'];
                $failureStatuses = ['failed', 'failure', 'error', 'cancelled', 'declined'];

                if (in_array($status, $successStatuses)) {
                    $status = 'success';
                } elseif (in_array($status, $failureStatuses)) {
                    $status = 'failed';
                } else {
                    Log::warning("[$gateway] Unknown status value: $status");
                    return response()->json(['error' => 'Unknown status value'], 400);
                }

                // Extract session and category for non-PhonePe, non-Razorpay, and non-Cashfree gateways
                if (isset($urlData)) {
                    $sessionId = $urlData['session_id'];
                    $category = $urlData['category'];
                }
            }

            // Validate required data
            if (!$paymentId) {
                Log::warning("[$gateway] Missing payment ID.");
                return response()->json(['error' => 'Missing payment ID'], 400);
            }

            if (!$sessionId || !$category) {
                Log::warning("[$gateway] Invalid or incomplete data format - Session: $sessionId, Category: $category");
                return response()->json(['error' => 'Invalid data format - missing session_id or category'], 400);
            }

            // Set default mode for non-PhonePe and non-Razorpay gateways
            if (!in_array($gateway, ['phonepe', 'razorpay', 'cashfree'])) {
                $params['mode'] = $params['mode'] ?? 'NA';
            }

            // Store payment log
            try {
                $this->storePaymentLog($gateway, $sessionId, $params);
                Log::info("[$gateway] Payment log stored successfully for session: $sessionId");
            } catch (\Exception $e) {
                Log::error("[$gateway] Failed to store payment log: " . $e->getMessage());
                return response()->json(['error' => 'Failed to store payment log'], 500);
            }

            // Check for duplicate webhook
            if ($this->checkExistingBooking($sessionId, $paymentId)) {
                Log::warning("[$gateway] Duplicate webhook received for session_id: $sessionId and payment_id: $paymentId");
                return response()->json(['message' => 'Webhook already processed'], 200);
            }

            // Trigger appropriate handler
            if ($category === 'Amusement') {
                Log::info("[$gateway] Processing amusement booking transfer - Session: $sessionId, Status: $status, Payment: $paymentId");
                $result = $this->transferAmusementBooking($sessionId, $status, $paymentId);

                if ($result === false) {
                    Log::error("[$gateway] Amusement booking transfer failed for session: $sessionId");
                    return response()->json(['error' => 'Amusement booking transfer failed'], 500);
                }

                Log::info("[$gateway] Amusement booking transfer completed successfully for session: $sessionId");
                return response()->json([
                    'message' => 'Amusement webhook processed successfully',
                    'session_id' => $sessionId,
                    'status' => $status
                ], 200);
            } else {
                Log::info("[$gateway] Processing event booking transfer - Session: $sessionId, Status: $status, Payment: $paymentId");
                return $this->transferEventBooking($sessionId, $status, $paymentId);
            }
        } catch (\Exception $e) {
            Log::error("[$gateway] Webhook processing failed: " . $e->getMessage(), [
                'exception' => $e,
                'request' => $params,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    private function extractLastPathSegment($url)
    {
        try {
            $parsedUrl = parse_url($url);
            if (!$parsedUrl) {
                Log::error("[extractSessionData] Failed to parse URL: $url");
                return null;
            }

            // Extract last path segment (e.g., AA00001)
            $path = $parsedUrl['path'] ?? '';
            $segments = explode('/', trim($path, '/'));
            $lastSegment = end($segments);

            // Extract query parameters
            $query = [];
            if (isset($parsedUrl['query'])) {
                $queryString = html_entity_decode($parsedUrl['query']);
                parse_str($queryString, $query);
            }

            // Get session_id and category
            $sessionId = $query['session_id'] ?? null;
            $category = $query['category'] ?? null;

            if (!$sessionId) {
                Log::warning("[extractSessionData] No session_id found in URL: $url");
            }

            $result = [
                'session_id' => $sessionId,
                'category' => $category,
                'last_segment' => $lastSegment,
            ];

            Log::info("[extractSessionData] Extracted data", $result);
            return $result;
        } catch (\Exception $e) {
            Log::error("[extractSessionData] Exception: " . $e->getMessage());
            return null;
        }
    }


    private function checkExistingBooking($sessionId, $paymentId)
    {
        // Check in regular bookings
        $existingBooking = Booking::where('session_id', $sessionId)
            ->where('payment_id', $paymentId)
            ->exists();

        if ($existingBooking) {
            Log::info("[checkExistingBooking] Found existing booking regarding to these data", [
                'session_id' => $sessionId,
                'payment_id' => $paymentId
            ]);
            return true;
        }

        // Check in amusement bookings
        $existingAmusementBooking = AmusementBooking::where('session_id', $sessionId)
            ->where('payment_id', $paymentId)
            ->exists();

        if ($existingAmusementBooking) {
            Log::info("[checkExistingBooking] Found existing booking in AmusementBooking table", [
                'session_id' => $sessionId,
                'payment_id' => $paymentId
            ]);
            return true;
        }

        return false;
    }

    // Add this private method to extract PhonePe webhook data
    private function extractPhonePeWebhookData($request)
    {
        try {
            $params = $request->all();

            // Check if it's a PhonePe webhook structure
            if (!isset($params['type']) || !isset($params['payload'])) {
                throw new \Exception('Invalid PhonePe webhook structure');
            }

            $payload = $params['payload'];

            // Extract basic information
            $merchantOrderId = $payload['merchantOrderId'] ?? null;
            $state = $payload['state'] ?? null;
            $amount = $payload['amount'] ?? null;

            // Determine status based on PhonePe state
            $status = 'unknown';
            switch (strtoupper($state)) {
                case 'COMPLETED':
                case 'SUCCESS':
                    $status = 'success';
                    break;
                case 'FAILED':
                case 'CANCELLED':
                case 'EXPIRED':
                    $status = 'failed';
                    break;
                case 'PENDING':
                    $status = 'pending';
                    break;
                default:
                    $status = 'unknown';
            }

            // Extract payment details
            $paymentDetails = $payload['paymentDetails'][0] ?? null;
            $transactionId = $paymentDetails['transactionId'] ?? $payload['orderId'] ?? null;
            $paymentMode = $paymentDetails['paymentMode'] ?? 'phonepe';

            // Extract UTR if available
            $utr = null;
            if (isset($paymentDetails['rail']['utr'])) {
                $utr = $paymentDetails['rail']['utr'];
            }

            // For PhonePe, we need to extract session ID from merchantOrderId
            $sessionId = $this->extractSessionFromMerchantOrderId($merchantOrderId);

            // Determine category
            $category = $this->determineCategoryFromMerchantOrderId($merchantOrderId);

            Log::info('[PhonePe] Extracted webhook data', [
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $status
            ]);

            return [
                'payment_id' => $transactionId,
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $status,
                'amount' => $amount,
                'mode' => $paymentMode,
                'merchant_order_id' => $merchantOrderId,
                'order_id' => $payload['orderId'] ?? null,
                'utr' => $utr,
                'timestamp' => $paymentDetails['timestamp'] ?? time(),
                'raw_payload' => $params
            ];
        } catch (\Exception $e) {
            Log::error('PhonePe webhook data extraction failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function extractCashfreeWebhookData($request)
    {
        try {
            $data = $request->all();

            $orderId = $data['data']['order']['order_id'] ?? null;
            $paymentId = $data['data']['payment']['cf_payment_id'] ?? null;
            $statusRaw = $data['data']['payment']['payment_status'] ?? null;
            $amount = $data['data']['payment']['payment_amount'] ?? null;
            $paymentMode = $data['data']['payment']['payment_group'] ?? null;

            // ✅ Normalize status
            $status = match (strtoupper($statusRaw)) {
                'SUCCESS', 'PAID' => 'success',
                'FAILED', 'CANCELLED' => 'failed',
                default => 'pending'
            };

            // ✅ Extract session_id from order_id
            $sessionId = $orderId;

            $pending = PenddingBooking::with('ticket.event.Category')
                ->where('session_id', $sessionId)
                ->first();

            $category = optional($pending->ticket?->event?->Category)->title ?? 'Event';

            Log::info('[Cashfree] Extracted webhook data', [
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $status
            ]);

            return [
                'payment_id' => $paymentId,
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $status,
                'amount' => $amount,
                'mode' => $paymentMode,
                'order_id' => $orderId,
                'raw_payload' => $data
            ];
        } catch (\Exception $e) {
            Log::error('Cashfree webhook data extraction failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // Helper method to extract session ID from merchant order ID
    private function extractSessionFromMerchantOrderId($merchantOrderId)
    {
        // Option 1: If you store it in pending bookings
        $pendingBooking = PenddingBooking::where('txnid', $merchantOrderId)->first();
        if ($pendingBooking) {
            Log::info('[extractSessionFromMerchantOrderId] Found in PenddingBooking', [
                'session_id' => $pendingBooking->session_id
            ]);
            return $pendingBooking->session_id;
        }

        // Option 2: If you store it in amusement pending bookings
        $amusementPendingBooking = AmusementPendingBooking::where('txnid', $merchantOrderId)->first();
        if ($amusementPendingBooking) {
            Log::info('[extractSessionFromMerchantOrderId] Found in AmusementPendingBooking', [
                'session_id' => $amusementPendingBooking->session_id
            ]);
            return $amusementPendingBooking->session_id;
        }

        Log::warning("Could not find session_id for merchant order ID: " . $merchantOrderId);
        return null;
    }

    private function extractRazorpayWebhookData($request)
    {
        try {
            $params = $request->all();

            if (!isset($params['event']) || !isset($params['payload'])) {
                throw new \Exception('Invalid Razorpay webhook structure');
            }

            $event = $params['event'];
            $payload = $params['payload'];

            $paymentId = null;
            $orderId = null;
            $amount = null;
            $status = null;
            $method = 'razorpay';
            $sessionId = null;
            $category = null;

            if ($event === 'payment.captured') {
                // ✅ Handle normal checkout flow
                $payment = $payload['payment']['entity'] ?? null;
                if (!$payment) {
                    throw new \Exception('Missing payment entity in webhook payload');
                }

                $paymentId = $payment['id'] ?? null;
                $orderId = $payment['order_id'] ?? null;
                $amount = ($payment['amount'] ?? 0) / 100;
                $status = $payment['status'] ?? null;
                $method = $payment['method'] ?? 'razorpay';

                // session & category from order notes
                $notes = $payment['notes'] ?? [];
                $sessionId = $notes['session_id'] ?? null;
                $category = $notes['category'] ?? null;
            } elseif ($event === 'payment_link.paid') {
                // ✅ Handle payment link flow
                $paymentLink = $payload['payment_link']['entity'] ?? null;
                $payment = $payload['payment']['entity'] ?? null;

                if (!$paymentLink || !$payment) {
                    throw new \Exception('Missing payment_link or payment entity');
                }

                $paymentId = $payment['id'] ?? null;
                $orderId = $payment['order_id'] ?? null;
                $amount = ($payment['amount'] ?? 0) / 100;
                $status = $payment['status'] ?? null;
                $method = $payment['method'] ?? 'razorpay';

                $callbackUrl = $paymentLink['callback_url'] ?? null;
                if ($callbackUrl) {
                    $sessionId = $this->extractSessionFromCallbackUrl($callbackUrl);
                    $category = $this->extractCategoryFromCallbackUrl($callbackUrl);
                }
            } else {
                Log::info("Razorpay webhook event '{$event}' ignored");
                throw new \Exception("Event '{$event}' not supported");
            }

            // ✅ Booking status
            $bookingStatus = ($status === 'captured') ? 'success' : 'failed';

            Log::info('[Razorpay] Extracted webhook data', [
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $bookingStatus
            ]);

            return [
                'payment_id' => $paymentId,
                'session_id' => $sessionId,
                'category' => $category,
                'status' => $bookingStatus,
                'amount' => $amount,
                'method' => $method,
                'order_id' => $orderId,
                'event' => $event,
                'raw_payload' => $params
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay webhook data extraction failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    // Helper method to extract session ID from Razorpay callback URL
    private function extractSessionFromCallbackUrl($callbackUrl)
    {
        try {
            $parsedUrl = parse_url($callbackUrl);
            if (!$parsedUrl || !isset($parsedUrl['path'])) {
                throw new \Exception('Invalid callback URL format');
            }

            // Extract path segments
            $pathSegments = explode('/', trim($parsedUrl['path'], '/'));

            // Find the session ID (should be the last segment before query parameters)
            if (count($pathSegments) >= 4) {
                $sessionId = end($pathSegments);

                if (!empty($sessionId)) {
                    Log::info('[extractSessionFromCallbackUrl] Extracted session_id', [
                        'session_id' => $sessionId
                    ]);
                    return $sessionId;
                }
            }

            Log::warning("Could not extract session_id from callback URL: " . $callbackUrl);
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to extract session ID from callback URL: ' . $e->getMessage());
            return null;
        }
    }

    // Helper method to extract category from Razorpay callback URL
    private function extractCategoryFromCallbackUrl($callbackUrl)
    {
        try {
            $parsedUrl = parse_url($callbackUrl);

            if (!$parsedUrl || !isset($parsedUrl['query'])) {
                return 'Event'; // Default fallback
            }

            // Parse query string
            parse_str($parsedUrl['query'], $queryParams);

            // Extract category from query parameters
            $category = $queryParams['category'] ?? 'Event';

            // URL decode the category
            $category = urldecode($category);

            // Map category names if needed
            if (stripos($category, 'amusement') !== false) {
                return 'Amusement';
            } elseif (stripos($category, 'business') !== false || stripos($category, 'conference') !== false) {
                return 'Event';
            }

            Log::info('[extractCategoryFromCallbackUrl] Extracted category', [
                'category' => $category
            ]);

            return $category ?: 'Event';
        } catch (\Exception $e) {
            Log::error('Failed to extract category from callback URL: ' . $e->getMessage());
            return 'Event';
        }
    }

    // Helper method to determine category from merchant order ID
    private function determineCategoryFromMerchantOrderId($merchantOrderId)
    {
        // Check in pending bookings first
        $pendingBooking = PenddingBooking::where('txnid', $merchantOrderId)->first();
        if ($pendingBooking) {
            Log::info('[determineCategoryFromMerchantOrderId] Category: Event (from PenddingBooking)');
            return 'Event';
        }

        // Check in amusement pending bookings
        $amusementPendingBooking = AmusementPendingBooking::where('txnid', $merchantOrderId)->first();
        if ($amusementPendingBooking) {
            Log::info('[determineCategoryFromMerchantOrderId] Category: Amusement (from AmusementPendingBooking)');
            return 'Amusement';
        }

        // Default fallback
        Log::info('[determineCategoryFromMerchantOrderId] Category: Event (default)');
        return 'Event';
    }

    private function storePaymentLog($gateway, $sessionId, $params)
    {
        try {
            if ($gateway == 'phonepe') {
                $paymentData = [
                    'session_id' => $sessionId ?? null,
                    'payment_id' => $params['payment_id'] ?? $params['order_id'] ?? null,
                    'amount' => $params['amount'] ?? null,
                    'status' => $params['status'] ?? null,
                    'txnid' => $params['merchant_order_id'] ?? null,
                    'mode' => $params['mode'] ?? 'phonepe',
                    'addedon' => isset($params['timestamp']) ? date('Y-m-d H:i:s', $params['timestamp'] / 1000) : now()->toDateTimeString(),
                    'params' => $params,
                    'category' => $params['category'] ?? null,
                ];
            } elseif ($gateway == 'easebuzz') {
                $paymentData = [
                    'session_id' => $sessionId ?? null,
                    'payment_id' => $params['easepayid'] ?? null,
                    'amount' => $params['amount'] ?? null,
                    'status' => $params['status'] ?? null,
                    'txnid' => $params['txnid'] ?? null,
                    'mode' => $params['mode'] ?? null,
                    'addedon' => $params['addedon'] ?? null,
                    'params' => $params,
                    'category' => $params['category'] ?? null,
                ];
            } elseif ($gateway == 'instamojo') {
                $paymentData = [
                    'session_id' => $sessionId,
                    'payment_id' => $params['payment_id'] ?? null,
                    'txnid' => $params['payment_request_id'] ?? null,
                    'amount' => $params['amount'] ?? null,
                    'status' => $params['status'] ?? null,
                    'params' => $params,
                    'category' => $params['category'] ?? null,
                ];
            } elseif ($gateway == 'razorpay') {
                $paymentData = [
                    'session_id' => $sessionId,
                    'payment_id' => $params['payment_id'] ?? null,
                    'txnid' => $params['order_id'] ?? null,
                    'amount' => $params['amount'] ?? null,
                    'status' => $params['status'] ?? null,
                    'mode' => $params['method'] ?? 'razorpay',
                    'addedon' => now()->toDateTimeString(),
                    'params' => $params,
                    'category' => $params['category'] ?? null,
                ];
            } elseif ($gateway == 'cashfree') {
                $paymentData = [
                    'session_id' => $sessionId ?? null,
                    'payment_id' => $params['payment_id'] ?? $params['cf_payment_id'] ?? null,
                    'txnid' => $params['order_id'] ?? $params['cf_order_id'] ?? null,
                    'amount' => $params['amount'] ?? null,
                    'status' => $params['status'] ?? null,
                    'mode' => $params['payment_mode'] ?? 'cashfree',
                    'addedon' => $params['payment_time'] ?? now()->toDateTimeString(),
                    'params' => $params,
                    'category' => $params['category'] ?? null,
                ];
            } else {
                throw new \Exception("Unsupported payment gateway: " . $gateway);
            }

            // Log payment data
            Log::info('Payment Log: ' . ucfirst($gateway), ['data' => $paymentData]);

            // Update pending bookings
            if ($sessionId || isset($params['easepayid']) || isset($params['payment_id'])) {
                $updateData = [
                    'payment_id' => $paymentData['payment_id'],
                    'payment_status' => $paymentData['status'],
                    'payment_method' => $paymentData['mode'] ?? $gateway
                ];
                $updated = PenddingBooking::where('session_id', $sessionId)->update($updateData);
                Log::info('[storePaymentLog] Updated PenddingBooking records', [
                    'session_id' => $sessionId,
                    'rows_updated' => $updated
                ]);
            }

            // Insert or update PaymentLog
            $existing = PaymentLog::where('txnid', $paymentData['txnid'])->first();
            if ($existing) {
                $existing->update($paymentData);
                $result = $existing->fresh();
                Log::info('[storePaymentLog] Updated existing PaymentLog', ['id' => $result->id]);
            } else {
                $result = PaymentLog::create($paymentData);
                Log::info('[storePaymentLog] Created new PaymentLog', ['id' => $result->id]);
            }

            return [$existing, $result];
        } catch (\Exception $e) {
            Log::error('[storePaymentLog] Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function transferAmusementBooking($decryptedSessionId, $status, $paymentId)
    {
        try {
            Log::info('[transferAmusementBooking] Starting transfer', [
                'session_id' => $decryptedSessionId,
                'status' => $status,
                'payment_id' => $paymentId
            ]);

            $bookingMaster = AmusementPendingMasterBooking::where('session_id', $decryptedSessionId)
                ->with('ticket.event')
                ->get();

            $bookings = AmusementPendingBooking::where('session_id', $decryptedSessionId)
                ->with('ticket.event')
                ->get();

            if ($bookings->isEmpty()) {
                Log::error('[transferAmusementBooking] No pending bookings found', [
                    'session_id' => $decryptedSessionId
                ]);
                return false;
            }

            Log::info('[transferAmusementBooking] Found pending bookings', [
                'count' => $bookings->count()
            ]);

            $totalQty = $bookings->count();

            if ($totalQty > 1 && $bookingMaster->isNotEmpty()) {
                $orderId = $bookingMaster->first()->order_id ?? '';
            } else {
                $orderId = $bookings[0]->token ?? '';
            }

            $shortLink = $orderId;
            $shortLinksms = "t.getyourticket.in/t/{$orderId}";
            $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
            $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

            $dates = explode(',', $bookings[0]->ticket->event->date_range);
            $formattedDates = [];
            foreach ($dates as $date) {
                $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
            }
            $dateRangeFormatted = implode(' | ', $formattedDates);

            $eventDateTime = $dateRangeFormatted . ' | ' . $bookings[0]->ticket->event->start_time . ' - ' . $bookings[0]->ticket->event->end_time;

            $totalQty = count($bookings) ?? 1;
            $mediaurl = $bookings[0]->ticket->event->eventMedia->thumbnail;
            $data = (object) [
                'name' => $bookings[0]->name,
                'number' => $bookings[0]->number,
                'templateName' => 'Online Booking Template',
                'whatsappTemplateData' => $whatsappTemplateName,
                'mediaurl' => $mediaurl,
                'shortLink' => $shortLink,
                'insta_whts_url' => $bookings[0]->ticket->event->insta_whts_url ?? 'helloinsta',
                'values' => [
                    $bookings[0]->name,
                    $bookings[0]->number,
                    $bookings[0]->ticket->event->name,
                    $totalQty,
                    $bookings[0]->ticket->name,
                    $bookings[0]->ticket->event->venue->address,
                    $eventDateTime,
                    $bookings[0]->ticket->event->whts_note ?? 'hello',
                ],
                'replacements' => [
                    ':C_Name' => $bookings[0]->name,
                    ':T_QTY' => $totalQty,
                    ':Ticket_Name' => $bookings[0]->ticket->name,
                    ':Event_Name' => $bookings[0]->ticket->event->name,
                    ':Event_Date' => $eventDateTime,
                    ':S_Link' => $shortLinksms,
                ]
            ];

            $masterBookingIDs = [];

            if ($bookings->isNotEmpty()) {
                foreach ($bookings as $individualBooking) {
                    if ($status === 'success') {
                        $oldPendingBookingId = $individualBooking->id; // ✅ Store old ID

                        $booking = $this->amusementBookingData($individualBooking, $paymentId);

                        if ($booking) {
                            $masterBookingIDs[] = $booking->id;

                            // ✅ UPDATE BOOKING TAX for amusement bookings
                            $taxUpdated = BookingTax::where('booking_id', $oldPendingBookingId)
                                ->where('type', 'online')
                                ->update(['booking_id' => $booking->id]);

                            if ($taxUpdated) {
                                Log::info('[transferAmusementBooking] BookingTax updated successfully', [
                                    'old_booking_id' => $oldPendingBookingId,
                                    'new_booking_id' => $booking->id,
                                    'rows_updated' => $taxUpdated
                                ]);
                            } else {
                                Log::warning('[transferAmusementBooking] No BookingTax found to update', [
                                    'old_booking_id' => $oldPendingBookingId
                                ]);
                            }

                            $individualBooking->delete();

                            Log::info('[transferAmusementBooking] Booking transferred successfully', [
                                'booking_id' => $booking->id
                            ]);
                        } else {
                            Log::error('[transferAmusementBooking] Failed to create booking');
                            return false;
                        }
                    } elseif ($status === 'failure' || $status === 'failed') {
                        $individualBooking->payment_status = 2;
                        $individualBooking->payment_id = $paymentId;
                        Log::info('[transferAmusementBooking] Marking booking as failed', [
                            'pending_booking_id' => $individualBooking->id
                        ]);
                    } else {
                        $individualBooking->payment_status = $status;
                    }
                    $individualBooking->save();
                }
            }

            // ✅ UPDATE AMUSEMENT MASTER BOOKING TAX
            if ($bookingMaster->isNotEmpty() && $status === 'success') {
                $oldPendingMasterId = $bookingMaster->first()->id; // ✅ Store old master ID

                $newMaster = $this->updateAmusementMasterBooking($bookingMaster, $masterBookingIDs, $paymentId);

                if ($newMaster) {
                    // ✅ UPDATE MASTER BOOKING TAX
                    $masterTaxUpdated = BookingTax::where('booking_id', $oldPendingMasterId)
                        ->where('type', 'online_master')
                        ->update(['booking_id' => $newMaster->id]);

                    if ($masterTaxUpdated) {
                        Log::info('[transferAmusementBooking] Master BookingTax updated successfully', [
                            'old_master_id' => $oldPendingMasterId,
                            'new_master_id' => $newMaster->id,
                            'rows_updated' => $masterTaxUpdated
                        ]);
                    } else {
                        Log::warning('[transferAmusementBooking] No Master BookingTax found to update', [
                            'old_master_id' => $oldPendingMasterId
                        ]);
                    }

                    $bookingMaster->each->delete();
                    Log::info('[transferAmusementBooking] Master booking updated and pending master deleted');
                } else {
                    Log::error('[transferAmusementBooking] Failed to update master booking');
                    return false;
                }
            }

            // Send SMS & WhatsApp
            if ($status === 'success') {
                try {
                    $this->smsService->send($data);
                    $this->whatsappService->send($data);
                    Log::info('[transferAmusementBooking] Notifications sent successfully');
                } catch (\Exception $e) {
                    Log::error('[transferAmusementBooking] Failed to send notifications: ' . $e->getMessage());
                }
            }

            Log::info('[transferAmusementBooking] Transfer completed successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('[transferAmusementBooking] Exception occurred: ' . $e->getMessage(), [
                'session_id' => $decryptedSessionId,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function transferEventBooking($decryptedSessionId, $status, $paymentId)
    {
        try {
            Log::info('[transferEventBooking] Starting transfer', [
                'session_id' => $decryptedSessionId,
                'status' => $status,
                'payment_id' => $paymentId
            ]);

            $bookingMaster = PenddingBookingsMaster::where('session_id', $decryptedSessionId)
                ->with('ticket.event')
                ->get();

            $bookings = PenddingBooking::where('session_id', $decryptedSessionId)
                ->with('ticket.event')
                ->get();

            if ($bookings->isEmpty()) {
                Log::error('[transferEventBooking] No pending bookings found', [
                    'session_id' => $decryptedSessionId
                ]);
                return response()->json([
                    'error' => 'No pending bookings found',
                    'session_id' => $decryptedSessionId
                ], 404);
            }

            Log::info('[transferEventBooking] Found pending bookings', [
                'count' => $bookings->count()
            ]);

            $totalQty = $bookings->count();

            if ($totalQty > 1 && $bookingMaster->isNotEmpty()) {
                $orderId = $bookingMaster->first()->order_id ?? '';
            } else {
                $orderId = $bookings[0]->token ?? '';
            }

            $shortLink = $orderId;
            $shortLinksms = "t.getyourticket.in/t/{$orderId}";
            $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
            $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

            $dates = explode(',', $bookings[0]->ticket->event->date_range);
            $formattedDates = [];
            foreach ($dates as $date) {
                $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
            }
            $dateRangeFormatted = implode(' | ', $formattedDates);

            $eventDateTime = $dateRangeFormatted . ' | ' . $bookings[0]->ticket->event->start_time . ' - ' . $bookings[0]->ticket->event->end_time;

            $totalQty = count($bookings) ?? 1;
            $mediaurl = $bookings[0]->ticket->event->thumbnail;
            $data = (object) [
                'name' => $bookings[0]->name,
                'number' => $bookings[0]->number,
                'templateName' => 'Online Booking Template',
                'whatsappTemplateData' => $whatsappTemplateName,
                'mediaurl' => $mediaurl,
                'shortLink' => $shortLink,
                'insta_whts_url' => $bookings[0]->ticket->event->insta_whts_url ?? 'helloinsta',
                'values' => [
                    $bookings[0]->name,
                    $bookings[0]->number,
                    $bookings[0]->ticket->event->name,
                    $totalQty,
                    $bookings[0]->ticket->name,
                    $bookings[0]->ticket->event->address,
                    $eventDateTime,
                    $bookings[0]->ticket->event->whts_note ?? 'hello',
                ],
                'replacements' => [
                    ':C_Name' => $bookings[0]->name,
                    ':T_QTY' => $totalQty,
                    ':Ticket_Name' => $bookings[0]->ticket->name,
                    ':Event_Name' => $bookings[0]->ticket->event->name,
                    ':Event_Date' => $eventDateTime,
                    ':S_Link' => $shortLinksms,
                ]
            ];

            $masterBookingIDs = [];

            if ($bookings->isNotEmpty()) {
                foreach ($bookings as $individualBooking) {
                    try {
                        if ($status === 'success') {
                            $oldPendingBookingId = $individualBooking->id; // ✅ Store old ID

                            $booking = $this->bookingData($individualBooking, $paymentId);

                            if ($booking) {
                                $masterBookingIDs[] = $booking->id;

                                // ✅ UPDATE BOOKING TAX - Swap pending booking_id with new booking_id
                                $taxUpdated = BookingTax::where('booking_id', $oldPendingBookingId)
                                    ->where('type', 'online')
                                    ->update(['booking_id' => $booking->id]);

                                if ($taxUpdated) {
                                    Log::info('[transferEventBooking] BookingTax updated successfully', [
                                        'old_booking_id' => $oldPendingBookingId,
                                        'new_booking_id' => $booking->id,
                                        'rows_updated' => $taxUpdated
                                    ]);
                                } else {
                                    Log::warning('[transferEventBooking] No BookingTax found to update', [
                                        'old_booking_id' => $oldPendingBookingId
                                    ]);
                                }

                                $individualBooking->delete();

                                Log::info('[transferEventBooking] Booking transferred successfully', [
                                    'booking_id' => $booking->id,
                                    'pending_booking_id' => $oldPendingBookingId,
                                ]);
                            } else {
                                $errorMessage = 'Booking creation failed: bookingData() returned null';
                                Log::error('[transferEventBooking] ' . $errorMessage, [
                                    'pending_booking_id' => $individualBooking->id,
                                ]);

                                return response()->json([
                                    'status' => 'error',
                                    'message' => $errorMessage,
                                    'pending_booking_id' => $individualBooking->id
                                ], 500);
                            }
                        } elseif (in_array($status, ['failure', 'failed'])) {
                            $individualBooking->payment_status = 2;
                            $individualBooking->payment_id = $paymentId;

                            Log::info('[transferEventBooking] Marking booking as failed', [
                                'pending_booking_id' => $individualBooking->id
                            ]);
                        } else {
                            $individualBooking->payment_status = $status;
                        }

                        $individualBooking->save();
                    } catch (\Throwable $e) {
                        Log::error('[transferEventBooking] Exception during booking transfer', [
                            'pending_booking_id' => $individualBooking->id,
                            'error_message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => 'Booking transfer failed',
                            'error' => [
                                'message' => $e->getMessage(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine(),
                                'trace' => explode("\n", $e->getTraceAsString()),
                            ],
                            'pending_booking_id' => $individualBooking->id,
                        ], 500);
                    }
                }
            }

            // ✅ UPDATE MASTER BOOKING TAX
            if ($bookingMaster->isNotEmpty() && $status === 'success') {
                $oldPendingMasterId = $bookingMaster->first()->id; // ✅ Store old master ID

                $newMaster = $this->updateMasterBooking($bookingMaster, $masterBookingIDs, $paymentId);
                return $newMaster;
                if ($newMaster) {
                    // ✅ UPDATE MASTER BOOKING TAX - Swap pending master_id with new master_id
                    $masterTaxUpdated = BookingTax::where('booking_id', $oldPendingMasterId)
                        ->where('type', 'online_master')
                        ->update(['booking_id' => $newMaster->id]);

                    if ($masterTaxUpdated) {
                        Log::info('[transferEventBooking] Master BookingTax updated successfully', [
                            'old_master_id' => $oldPendingMasterId,
                            'new_master_id' => $newMaster->id,
                            'rows_updated' => $masterTaxUpdated
                        ]);
                    } else {
                        Log::warning('[transferEventBooking] No Master BookingTax found to update', [
                            'old_master_id' => $oldPendingMasterId
                        ]);
                    }

                    $bookingMaster->each->delete();
                    Log::info('[transferEventBooking] Master booking updated and pending master deleted');
                } else {
                    Log::error('[transferEventBooking] Failed to update master booking');
                    return response()->json(['error' => 'Failed to update master booking'], 500);
                }
            }

            // Send SMS & WhatsApp
            if ($status === 'success') {
                try {
                    $this->smsService->send($data);
                    $this->whatsappService->send($data);
                    Log::info('[transferEventBooking] Notifications sent successfully');
                } catch (\Exception $e) {
                    Log::error('[transferEventBooking] Failed to send notifications: ' . $e->getMessage());
                }
            }

            Log::info('[transferEventBooking] Transfer completed successfully');
            return response()->json([
                'message' => 'Event booking processed successfully',
                'session_id' => $decryptedSessionId,
                'status' => $status,
                'booking_ids' => $masterBookingIDs
            ], 200);
        } catch (\Exception $e) {
            Log::error('[transferEventBooking] Exception occurred: ' . $e->getMessage(), [
                'session_id' => $decryptedSessionId,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => 'Booking transfer failed: ' . $e->getMessage(),
                'session_id' => $decryptedSessionId
            ], 500);
        }
    }

    private function bookingData($data, $paymentId)
    {
        try {
            $ticket = Ticket::findOrFail($data->ticket_id);
            $eventId = $ticket->event_id;

            $booking = new Booking();
            $booking->ticket_id = $data->ticket_id;
            $booking->event_id = $eventId;
            $booking->batch_id = Ticket::where('id', $data->ticket_id)->value('batch_id');
            $booking->user_id = $data->user_id;
            $booking->gateway = $data->gateway;
            $booking->session_id = $data->session_id;
            $booking->set_id = $data->setId;
            $booking->promocode_id = $data->promocode_id;
            $booking->token = $data->token;
            $booking->payment_id = $paymentId ?? NULL;
            $booking->total_amount = $data->total_amount > 0 ? $data->total_amount : 0;
            $booking->email = $data->email;
            $booking->name = $data->name;
            $booking->number = $data->number;
            $booking->type = $data->type;
            $booking->dates = $data->dates;
            $booking->payment_method = $data->payment_method;
            $booking->discount = $data->discount;
            $booking->status = $data->status = 0;
            $booking->payment_status = 1;
            $booking->txnid = $data->txnid;
            $booking->device = $data->device;
            $booking->attendee_id = $data->attendee_id;
            $booking->quantity = $data->quantity;
            $booking->booking_type = $data->booking_type;
            $booking->save();
            if (isset($booking->promocode_id)) {
                $promocode = Promocode::where('code', $booking->promocode_id)->first();

                if (!$promocode) {
                    Log::error('[bookingData] Invalid promocode', [
                        'code' => $booking->promocode_id
                    ]);
                    return response()->json(['status' => false, 'message' => 'Invalid promocode'], 400);
                }

                if ($promocode->remaining_count === null) {
                    $promocode->remaining_count = $promocode->usage_limit - 1;
                } elseif ($promocode->remaining_count > 0) {
                    $promocode->remaining_count--;
                } else {
                    Log::error('[bookingData] Promocode usage limit reached', [
                        'code' => $booking->promocode_id
                    ]);
                    return response()->json(['status' => false, 'message' => 'Promocode usage limit reached'], 400);
                }

                $promocode->save();
                Log::info('[bookingData] Promocode applied', [
                    'code' => $promocode->code,
                    'remaining' => $promocode->remaining_count
                ]);
            }

            Log::info('[bookingData] Booking created successfully', [
                'booking_id' => $booking->id
            ]);

            return $booking;
        } catch (\Exception $e) {
            Log::error('[bookingData] Failed to create booking: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            //return null;
            return $e->getMessage();
        }
    }

    private function updateMasterBooking($bookingMaster, $ids, $paymentId)
    {
        try {
            $createdMaster = null; // ✅ Track the created master

            foreach ($bookingMaster as $entry) {
                $data = [
                    'user_id' => $entry->user_id,
                    'session_id' => $entry->session_id,
                    'booking_id' => $ids,
                    'order_id' => $entry->order_id,
                    'total_amount' => $entry->amount,
                    'discount' => $entry->discount,
                    'payment_method' => $entry->payment_method,
                    'gateway' => $entry->gateway,
                    'payment_id' => $paymentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $master = MasterBooking::create($data);

                if (!$master) {
                    Log::error('[updateMasterBooking] Failed to create master booking');
                    return false;
                }

                $createdMaster = $master; // ✅ Store reference

                Log::info('[updateMasterBooking] Master booking created', [
                    'master_id' => $master->id
                ]);
            }

            return $createdMaster; // ✅ Return the master booking object
        } catch (\Exception $e) {
            Log::error('[updateMasterBooking] Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }





    private function amusementBookingData($data, $paymentId)
    {
        try {
            $booking = new AmusementBooking();
            $booking->ticket_id = $data->ticket_id;
            $booking->user_id = $data->user_id;
            $booking->gateway = $data->gateway;
            $booking->session_id = $data->session_id;
            $booking->promocode_id = $data->promocode_id;
            $booking->token = $data->token;
            $booking->payment_id = $paymentId ?? NULL;
            $booking->amount = $data->amount ?? 0;
            $booking->email = $data->email;
            $booking->name = $data->name;
            $booking->number = $data->number;
            $booking->type = $data->type;
            $booking->dates = $data->dates;
            $booking->payment_method = $data->payment_method;
            $booking->discount = $data->discount;
            $booking->status = $data->status = 0;
            $booking->payment_status = 1;
            $booking->txnid = $data->txnid;
            $booking->device = $data->device;
            $booking->booking_date = $data->booking_date;
            $booking->save();

            if (isset($booking->promocode_id)) {
                $promocode = Promocode::where('code', $booking->promocode_id)->first();

                if (!$promocode) {
                    Log::error('[amusementBookingData] Invalid promocode', [
                        'code' => $booking->promocode_id
                    ]);
                    return response()->json(['status' => false, 'message' => 'Invalid promocode'], 400);
                }

                if ($promocode->remaining_count === null) {
                    $promocode->remaining_count = $promocode->usage_limit - 1;
                } elseif ($promocode->remaining_count > 0) {
                    $promocode->remaining_count--;
                } else {
                    Log::error('[amusementBookingData] Promocode usage limit reached', [
                        'code' => $booking->promocode_id
                    ]);
                    return response()->json(['status' => false, 'message' => 'Promocode usage limit reached'], 400);
                }

                $promocode->save();
                Log::info('[amusementBookingData] Promocode applied', [
                    'code' => $promocode->code,
                    'remaining' => $promocode->remaining_count
                ]);
            }

            Log::info('[amusementBookingData] Booking created successfully', [
                'booking_id' => $booking->id
            ]);

            return $booking;
        } catch (\Exception $e) {
            Log::error('[amusementBookingData] Failed to create booking: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function updateAmusementMasterBooking($bookingMaster, $ids, $paymentId)
    {
        try {
            foreach ($bookingMaster as $entry) {
                if (!$entry) {
                    continue;
                }

                $data = [
                    'user_id' => $entry->user_id,
                    'gateway' => $entry->gateway,
                    'session_id' => $entry->session_id,
                    'booking_id' => is_array($ids) ? json_encode($ids) : json_encode([$ids]),
                    'order_id' => $entry->order_id,
                    'amount' => $entry->amount,
                    'discount' => $entry->discount,
                    'payment_method' => $entry->payment_method,
                    'payment_id' => $paymentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $master = AmusementMasterBooking::create($data);

                if (!$master) {
                    Log::error('[updateAmusementMasterBooking] Failed to create master booking');
                    return false;
                }

                Log::info('[updateAmusementMasterBooking] Master booking created', [
                    'master_id' => $master->id
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('[updateAmusementMasterBooking] Exception: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }





    // NOTE: The remaining methods (handleZeroAmountBooking, store, storeEmusment, etc.) 
    // are kept as-is from the original file for completeness.
    // Add similar logging improvements to these methods if needed.

    public function handleZeroAmountBooking($request, $session, $txnid, $setId)
    {
        $bookingIds = [];
        $bookings = [];

        if (!$request->all()) {
            return response()->json(['status' => false, 'message' => 'Invalid JSON data'], 400);
        }

        // ✅ Step 1: Save/Update attendees
        $attendees = $request->attendees ?? [];
        $savedAttendees = [];

        foreach ($attendees as $index => $attendeeData) {
            $id = $attendeeData['id'] ?? null;
            if ($id) {
                $savedAttendees[$index] = $this->updateAttendee(
                    $id,
                    $attendeeData,
                    $request,
                    $request->event_name,
                    $index
                );
            } else {
                $savedAttendees[$index] = $this->createAttendee(
                    $attendeeData,
                    $request,
                    $request->event_name,
                    $index
                );
            }
        }

        // ✅ Step 2: Create single bookings (based on quantity)
        for ($i = 0; $i < $request->quantity; $i++) {
            $attendeeId = $savedAttendees[$i]->id ?? ($attendees[$i]['id'] ?? null);

            if ($request->category === 'Amusement') {
                $booking = $this->amusementBookingDataZero($request, $session, $txnid, $setId, $i);
            } else {
                $booking = $this->bookingDataZero($request, $session, $txnid, $setId, [
                    'is_master_booking' => false,
                    'attendee_id' => $attendeeId
                ]);
            }

            if (!$booking) {
                return response()->json(['status' => false, 'message' => 'Booking failed'], 400);
            }

            $bookings[] = $booking;
            $bookingIds[] = $booking->id;
        }

        // ✅ Step 3: If multiple bookings → create master booking
        if (count($bookingIds) > 1) {
            $masterData = ($request->category === 'Amusement')
                ? $this->updateAmusementMasterBookingZero($bookings[0], $bookingIds)
                : $this->updateMasterBookingZero($bookings[0], $bookingIds);

            return response()->json([
                'status' => true,
                'bookings' => $masterData ?? $bookings,
                'is_master' => true,
                'message' => 'Master booking created successfully',
                'attendees' => $savedAttendees,
                'gateway_status' => 'success'
            ], 200);
        }

        // ✅ Step 4: Only single booking (if quantity = 1)
        return response()->json([
            'status' => true,
            'bookings' => $bookings,
            'is_master' => false,
            'message' => 'Single booking created successfully',
            'attendees' => $savedAttendees,
            'gateway_status' => 'success'
        ], 200);
    }


    private function updateAmusementMasterBookingZero($booking, $ids)
    {
        $data = [
            'user_id' => $booking->user_id,
            'session_id' => $booking->session_id,
            'booking_id' => is_array($ids) ? json_encode($ids) : json_encode([$ids]),
            'order_id' => $booking->order_id,
            'amount' => $booking->amount ?? 0,
            'discount' => $booking->discount,
            'payment_method' => $booking->payment_method,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $master = AmusementMasterBooking::create($data);

        if (!$master) {
            return false;
        }
        $master->bookings = AmusementBooking::whereIn('id', $ids)->with(['user', 'attendee', 'ticket.event'])->get();


        $orderId = $booking->order_id ?? '';
        $shortLink = $orderId;
        $shortLinksms = "t.getyourticket.in/t/{$orderId}";

        $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
        $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

        $dates = explode(',', $booking->ticket->event->date_range);
        $formattedDates = [];
        foreach ($dates as $date) {
            $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
        }
        $dateRangeFormatted = implode(' | ', $formattedDates);

        $eventDateTime = $dateRangeFormatted . ' | ' . $booking->ticket->event->start_time . ' - ' . $booking->ticket->event->end_time;

        $totalQty = count($ids);
        $mediaurl = $booking->ticket->event->eventMedia->thumbnail;
        $data = (object) [
            'name' => $booking->name,
            'number' => $booking->number,
            'templateName' => 'Online Booking Template',
            'whatsappTemplateData' => $whatsappTemplateName,
            'mediaurl' => $mediaurl,
            'shortLink' => $shortLink,
            'insta_whts_url' => $booking->ticket->event->insta_whts_url ?? 'helloinsta',
            'values' => [
                $booking->name,
                $booking->number,
                $booking->ticket->event->name,
                $totalQty,
                $booking->ticket->name,
                $booking->ticket->event->venue->address,
                $eventDateTime,
                $booking->ticket->event->whts_note ?? 'hello',
            ],
            'replacements' => [
                ':C_Name' => $booking->name,
                ':T_QTY' => $totalQty,
                ':Ticket_Name' => $booking->ticket->name,
                ':Event_Name' => $booking->ticket->event->name,
                ':Event_Date' => $eventDateTime,
                ':S_Link' => $shortLinksms,
            ]
        ];

        if ($totalQty >= 2) {
            $this->smsService->send($data);
            $this->whatsappService->send($data);
        }

        return $master;
    }

    // private function updateMasterBookingZero($booking, $ids)
    // {
    //     $data = [
    //         'user_id' => $booking['user_id'],
    //         'session_id' => $booking['session_id'],
    //         'set_id' => $booking['set_id'],
    //         'booking_type' => $booking['booking_type'],
    //         'booking_id' => implode(',', $ids),
    //         'order_id' => $this->generateHexadecimalCode(),
    //         'total_amount' => $booking['totalFinalAmount'] ?? 0,
    //         'discount' => $booking['discount'] ?? 0,
    //         'payment_method' => $booking['payment_method'] ?? 'online',
    //         'created_at' => now(),
    //         'updated_at' => now(),
    //     ];

    //     $master = MasterBooking::create($data);

    //     if (!$master) {
    //         return false;
    //     }

    //     $singleBookings = Booking::whereIn('id', $ids)->get();

    //     foreach ($singleBookings as $single) {
    //         $single->master_token = $master->order_id;
    //         $single->save();
    //     }
    //     if (isset($booking->ticket_id)) {
    //         $ticket = Ticket::find($booking->ticket_id);
    //         if ($ticket) {
    //             $totalQty = count($ids); // since all are part of same master booking
    //             $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
    //             $newRemaining = max(0, $newRemaining - $totalQty);
    //             $ticket->remaining_count = $newRemaining;
    //             $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
    //             $ticket->save();
    //         }
    //     }

    //     $master->bookings = Booking::whereIn('id', $ids)
    //         ->with(['user', 'ticket.event', 'attendee'])
    //         ->get();

    //     $orderId = $master->order_id ?? '';
    //     $shortLink = $orderId;
    //     $shortLinksms = "t.getyourticket.in/t/{$orderId}";

    //     $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
    //     $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

    //     $dates = explode(',', $booking->ticket->event->date_range);
    //     $formattedDates = [];
    //     foreach ($dates as $date) {
    //         $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
    //     }
    //     $dateRangeFormatted = implode(' | ', $formattedDates);

    //     $eventDateTime = $dateRangeFormatted . ' | ' . $booking->ticket->event->start_time . ' - ' . $booking->ticket->event->end_time;
    //     $totalQty = count($ids);
    //     $mediaurl = $booking->ticket->event->eventMedia->thumbnail ?? '';

    //     $notifyData = (object) [
    //         'name' => $booking->name,
    //         'number' => $booking->number,
    //         'templateName' => 'Online Booking Template',
    //         'whatsappTemplateData' => $whatsappTemplateName,
    //         'mediaurl' => $mediaurl,
    //         'shortLink' => $shortLink,
    //         'insta_whts_url' => $booking->ticket->event->insta_whts_url ?? 'helloinsta',
    //         'values' => [
    //             $booking->name,
    //             $booking->number,
    //             $booking->ticket->event->name,
    //             $totalQty,
    //             $booking->ticket->name,
    //             $booking->ticket->event->venue->address,
    //             $eventDateTime,
    //             $booking->ticket->event->whts_note ?? 'hello',
    //         ],
    //         'replacements' => [
    //             ':C_Name' => $booking->name,
    //             ':T_QTY' => $totalQty,
    //             ':Ticket_Name' => $booking->ticket->name,
    //             ':Event_Name' => $booking->ticket->event->name,
    //             ':Event_Date' => $eventDateTime,
    //             ':S_Link' => $shortLinksms,
    //         ]
    //     ];

    //     if ($totalQty >= 2) {
    //         $this->smsService->send($notifyData);
    //         $this->whatsappService->send($notifyData);
    //     }

    //     return $master->load(['bookings.ticket.event', 'bookings.attendee', 'bookings.user']);
    // }

    private function updateMasterBookingZero($booking, $ids)
    {
        DB::beginTransaction();

        try {
            $data = [
                'user_id' => $booking['user_id'],
                'session_id' => $booking['session_id'],
                'set_id' => $booking['set_id'],
                'booking_type' => $booking['booking_type'],
                'booking_id' => implode(',', $ids),
                'order_id' => $this->generateHexadecimalCode(),
                'total_amount' => $booking['totalFinalAmount'] ?? 0,
                'discount' => $booking['discount'] ?? 0,
                'payment_method' => $booking['payment_method'] ?? 'online',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $master = MasterBooking::create($data);

            if (!$master) {
                DB::rollBack();
                Log::error('[MASTER_BOOKING] Failed to create master booking', ['data' => $data]);
                return false;
            }

            // fetch all single bookings
            $singleBookings = Booking::whereIn('id', $ids)->get();

            foreach ($singleBookings as $single) {

                // attach master token
                $single->master_token = $master->order_id;
                $single->save();

                // 🟢 MULTI SEAT FIX — each booking already has seat info
                if (!empty($single->seat_id)) {
                    try {

                        $ess = EventSeatStatus::updateOrCreate(
                            ['seat_id' => $single->seat_id],
                            [
                                'booking_id' => $single->id,
                                'event_id'   => $single->event_id,
                                'status'     => 1,
                                'type'       => 'ONLINE',
                                'section_id' => $single->section_id,
                                'seat_name'  => $single->seat_name,
                                'row_id'     => $single->row_id,
                            ]
                        );

                        // update booking ess_id
                        $single->ess_id = $ess->id;
                        $single->save();

                        Log::info('[MASTER_BOOKING][ESS] ESS UPDATED', [
                            'booking_id' => $single->id,
                            'seat_id' => $single->seat_id,
                            'ess_id' => $ess->id
                        ]);
                    } catch (\Exception $ex) {
                        Log::error('[MASTER_BOOKING][ESS_ERROR]', [
                            'booking_id' => $single->id,
                            'error' => $ex->getMessage(),
                        ]);
                    }
                }
            }

            // update ticket remaining count
            if (!empty($booking['ticket_id'])) {
                $ticket = Ticket::find($booking['ticket_id']);
                if ($ticket) {
                    $totalQty = count($ids);
                    $newRemaining = max(0, ($ticket->remaining_count ?? $ticket->ticket_quantity) - $totalQty);
                    $ticket->remaining_count = $newRemaining;
                    $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
                    $ticket->save();
                }
            }

            // load fresh bookings
            $master->bookings = Booking::whereIn('id', $ids)
                ->with(['user', 'ticket.event', 'attendee'])
                ->get();

            DB::commit();
            return $master->load(['bookings.ticket.event', 'bookings.attendee', 'bookings.user']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[MASTER_BOOKING] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }


    private function amusementBookingDataZero($request, $session, $txnid, $i)
    {
        $attendees = $request->attendees ?? [];
        $attendeeId = $attendees[$i]['id'] ?? null;

        $booking = new AmusementBooking();
        $booking->ticket_id = $request->ticket_id;
        $booking->user_id = $request->user_id;
        $booking->session_id = $request->session_id ?? $session;
        $booking->promocode_id = $request->promocode_id ?? NULL;
        $booking->token = $request->token ?? $this->generateHexadecimalCode();
        $booking->amount = $request->amount ?? 0;
        $booking->email = $request->user_email;
        $booking->name = $request->user_name;
        $booking->number = $request->user_phone;
        $booking->type = $request->type;
        $booking->dates = $request->dates ?? now();
        $booking->payment_method = $request->payment_method;
        $booking->discount = $request->discount ?? NULL;
        $booking->status = $request->status = 0;
        $booking->payment_status = 1;
        $booking->txnid = $request->txnid ?? $txnid;
        $booking->device = $request->device ?? NULL;
        $booking->base_amount = $request->base_amount;
        $booking->convenience_fee = $request->convenience_fee ?? NULL;
        $booking->attendee_id = $attendeeId;
        $booking->total_tax = $request->total_tax ?? NULL;
        $booking->booking_date = $request->booking_date;
        $booking->save();
        $booking->load(['user', 'ticket.event', 'attendee']);

        if (isset($booking->promocode_id)) {
            $promocode = Promocode::where('code', $booking->promocode_id)->first();

            if (!$promocode) {
                return response()->json(['status' => false, 'message' => 'Invalid promocode'], 400);
            }

            if ($promocode->remaining_count === null) {
                $promocode->remaining_count = $promocode->usage_limit - 1;
            } elseif ($promocode->remaining_count > null) {
                $promocode->remaining_count--;
            } else {
                return response()->json(['status' => false, 'message' => 'Promocode usage limit reached'], 400);
            }

            if (isset($booking->promocode_id)) {
                $booking->promocode_id = $booking->promocode_id;
            }

            $promocode->save();
        }

        $orderId = $booking->token ?? '';
        $shortLink = $orderId;
        $shortLinksms = "t.getyourticket.in/t/{$orderId}";
        $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
        $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

        $dates = explode(',', $booking->ticket->event->date_range);
        $formattedDates = [];
        foreach ($dates as $date) {
            $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
        }
        $dateRangeFormatted = implode(' | ', $formattedDates);

        $eventDateTime = $dateRangeFormatted . ' | ' . $booking->ticket->event->start_time . ' - ' . $booking->ticket->event->end_time;

        $totalQty = 1;
        $mediaurl = $booking->ticket->event->eventMedia->thumbnail;
        $data = (object) [
            'name' => $booking->name,
            'number' => $booking->number,
            'templateName' => 'Online Booking Template',
            'whatsappTemplateData' => $whatsappTemplateName,
            'mediaurl' => $mediaurl,
            'shortLink' => $shortLink,
            'insta_whts_url' => $booking->ticket->event->insta_whts_url ?? 'helloinsta',
            'values' => [
                $booking->name,
                $booking->number,
                $booking->ticket->event->name,
                $totalQty,
                $booking->ticket->name,
                $booking->ticket->event->venue->address,
                $eventDateTime,
                $booking->ticket->event->whts_note ?? 'hello',
            ],
            'replacements' => [
                ':C_Name' => $booking->name,
                ':T_QTY' => $totalQty,
                ':Ticket_Name' => $booking->ticket->name,
                ':Event_Name' => $booking->ticket->event->name,
                ':Event_DateTime' => $eventDateTime,
                ':S_Link' => $shortLinksms,
            ]
        ];

        $isMasterBooking = $extra['is_master_booking'] ?? false;

        if (!$isMasterBooking && ($data->tickets->quantity ?? 1) == 1) {
            $this->smsService->send($data);
            $this->whatsappService->send($data);
        }

        return $booking;
    }

    public function bookingDataZero($request, $session, $txnid, $setId, $extra = [])
    {
        $ticket = Ticket::findOrFail($request->ticket_id);
        $eventId = $ticket->event_id;
        $seats = $request->seats ?? [];

        if (is_string($seats)) {
            $seats = json_decode($seats, true) ?? [];
        }

        Log::info('[bookingDataZero] Seats payload', ['seats' => $seats]);

        // 🔥 Transaction શરૂ કરો
        DB::beginTransaction();

        try {
            $booking = new Booking();
            $booking->ticket_id = $request->ticket_id;
            $booking->event_id  = $eventId;
            $booking->batch_id = Ticket::where('id', $request->ticket_id)->value('batch_id');
            $booking->set_id = $setId;
            $booking->user_id = $request->user_id ?? 0;
            $booking->session_id = $request->session_id ?? $session;
            $booking->promocode_id = $request->promocode_id ?? null;
            $booking->token = $this->generateHexadecimalCode();
            $booking->total_amount = $request->totalFinalAmount > 0 ? $request->totalFinalAmount : 0;
            $booking->email = $request->user_email;
            $booking->name = $request->user_name;
            $booking->number = $request->user_phone;
            $booking->type = $request->category;
            $booking->dates = $request->dates ?? now();
            $booking->payment_method = $request->payment_method;
            $booking->discount = $request->discount ?? 0;
            $booking->status = 0;
            $booking->txnid = $request->txnid ?? $txnid;
            $booking->device = $request->device ?? NULL;
            $booking->attendee_id = $extra['attendee_id'] ?? null;
            $booking->booking_type = 'online';
            $booking->quantity = $request->quantity ?? 0;

            // 🔥 $firstSeat ને બહાર define કરો
            $firstSeat = null;

            if (!empty($seats) && is_array($seats)) {
                $firstSeat = $seats[0];

                // Sanitize section_id and row_id
                if (isset($firstSeat['section_id']) && is_string($firstSeat['section_id'])) {
                    $firstSeat['section_id'] = str_replace('section_', '', $firstSeat['section_id']);
                }
                if (isset($firstSeat['row_id']) && is_string($firstSeat['row_id'])) {
                    $firstSeat['row_id'] = str_replace('row_', '', $firstSeat['row_id']);
                }

                $booking->seat_id = $firstSeat['seat_id'] ?? null;
                $booking->seat_name = $firstSeat['seat_name'] ?? null;
                $booking->section_id = $firstSeat['section_id'] ?? null;
                $booking->row_id = $firstSeat['row_id'] ?? null;
            } else {
                $booking->seat_id = null;
                $booking->seat_name = null;
                $booking->section_id = null;
                $booking->row_id = null;
            }

            $booking->save();

            Log::info('[Booking] Booking created', ['booking_id' => $booking->id]);

            // 🔥 EventSeatStatus create/update
            if (!empty($firstSeat) && !empty($firstSeat['seat_id'])) {

                $ess = EventSeatStatus::updateOrCreate(
                    ['seat_id' => $firstSeat['seat_id']], // lookup
                    [   // values to set / update
                        'booking_id' => $booking->id,
                        'event_id'   => $ticket->event_id,
                        'status'     => 1,
                        'type'       => 'ONLINE',
                        'section_id' => $firstSeat['section_id'] ?? null,
                        'seat_name'  => $firstSeat['seat_name'] ?? null,
                        'row_id'     => $firstSeat['row_id'] ?? null,
                        // add other fields if needed
                    ]
                );
                // 🔥 Correct booking update
                $booking->ess_id = $ess->id;
                $booking->save();

                Log::info('[Booking] Updated with ess_id', [
                    'booking_id' => $booking->id,
                    'ess_id' => $ess->id
                ]);
            } else {
                Log::warning('[ess] No seat data available', [
                    'booking_id' => $booking->id,
                    'seats' => $seats
                ]);
            }

            // Ticket inventory update
            $ticket = Ticket::find($request->ticket_id);
            if ($ticket) {
                $quantity = (int) $booking->quantity;
                $newRemaining = $ticket->remaining_count ?? $ticket->ticket_quantity;
                $newRemaining = max(0, $newRemaining - $quantity);
                $ticket->remaining_count = $newRemaining;
                $ticket->sold_out = $newRemaining <= 0 ? 1 : 0;
                $ticket->save();
            }

            // BookingTax create
            BookingTax::create([
                'booking_id' => $booking->id,
                'type' => 'Online',
                'base_amount' => $booking->base_amount ?? 0,
                'discount' => $booking->discount ?? 0,
                'central_gst' => $request->cgst ?? 0,
                'state_gst' => $request->sgst ?? 0,
                'total_tax' => $booking->total_tax ?? 0,
                'convenience_fee' => $booking->convenience_fees ?? 0,
            ]);

            // Promocode logic
            if (isset($booking->promocode_id)) {
                $promocode = Promocode::where('code', $booking->promocode_id)->first();

                if (!$promocode) {
                    throw new \Exception('Invalid promocode');
                }

                if ($promocode->remaining_count === null) {
                    $promocode->remaining_count = $promocode->usage_limit - 1;
                } elseif ($promocode->remaining_count > 0) {
                    $promocode->remaining_count--;
                } else {
                    throw new \Exception('Promocode usage limit reached');
                }

                $promocode->save();
            }

            // 🔥 બધું સફળ થયું, transaction commit કરો
            DB::commit();

            Log::info('[Transaction] Booking transaction committed successfully', [
                'booking_id' => $booking->id
            ]);
        } catch (\Exception $e) {
            // 🔥 કોઈ error આવે તો rollback કરો
            DB::rollBack();

            Log::error('[Transaction] Booking transaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw the exception
        }

        // Load relationships
        $booking->load(['user', 'ticket.event', 'attendee']);

        // SMS/WhatsApp notification logic
        $orderId = $booking->token ?? '';
        $shortLink = $orderId;
        $shortLinksms = "t.getyourticket.in/t/{$orderId}";

        $whatsappTemplate = WhatsappApi::where('title', 'Online Booking')->first();
        $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

        $dates = explode(',', $booking->ticket->event->date_range);
        $formattedDates = [];
        foreach ($dates as $date) {
            $formattedDates[] = \Carbon\Carbon::parse($date)->format('d-m-Y');
        }
        $dateRangeFormatted = implode(' | ', $formattedDates);

        $eventDateTime = $dateRangeFormatted . ' | ' . $booking->ticket->event->start_time . ' - ' . $booking->ticket->event->end_time;
        $mediaurl = $booking->ticket->event->eventMedia->thumbnail;

        $data = (object) [
            'name' => $booking->name,
            'number' => $booking->number,
            'templateName' => 'Online Booking Template',
            'whatsappTemplateData' => $whatsappTemplateName,
            'mediaurl' => $mediaurl,
            'shortLink' => $shortLink,
            'insta_whts_url' => $booking->ticket->event->insta_whts_url ?? 'helloinsta',
            'values' => [
                $booking->name,
                $booking->number,
                $booking->ticket->event->name,
                1,
                $booking->ticket->name,
                $booking->ticket->event->venue->address,
                $eventDateTime,
                $booking->ticket->event->whts_note ?? 'hello',
            ],
            'replacements' => [
                ':C_Name' => $booking->name,
                ':T_QTY' => 1,
                ':Ticket_Name' => $booking->ticket->name,
                ':Event_Name' => $booking->ticket->event->name,
                ':Event_Date' => $eventDateTime,
                ':S_Link' => $shortLinksms,
            ]
        ];

        $isMasterBooking = $extra['is_master_booking'] ?? false;

        if (!$isMasterBooking && ($request->quantity ?? 1) == 1) {
            $this->smsService->send($data);
            $this->whatsappService->send($data);
        }

        return $booking;
    }
    public function store(Request $request, $session, $txnid, $setId)
    {
        try {
            $qty = (int) $request->quantity;
            $bookings = [];
            $masterBookingIds = [];
            $savedAttendees = [];
            $penddingBookingsMaster = null;

            if ($qty <= 0) {
                return response()->json(['status' => false, 'message' => 'Quantity must be greater than 0'], 400);
            }

            // ✅ 1. Save/Update Attendees
            $attendees = $request->attendees ?? [];
            foreach ($attendees as $index => $attendeeData) {
                if (isset($attendeeData['id']) && $attendeeData['id']) {
                    $savedAttendees[$index] = $this->updateAttendee(
                        $attendeeData['id'],
                        $attendeeData,
                        $request,
                        $request->event_name,
                        $index
                    );
                } else {
                    $savedAttendees[$index] = $this->createAttendee(
                        $attendeeData,
                        $request,
                        $request->event_name,
                        $index
                    );
                }
            }

            // ✅ 2. Create Pending Bookings
            $firstIteration = true;
            for ($i = 0; $i < $qty; $i++) {
                $ticket = Ticket::findOrFail($request->ticket_id);
                $eventId = $ticket->event_id;

                $booking = new PenddingBooking();
                $booking->ticket_id = $request->ticket_id;
                $booking->event_id = $eventId;
                $booking->batch_id = Ticket::where('id', $request->ticket_id)->value('batch_id');
                $booking->user_id = $request->user_id;
                $booking->email = $request->user_email;
                $booking->name = $request->user_name;
                $booking->number = $request->user_phone;
                $booking->type = $request->category;
                $booking->payment_method = $request->payment_method;
                $booking->booking_type = 'online';
                $booking->quantity = $request->quantity;
                $booking->total_amount = $request->totalFinalAmount;
                $booking->gateway = $request->gateway ?? 'online';
                $booking->token = $this->generateHexadecimalCode();
                $booking->session_id = $session;
                $booking->set_id = $setId;
                $booking->promocode_id = $request->promo_code ?? null;
                $booking->txnid = $txnid;
                $booking->status = 0;
                $booking->payment_status = 0;
                $booking->total_tax = $request->total_tax ?? 0;
                $booking->attendee_id = $savedAttendees[$i]->id ?? ($attendees[$i]['id'] ?? null);

                $booking->save();

                BookingTax::create([
                    'booking_id' => $booking->id,
                    'type' => 'online',
                    'base_amount' => $request->baseAmount ?? 0,
                    'discount' => $booking->discount ?? 0,
                    'central_gst' => $request->centralGST ?? 0,
                    'state_gst' => $request->stateGST ?? 0,
                    'total_tax' => $request->totalTax ?? 0,
                    'convenience_fee' => $request->convenienceFee ?? 0,
                    'final_amount' => $request->finalAmount ?? 0,
                ]);
                $booking->load(['user', 'ticket.event', 'attendee']);

                $bookings[] = $booking;
                $masterBookingIds[] = $booking->id;
            }

            // ✅ 3. Only if qty > 1 → create MasterBooking from PendingBooking IDs
            if (count($masterBookingIds) > 1) {
                $penddingBookingsMaster = new PenddingBookingsMaster();
                $penddingBookingsMaster->booking_id = $masterBookingIds;
                $penddingBookingsMaster->session_id = $session;
                $penddingBookingsMaster->set_id = $setId;
                $penddingBookingsMaster->user_id = $request->user_id;
                $penddingBookingsMaster->order_id = $this->generateHexadecimalCode();
                $penddingBookingsMaster->save();

                $bookingTaxData = [
                    'booking_id' => $penddingBookingsMaster->id,
                    'type' => 'online_master',
                    'final_amount' => $request->totalFinalAmount ?? 0,
                    'base_amount' => $request->totalBaseAmount ?? 0,
                    'central_gst' => $request->totalCentralGST ?? 0,
                    'state_gst' => $request->totalStateGST ?? 0,
                    'total_tax' => $request->totalTaxTotal ?? 0,
                    'convenience_fee' => $request->totalConvenienceFee ?? 0,
                ];

                // ✅ Log before storing
                Log::info('[BookingTax] Incoming tax data before store', [
                    'request_data' => $request->all(),
                    'prepared_data' => $bookingTaxData,
                ]);

                // Then create the record
                BookingTax::create($bookingTaxData);
            }

            // ✅ 4. Response
            return response()->json([
                'status' => true,
                'message' => 'Pending booking created successfully',
                'bookings' => $bookings,
                'attendees' => $savedAttendees,
                'master' => $penddingBookingsMaster,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to book tickets',
                'error' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    public function storeEmusment($request, $session, $txnid)
    {
        try {
            $requestData = json_decode($request->requestData);

            $qty = $requestData->tickets->quantity;
            $bookings = [];
            $masterBookingData = [];
            $firstIteration = true;
            $penddingBookingsMaster = null;

            if ($qty > 0) {
                for ($i = 0; $i < $qty; $i++) {
                    $booking = new AmusementPendingBooking();
                    $booking->ticket_id = $requestData->tickets->id;
                    $booking->user_id = $requestData->user_id;
                    $booking->email = $requestData->email;
                    $booking->name = $requestData->name;
                    $booking->number = $requestData->number;
                    $booking->type = $requestData->type;
                    $booking->payment_method = $requestData->payment_method;
                    $booking->gateway = $request->gateway;

                    $booking->token = $this->generateHexadecimalCode();
                    $booking->session_id = $session;
                    $booking->promocode_id = $request->promo_code;
                    $booking->txnid = $txnid;
                    $booking->status = 0;
                    $booking->payment_status = 0;
                    $booking->attendee_id = $request->attendees[$i]['id'] ?? null;
                    $booking->total_tax = $request->total_tax;
                    $booking->booking_date = $request->booking_date;


                    if ($firstIteration) {
                        $booking->amount = $request->amount ?? 0;
                        $booking->discount = $request->discount;
                        $booking->base_amount = $request->base_amount;
                        $booking->convenience_fee = $request->convenience_fee;
                        $firstIteration = false;
                    }

                    $booking->save();
                    $booking->load(['user', 'ticket.event.user.smsConfig']);
                    $bookings[] = $booking;

                    $masterBookingData[] = $booking->id;
                }

                try {
                    if (count($bookings) > 1) {
                        $penddingBookingsMaster = new AmusementPendingMasterBooking();

                        $penddingBookingsMaster->booking_id = is_array($masterBookingData) ? json_encode($masterBookingData) : json_encode([$masterBookingData]);
                        $penddingBookingsMaster->session_id = $session;
                        $penddingBookingsMaster->user_id = $requestData->user_id;
                        $penddingBookingsMaster->amount = $request->amount;
                        $penddingBookingsMaster->order_id = $this->generateHexadecimalCode();
                        $penddingBookingsMaster->discount = $request->discount;
                        $penddingBookingsMaster->payment_method = $request->payment_method;
                        $penddingBookingsMaster->gateway = $request->gateway;
                        $penddingBookingsMaster->save();
                    }
                } catch (\Exception $e) {
                    return response()->json([
                        'error' => $e->getMessage(),
                        'line' => $e->getLine(),
                        'file' => $e->getFile()
                    ], 500);
                }
            }
            return response()->json(['status' => true, 'message' => 'Tickets Booked Successfully', 'bookings' => $bookings, 'PenddingBookingsMaster' => $penddingBookingsMaster], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to book tickets', 'error' => $e->getMessage(), 'line' => $e->getLine()], 500);
        }
    }

    public function generateHexadecimalCode($length = 8)
    {
        $characters = '0123456789ABCDEF';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function createAttendee(array $attendeeData, Request $request, $eventName, $index = 0)
    {
        $data = $attendeeData;

        if ($request->isAgentBooking) {
            $data['agent_id'] = $request->user_id;
            $data['user_id'] = null;
        } else {
            $data['user_id'] = $request->user_id;
            $data['agent_id'] = null;
        }

        $attendee = Attndy::create($data);
        $attendee->token = $this->generateHexadecimalCode();

        if ($request->hasFile("attendees.$index")) {
            foreach ($request->file("attendees.$index") as $fileKey => $file) {
                if ($file->isValid()) {
                    $fileName = $attendee->id . '_' . str_replace(' ', '_', strtolower($attendee->Name)) . '.jpg';
                    $folder = str_replace(' ', '_', $eventName) . '/attendees/' . $fileKey;
                    $filePath = $this->storeFile($file, $folder, $fileName);
                    $attendee->$fileKey = $filePath;
                }
            }
        }

        $attendee->save();
        return $attendee;
    }

    private function updateAttendee($id, array $attendeeData, Request $request, $eventName, $index = 0)
    {
        $attendee = Attndy::findOrFail($id);

        foreach ($attendeeData as $key => $value) {
            if (!in_array($key, ['id', 'index', 'Photo'])) {
                $attendee->$key = $value;
            }
        }

        if ($request->hasFile("attendees.$index")) {
            foreach ($request->file("attendees.$index") as $fileKey => $file) {
                if ($file->isValid()) {
                    $fileName = $attendee->id . '_' . str_replace(' ', '_', strtolower($attendee->Name)) . '.jpg';
                    $folder = str_replace(' ', '_', $eventName) . '/attendees/' . $fileKey;
                    $filePath = $this->storeFile($file, $folder, $fileName);
                    $attendee->$fileKey = $filePath;
                }
            }
        }

        $attendee->save();
        return $attendee;
    }


    private function storeFile($file, $folder, $fileName = null, $disk = 'public')
    {
        $fileName = $fileName ?? uniqid() . '_' . $file->getClientOriginalName();
        $folderPath = public_path('uploads/' . $folder);

        if (!file_exists($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        $file->move($folderPath, $fileName);

        return url('uploads/' . $folder . '/' . $fileName);
    }

    private function extractNumericId($value): ?int
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
