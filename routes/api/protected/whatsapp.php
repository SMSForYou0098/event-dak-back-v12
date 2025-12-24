<?php
use App\Http\Controllers\WhatsappConfigurationsController;
use Illuminate\Support\Facades\Route;
// whatsapp configuration dynamic
Route::get('whatsapp-config-show/{id}', [WhatsappConfigurationsController::class, 'show']);
Route::post('whatsapp-config-store', [WhatsappConfigurationsController::class, 'store']);
Route::delete('whatsapp-config-destroy/{id}', [WhatsappConfigurationsController::class, 'destroy']);
Route::get('whatsapp-api-show', [WhatsappConfigurationsController::class, 'listData']);
Route::get('whatsapp-api-show/{id}', [WhatsappConfigurationsController::class, 'list']);
Route::post('whatsapp-api-store', [WhatsappConfigurationsController::class, 'storeApi']);
Route::post('whatsapp-api-update/{id}', [WhatsappConfigurationsController::class, 'updateApi']);
Route::delete('whatsapp-api-destroy/{id}', [WhatsappConfigurationsController::class, 'deleteApi']);
Route::get('whatsapp-api/{id}/{title}', [WhatsappConfigurationsController::class, 'whatsappData']);
Route::get('whatsapp-apiData/{title}', [WhatsappConfigurationsController::class, 'whatsappTitleData']);