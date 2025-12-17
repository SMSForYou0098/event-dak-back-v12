<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

//notification
Route::post('/notifications/save-token', [NotificationController::class, 'storeToken']);
Route::post('/sendToAll', [NotificationController::class, 'sendToAll']);

Route::get('/run-command', function () {
    Artisan::call('optimize:clear');
    // Artisan::call('storage:link');
    $output = Artisan::output();

    return $output;
});
