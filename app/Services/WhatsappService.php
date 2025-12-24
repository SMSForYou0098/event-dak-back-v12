<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WhatsappService
{
    public function send($data)
    {
        try {
            // Validate required data
            if (empty($data->number)) {
                Log::error('WhatsApp Service: Missing recipient number', [
                    'data' => $data
                ]);
                return ['error' => 'Recipient number is required'];
            }

            // Sanitize and format phone number
            $number = preg_replace('/[^0-9]/', '', $data->number);
            $modifiedNumber = strlen($number) === 10 ? '91' . $number : $number;

            Log::info('WhatsApp Service: Processing send request', [
                'original_number' => $data->number,
                'formatted_number' => $modifiedNumber,
                'template' => $data->whatsappTemplateData ?? 'not_provided'
            ]);

            $template = $data->whatsappTemplateData ?? '';

            if (empty($template)) {
                Log::warning('WhatsApp Service: Template name not provided', [
                    'number' => $modifiedNumber
                ]);
            }

            // Media URL
            // $mediaurl = $data->mediaurl ?? '';
            $mediaurl = "https://cricket.getyourticket.in/uploads/thumbnail/6915a7c061fb3_sunset-7133867_1280%20(1).jpg";
            $data->buttonValue = [$data->shortLink ?? '', $data->insta_whts_url ?? 'helloinsta'];

            // Fetch admin WhatsApp configuration
            $admin = User::role('Admin', 'api')->with('whatsappConfig')->first();
            
            if (!$admin || !$admin->whatsappConfig || !isset($admin->whatsappConfig[0])) {
                Log::error('WhatsApp Service: Admin WhatsApp configuration not found', [
                    'admin_exists' => !!$admin,
                    'config_exists' => $admin ? !!$admin->whatsappConfig : false
                ]);
                return ['error' => 'Admin WhatsApp configuration not found'];
            }

            $apiKey = $admin->whatsappConfig[0]->api_key ?? null;

            if (!$apiKey) {
                Log::error('WhatsApp Service: API Key missing in configuration', [
                    'config_id' => $admin->whatsappConfig[0]->id ?? null
                ]);
                return ['error' => 'API Key missing'];
            }

            // Template values
            $value = $data->values ?? [];

            $whatsappApi = "https://waba.smsforyou.biz/api/send-messages";
            $params = [
                'apikey'       => $apiKey,
                'to'           => $modifiedNumber,
                'type'         => 'T',
                'tname'        => $template,
                'values'       => implode(',', $value),
                'media_url'    => $mediaurl,
                'button_value' => is_array($data->buttonValue) ? implode(',', $data->buttonValue) : ($data->buttonValue ?? ''),
            ];

            Log::info('WhatsApp Service: Sending request', [
                'to' => $modifiedNumber,
                'template' => $template,
                'values_count' => count($value),
                'has_media' => !empty($mediaurl),
                'button_value' => $params['button_value']
            ]);

            // Send HTTP request
            $response = Http::timeout(30)->get($whatsappApi, $params);

            // Log response
            if ($response->successful()) {
                Log::info('WhatsApp Service: Message sent successfully', [
                    'to' => $modifiedNumber,
                    'template' => $template,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => true,
                    'message' => 'WhatsApp sent successfully',
                    'url' => $whatsappApi . '?' . http_build_query($params)
                ];
            } else {
                Log::error('WhatsApp Service: Failed to send message', [
                    'to' => $modifiedNumber,
                    'template' => $template,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'response_headers' => $response->headers()
                ]);

                return [
                    'error' => 'Failed to send WhatsApp',
                    'status_code' => $response->status(),
                    'details' => $response->body()
                ];
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Network/connection errors
            Log::error('WhatsApp Service: Connection error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'number' => $data->number ?? 'unknown'
            ]);

            return [
                'error' => 'Network connection error',
                'message' => 'Failed to connect to WhatsApp service',
                'details' => $e->getMessage()
            ];
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // HTTP request errors
            Log::error('WhatsApp Service: Request error', [
                'error' => $e->getMessage(),
                'response' => $e->response ? $e->response->body() : 'No response',
                'number' => $data->number ?? 'unknown'
            ]);

            return [
                'error' => 'Request error',
                'message' => 'Failed to send WhatsApp request',
                'details' => $e->getMessage()
            ];
        } catch (\Exception $e) {
            // General exceptions
            Log::error('WhatsApp Service: Unexpected error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'number' => $data->number ?? 'unknown',
                'template' => $data->whatsappTemplateData ?? 'unknown'
            ]);

            return [
                'error' => 'Unexpected error',
                'message' => 'An unexpected error occurred while sending WhatsApp',
                'details' => $e->getMessage()
            ];
        }
    }
}
