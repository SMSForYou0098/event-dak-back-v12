<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AttndyController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ComplimentaryBookingController;
use App\Http\Controllers\CorporateBookingController;
use App\Http\Controllers\CorporateUserController;
use App\Http\Controllers\ExhibitionBookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResendTicketController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SponsorBookingController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// Route::get('tickets/{id}', [TicketController::class, 'index']);
Route::post('create-ticket/{id}', [TicketController::class, 'create']);
Route::get('bookings/pos/{id}', [PosController::class, 'index']);
Route::post('update-ticket/{id}', [TicketController::class, 'update']);
Route::delete('ticket-delete/{id}', [TicketController::class, 'destroy']);
Route::get('ticket-info/{id}', [TicketController::class, 'info']);
Route::get('user-ticket-info/{user_id}/{ticket_id}', [TicketController::class, 'userTicketInfo']);

// Bookings routes 
Route::get('bookings/event-wise-sales/{type}', [BookingController::class, 'eventWiseTicketSales']);
Route::get('bookings/summary/{type}', [BookingController::class, 'BookingSummary']);
Route::post('booking-mail/{id}', [BookingController::class, 'sendBookingMail']);
Route::get('bookings/{type}/{id}', [BookingController::class, 'list']);
Route::get('agent-bookings/{id}', [BookingController::class, 'agentBooking']);
Route::get('sponsor-bookings/{id}', [BookingController::class, 'sponsorBooking']);
Route::post('/resend', [BookingController::class, 'resend']);
Route::delete('delete-booking/{id}/{token}', [BookingController::class, 'destroy']);
Route::get('restore-booking/{id}/{token}', [BookingController::class, 'restoreBooking']);
Route::get('user-bookings/{userId}', [BookingController::class, 'getUserBookings']);
Route::post('/verify-booking', [BookingController::class, 'verifyBooking']);
Route::get('bookings/pending/{id}', [BookingController::class, 'pendingBookingList']);
Route::post('booking-confirm/{id}', [BookingController::class, 'pendingBookingConform']);

//scan routes
Route::post('verify-ticket/{orderId}', [ScanController::class, 'verifyTicket']);
Route::get('chek-in/{orderId}', [ScanController::class, 'ChekIn']);
Route::get('scan-histories', [ScanController::class, 'getScanHistories']);

//Agent booking
Route::post('booking/{type}/{id}', [AgentController::class, 'store']);
Route::post('agent-master-booking/{id}', [AgentController::class, 'agentMaster']);

Route::get('user-form-number/{id}', [AgentController::class, 'userFormNumber']);
Route::get('restore/{type}/{token}', [AgentController::class, 'restoreBooking']);
Route::delete('disable/{type}/{token}', [AgentController::class, 'destroy']);

//sponsor booking
Route::post('sponsor-book-ticket/{id}', [SponsorBookingController::class, 'store']);
Route::post('sponsor-master-booking/{id}', [SponsorBookingController::class, 'sponsorMaster']);
Route::get('sponsor/list/{id}', [SponsorBookingController::class, 'list'])->middleware('permission:View Sponsor Bookings');
// Route::get('user-form-number/{id}', [SponsorBookingController::class, 'userFormNumber']);
Route::get('sponsor-restore-booking/{token}', [SponsorBookingController::class, 'restoreBooking']);
Route::delete('sponsor-delete-booking/{token}', [SponsorBookingController::class, 'destroy']);

//pos
Route::post('booking/pos', [PosController::class, 'create']);
Route::delete('booking/pos/delete/{id}', [PosController::class, 'destroy']);
Route::get('booking/pos/restore/{id}', [PosController::class, 'restoreBooking']);
Route::get('pos/ex-user/{number}', [PosController::class, 'posDataByNumber']);



