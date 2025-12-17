<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('getDashboardOrgTicket', [DashboardController::class, 'getDashboardOrgTicket']);

//Dashboard routes
Route::get('/bookingCount/{id}', [DashboardController::class, 'BookingCounts']);
Route::get('/calculateSale/{id}', [DashboardController::class, 'calculateSale']);
Route::get('/user-stats', [DashboardController::class, 'getUserStatistics']);
Route::get('dashboard/org/{type}/{id}', [DashboardController::class, 'dashbordOrgData']);
Route::get('org/dashbord', [DashboardController::class, 'organizerWeeklyReport']);
Route::get('/payment-log', [DashboardController::class, 'getPaymentLog']);
Route::delete('/flush-payment-log', [DashboardController::class, 'PaymentLogDelet']);

// reports route
Route::get('/agent-report', [ReportController::class, 'AgentReport'])->middleware('permission:View Agent Reports');
Route::get('/sponsor-report', [ReportController::class, 'SponsorReport']);
Route::get('/accreditation-report', [ReportController::class, 'AccreditationReport']);
Route::get('/event-reports/{id}', [ReportController::class, 'EventReport'])->middleware('permission:View Event Reports');
Route::get('/pos-report', [ReportController::class, 'PosReport'])->middleware('permission:View POS Reports');
// Route::get('/organizer-report', [ReportController::class, 'OrganizerReport']);

//comman org report
Route::get('/org-list-report', [ReportController::class, 'orgListReport']);
Route::get('/organizer-events-report', [ReportController::class, 'organizerEventsReport']);

//new booking-stats
Route::get('booking-stats/{type}/{id}', [BookingController::class, 'bookingStats']);
