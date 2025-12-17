<?php

namespace App\Services;

use Cashfree\Cashfree;
use Cashfree\Model\CreateOrderRequest;
use Cashfree\Model\CustomerDetails;
use Cashfree\Model\OrderMeta;
use Exception;
use Illuminate\Support\Facades\Log;

class CashfreeService
{
    private $cashfree;

    public function __construct()
    {
        // Initialize Cashfree configuration
        $this->cashfree = new Cashfree(
            env('CASHFREE_ENV') === 'production' ? 1 : 0, // environment
            env('CASHFREE_APP_ID'),                        // client id
            env('CASHFREE_SECRET_KEY'),                    // secret key
            '',                                            // partner api key
            '',                                            // partner merchant id
            '',                                            // client signature
            false                                          // error analytics
        );
    }

    public function createOrder($orderId, $amount, $customerId, $customerName, $customerEmail, $customerPhone)
    {
        $gateway = 'cashfree';
        $sessionId = session()->getId();
        try {
            // Create CustomerDetails object
            $customerDetails = new CustomerDetails();
            $customerDetails->setCustomerId((string)$customerId);
            $customerDetails->setCustomerName($customerName);
            $customerDetails->setCustomerEmail($customerEmail);
            $customerDetails->setCustomerPhone($customerPhone);

            // Create OrderMeta object
            $orderMeta = new OrderMeta();
            $orderMeta->setReturnUrl(url('/api/payment-response/' . $gateway . '/' . 1 . '/' . $sessionId));

            // Create the order request using proper model classes
            $createOrderRequest = new CreateOrderRequest();
            $createOrderRequest->setOrderId($orderId);
            $createOrderRequest->setOrderAmount((float)$amount);
            $createOrderRequest->setOrderCurrency('INR');
            $createOrderRequest->setCustomerDetails($customerDetails);
            $createOrderRequest->setOrderMeta($orderMeta);
            
            // Debug: Log the request
            Log::info('Cashfree Request:', [
                'order_id' => $orderId,
                'order_amount' => (float)$amount,
                'order_currency' => 'INR',
                'customer_details' => [
                    'customer_id' => (string)$customerId,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone
                ],
                'order_meta' => [
                    'return_url' => url('/api/payment-response/' . $gateway . '/' . 1 . '/' . $sessionId)
                ]
            ]);
            
            // Create order using the correct method call with proper model objects
            $response = $this->cashfree->PGCreateOrder($createOrderRequest);
            
            // Debug: Log the response
            Log::info('Cashfree Response:', $response);
            
            return $response;
        } catch (\Cashfree\ApiException $e) {
            Log::error('Cashfree API Error:', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'response_body' => $e->getResponseBody()
            ]);
            return 'Failed to create Cashfree order: ' . $e->getMessage();
        } catch (Exception $e) {
            Log::error('Cashfree Error:', ['error' => $e->getMessage()]);
            return 'Failed to create Cashfree order: ' . $e->getMessage();
        }
    }

    public function fetchOrder($orderId)
    {
        try {
            return $this->cashfree->PGFetchOrder($orderId);
        } catch (Exception $e) {
            throw new Exception('Failed to fetch Cashfree order: ' . $e->getMessage());
        }
    }

    public function getPaymentUrl($paymentSessionId)
    {
        // Method 1: Try different URL formats
        $possibleUrls = [
            // Latest format
            ($this->cashfree->XEnvironment == 0 ? 'https://sandbox.cashfree.com/pg/checkout' : 'https://checkout.cashfree.com/pg/checkout'),
            // Alternative format 1
            ($this->cashfree->XEnvironment == 0 ? 'https://sandbox.cashfree.com/pg/view/checkout' : 'https://checkout.cashfree.com/pg/view/checkout'),
            // Alternative format 2  
            ($this->cashfree->XEnvironment == 0 ? 'https://sandbox.cashfree.com/checkout' : 'https://checkout.cashfree.com/checkout'),
        ];
        
        // Use the first URL format for now
        $baseUrl = $possibleUrls[0];
        $paymentUrl = $baseUrl . '?payment_session_id=' . $paymentSessionId;
        
        Log::info('Generated Payment URL:', ['url' => $paymentUrl]);
        
        return $paymentUrl;
    }

    public function getAlternativePaymentUrls($paymentSessionId)
    {
        // Return all possible URL formats for testing
        $urls = [];
        $formats = [
            '/pg/checkout',
            '/pg/view/checkout', 
            '/checkout',
            '/pg/sessions'
        ];
        
        $baseUrl = $this->cashfree->XEnvironment == 0 ? 'https://sandbox.cashfree.com' : 'https://checkout.cashfree.com';
        
        foreach ($formats as $format) {
            $urls[] = $baseUrl . $format . '?payment_session_id=' . $paymentSessionId;
        }
        
        return $urls;
    }

    public function createOrderAndGetPaymentUrl($orderId, $amount, $customerId, $customerName, $customerEmail, $customerPhone)
    {
        try {
            $orderResponse = $this->createOrder($orderId, $amount, $customerId, $customerName, $customerEmail, $customerPhone);
            
            if (is_string($orderResponse)) {
                // Error occurred
                return $orderResponse;
            }
            
            // Extract payment session ID from response
            $responseData = $orderResponse[0]; // The first element contains the order data
            $paymentSessionId = $responseData['payment_session_id'];
            
            return [
                'order_data' => $responseData,
                'payment_url' => $this->getPaymentUrl($paymentSessionId),
                'payment_session_id' => $paymentSessionId
            ];
        } catch (Exception $e) {
            return 'Failed to create order and payment URL: ' . $e->getMessage();
        }
    }
}