//ExhibitionBooking
Route::post('book-exhibition/{id}', [ExhibitionBookingController::class, 'create']);
Route::get('exhibition-bookings/{id}', [ExhibitionBookingController::class, 'index'])->middleware('permission:View Exhibition Bookings');
Route::delete('exihibition/delete-booking/{token}', [ExhibitionBookingController::class, 'destroy']);
Route::get('exihibition/restore-booking/{token}', [ExhibitionBookingController::class, 'restoreBooking']);

//balance routes
Route::get('balance-history/{id}', [BalanceController::class, 'index']);
Route::post('add-balance', [BalanceController::class, 'create']);
Route::post('deduct-balance', [BalanceController::class, 'deductBalance']);
Route::get('chek-user/{id}', [BalanceController::class, 'CheckValidUser']);
Route::post('wallet-user/{id}', [BalanceController::class, 'walletUser']);
Route::post('debit-wallet', [BalanceController::class, 'processTransaction']);
Route::get('user-transactions/{id}', [BalanceController::class, 'allBalance']);
Route::get('transactions-summary/{id}', [BalanceController::class, 'transactionsOverView']);
Route::get('shopKeeper-dashbord/{id}', [BalanceController::class, 'shopKeeperDashbord']);
Route::get('transactions-data/{id}', [BalanceController::class, 'walletData']);

//complimentary
// Route to store new complimentary bookings
Route::get('/complimentary-bookings/{id}', [ComplimentaryBookingController::class, 'index'])->middleware('permission:View Complimentary Booking');
Route::post('/complimentary-booking-store', [ComplimentaryBookingController::class, 'storeData']);
Route::post('/complimentary-booking', [ComplimentaryBookingController::class, 'store']);
Route::post('/fetch-batch-cb/{id}', [ComplimentaryBookingController::class, 'getTokensByBatchId']);
Route::get('/complimatory/restore-booking/{id}', [ComplimentaryBookingController::class, 'restoreComplimentaryBooking']);
Route::delete('/complimatory/delete-booking/{id}', [ComplimentaryBookingController::class, 'destroy']);

//payment
Route::post('/initiate-payment', [PaymentController::class, 'processPayment']);

//corporate  
Route::post('/corporate-user-store', [CorporateUserController::class, 'corporateUserStore']);
Route::post('/corporateUser/update/{id}', [CorporateUserController::class, 'corporateUserUpdate']);
Route::get('/corporate-attendee/{userId}/{category_id}', [CorporateUserController::class, 'corporateUserAttendy']);

Route::post('corporate-pos/{id}', [CorporateBookingController::class, 'create']);
Route::get('corporate-bookings/{id}', [CorporateBookingController::class, 'index'])->middleware('permission:View Corporate Bookings');
Route::delete('delete-corporate-booking/{id}', [CorporateBookingController::class, 'destroy']);
Route::get('restore-corporate-booking/{id}', [CorporateBookingController::class, 'restoreBooking']);
Route::get('corporate/ex-user/{number}', [CorporateBookingController::class, 'corporateDataByNumber']);

Route::post('/export-onlineBooking', [BookingController::class, 'export'])->middleware('permission:Export Online Bookings');
Route::post('/export-attndy/{event_id}', [AttndyController::class, 'export'])->middleware('permission:Export Attendees');
Route::post('/export-agentBooking', [AgentController::class, 'export'])->middleware('permission:Export Agent Bookings');
Route::post('/export-sponsorBooking', [SponsorBookingController::class, 'export'])->middleware('permission:Export Sponsor Bookings');
Route::post('/export-posBooking', [PosController::class, 'export'])->middleware('permission:Export POS Bookings');
Route::post('/export-corporateBooking', [CorporateBookingController::class, 'export']);
Route::post('/export-complimentaryBooking', [ComplimentaryBookingController::class, 'export']);
Route::post('/export-event-reports', [ReportController::class, 'exportEventReport']);
Route::post('/export-agent-reports', [ReportController::class, 'exportAgentReport']);
Route::post('/export-pos-reports', [ReportController::class, 'exportPosReport']);

//new
Route::post('/resend-ticket', [ResendTicketController::class, 'resendTicket']);
