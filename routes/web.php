<?php

use App\Events\ReportUpdated;
use App\Events\TestEvent;
use App\Http\Controllers\CashfreePaymentController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ShortUrlController;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/s/{shortCode}', [ShortUrlController::class, 'redirectUrl']);

// Email Verification Routes
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify');


Route::any('{any}', function () {
    return redirect()->away('https://getyourticket.in');
})->where('any', '.*');
