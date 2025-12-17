<?php

use App\Http\Controllers\PhonePeController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/payment/phonepe/initiate', [PhonePeController::class, 'initiatePayment'])->name('phonepe.initiate');
Route::post('/payment/phonepe/callback', [PhonePeController::class, 'callback'])->name('phonepe.callback');

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//,'device.info'
Route::post('/payment-webhook/{gateway}/vod', [WebhookController::class, 'handleWebhook']);
Route::any('/payment-response/{gateway}/{id}/{session_id}', [WebhookController::class, 'handlePaymentResponse'])->middleware('restrict.payment');
