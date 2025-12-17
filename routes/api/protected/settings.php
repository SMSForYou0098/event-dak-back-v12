<?php

use App\Http\Controllers\AIDataGenerator;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\HighlightEventController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\PromoteOrgController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShortUrlController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\SystemVariableController;
use App\Http\Controllers\TaxController;
use Illuminate\Support\Facades\Route;

Route::get('generate-seo/{eventKey}', [AIDataGenerator::class, 'generateSeo']);

// alerts route
Route::get('send-mail', [MailController::class, 'send']);
Route::get('email-config', [MailController::class, 'index'])->middleware('permission:View Mail Config Setting');
Route::post('email-config', [MailController::class, 'store']);


//setting
Route::post('/setting', [SettingController::class, 'store']);
Route::post('/banners', [SettingController::class, 'storeBanner']);
Route::put('settings/live-user/{id}', [SettingController::class, 'updateLiveUser']);
Route::post('/sponsorsImages', [SettingController::class, 'sponsorsImages']);
Route::post('/pcSponsorsImages', [SettingController::class, 'pcSponsorsImages']);
// Route::get('/getSponsorsImages', [SettingController::class, 'getSponsorsImages']);
// Route::get('/getPcSponsorsImages', [SettingController::class, 'getPcSponsorsImages']);

//banner
Route::post('banner-store', [BannerController::class, 'store']);
Route::get('all-banners', [BannerController::class, 'allBanners']);
Route::post('banner-update/{id}', [BannerController::class, 'update']);
Route::get('banner-show/{id}', [BannerController::class, 'show']);
Route::delete('banner-destroy/{id}', [BannerController::class, 'destroy']);
Route::post('/rearrange-banner/{type}', [BannerController::class, 'rearrangeBanner']);


//highlight event
Route::post('highlightEvent-store', [HighlightEventController::class, 'store']);
Route::post('highlightEvent-update/{id}', [HighlightEventController::class, 'update']);
Route::get('highlightEvent-show/{id}', [HighlightEventController::class, 'show']);
Route::delete('highlightEvent-destroy/{id}', [HighlightEventController::class, 'destroy']);
Route::post('/rearrange-highlightEvent', [HighlightEventController::class, 'rearrangeHighlightEvent']);

//SMS
Route::get('/sms-api/{id}', [SmsController::class, 'index'])->middleware('permission:View SMS Config Setting');
Route::post('/store-api', [SmsController::class, 'DefaultApi']);
Route::post('/store-custom-api/{id}', [SmsController::class, 'CustomApi']);
Route::post('/sms-template/{id}', [SmsController::class, 'store']);
Route::post('/sms-template-update/{id}', [SmsController::class, 'update']);
Route::delete('/sms-template-delete/{id}', [SmsController::class, 'destroy']);
Route::post('/send-sms', [SmsController::class, 'sendSms']);

//gateways
Route::get('/payment-gateways/{user_id}', [PaymentGatewayController::class, 'getPaymentGateways'])->middleware('permission:View Payment Config Setting');
Route::post('/store-razorpay', [PaymentGatewayController::class, 'storeRazorpay']);
Route::post('/store-instamojo', [PaymentGatewayController::class, 'storeInstamojo']);
Route::post('/store-easebuzz', [PaymentGatewayController::class, 'storeEasebuzz']);
Route::post('/store-paytm', [PaymentGatewayController::class, 'storePaytm']);
Route::post('/store-stripe', [PaymentGatewayController::class, 'storeStripe']);
Route::post('/store-paypal', [PaymentGatewayController::class, 'storePayPal']);
Route::post('/store-phonepe', [PaymentGatewayController::class, 'storePhonePe']);
Route::post('/store-cashfree', [PaymentGatewayController::class, 'storeCashfree']);
Route::post('/test', [PaymentGatewayController::class, 'initiatePayment']);

// Route for storing tax records
Route::post('/taxes', [TaxController::class, 'store']);
Route::get('/taxes/{id}', [TaxController::class, 'index']);

Route::post('/commissions-store', [CommissionController::class, 'store']);
Route::get('/commissions/{id}', [CommissionController::class, 'index']);

//promocode
Route::get('promo-list/{id}', [PromoCodeController::class, 'list']);
Route::post('promo-store', [PromoCodeController::class, 'store']);
Route::get('promo-show/{id}', [PromoCodeController::class, 'show']);
Route::put('promo-update', [PromoCodeController::class, 'update']);
Route::delete('promo-destroy/{id}', [PromoCodeController::class, 'destroy']);
Route::post('check-promo-code/{id}', [PromoCodeController::class, 'checkPromoCode']);

// system veriable
Route::get('system-variables', [SystemVariableController::class, 'index']);
Route::post('system-variables-store', [SystemVariableController::class, 'store']);
Route::post('system-variables-update/{id}', [SystemVariableController::class, 'update']);
Route::delete('system-variables-destroy/{id}', [SystemVariableController::class, 'destroy']);

//notifictino
Route::post('/send-to-token', [NotificationController::class, 'sendToToken']);

// event notification
Route::post('send-notifications', [NotificationController::class, 'sendNotification']);

Route::get('/export-promocode', [PromoCodeController::class, 'export']);

//new ShortUrl
Route::post('/short-url', [ShortUrlController::class, 'create']);
Route::get('/long-url/{url}', [ShortUrlController::class, 'getLongUrl']);
Route::get('/s/{shortCode}', [ShortUrlController::class, 'redirectUrl']);

//new PromoteOrg
Route::post('promote-org', [PromoteOrgController::class, 'store']);
Route::post('promote-org/update/{id}', [PromoteOrgController::class, 'update']);
Route::delete('promote-org/delete/{id}', [PromoteOrgController::class, 'destroy']);
Route::post('promote-org/reorder', [PromoteOrgController::class, 'reorderPromote']);
