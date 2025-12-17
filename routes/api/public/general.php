<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgreementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HighlightEventController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\PopUpController;
use App\Http\Controllers\PromoteOrgController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserInfoController;
use Illuminate\Support\Facades\Route;

Route::post('resend-verification', [EmailVerificationController::class, 'resend'])->name('verification.resend');
Route::get('agreement/preview/{id}', [AgreementController::class, 'previewAgreement']);
Route::post('agreement/verify-user', [AgreementController::class, 'verifyUserForAgreement']);
// auth routes
Route::post('verify-user', [AuthController::class, 'verifyUser']);
Route::post('login', [AuthController::class, 'verifyUserRequest']);
// Route::post('login', [AuthController::class, 'verifyOTP']);
Route::post('register', [AuthController::class, 'register']);
Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);
Route::post('verify-Password', [AuthController::class, 'verifyPassword']);


// Route::post('/verify-otp', [AuthController::class, 'verifyOTP']);

Route::get('/settings', [SettingController::class, 'index']);

Route::get('/getAllData', [DashboardController::class, 'getAllData']);
Route::get('/gateway-wise-sales/{id}', [DashboardController::class, 'getGatewayWiseSalesData']);
Route::get('organizer/summary/{id}', [DashboardController::class, 'organizerTotals']);


// role routes
Route::get('tickets/{id}', [TicketController::class, 'index']);
Route::get('events', [EventController::class, 'index']);
Route::get('events-days/{day}', [EventController::class, 'dayWiseEvents']);
Route::get('events-whatsapp', [EventController::class, 'eventWhatsapp']);
Route::get('feature-event', [EventController::class, 'FeatureEvent']);
Route::post('create-user', [UserController::class, 'create'])->middleware('block.temp.email');
Route::get('event-detail/{id}', [EventController::class, 'edit']);
Route::get('edit-event/{id}/{step}', [EventController::class, 'editevent']);
Route::get('event-detail-whatsapp/{id}', [EventController::class, 'editWhatsapp']);
Route::post('/send-email/{id}', [EmailTemplateController::class, 'send']);
Route::get('/banners', [SettingController::class, 'getBanners']);
Route::get('banner-list/{type}', [BannerController::class, 'index']);
Route::get('highlightEvent-list', [HighlightEventController::class, 'index']);
Route::post('store-device', [UserInfoController::class, 'storeDeviceInfo']);
Route::get('user-devices/count', [UserInfoController::class, 'countUserDevices']);
Route::get('live-user', [UserInfoController::class, 'liveData']);
Route::get('delete-device-info', [UserInfoController::class, 'deleteDeviceInfo']);
Route::get('wc-mdl-list', [PopUpController::class, 'index']);
Route::get('/past-events', [EventController::class, 'pastEvents']);
Route::get('blogs', [BlogController::class, 'statusData']);
Route::get('blog-show/{id}', [BlogController::class, 'show']);
Route::get('related-blogs/{id}', [BlogController::class, 'cetegoryData']);
Route::get('blog-comment-show/{blog_id}', [BlogCommentController::class, 'show']);
Route::get('gan-card/{order_id}', [AgentController::class, 'ganerateCard']);
Route::get('generate-token/{order_id}', [AgentController::class, 'generate']);
Route::get('attendees-chek-in/{orderId}', [ScanController::class, 'attendeesChekIn']);
Route::post('attendees-verify/{orderId}', [ScanController::class, 'attendeesVerify']);
Route::get('/category-events/{title}', [EventController::class, 'eventsByCategory']);
Route::get('/events-filter', [EventController::class, 'eventsByData']);
Route::get('/landing-orgs', [EventController::class, 'landingOrg']);
Route::get('/landing-orgs/show-details/{organisation}', [EventController::class, 'landingOrgId']);
Route::get('promote-orgs', [PromoteOrgController::class, 'index']);
//new
Route::get('/global-search', [GlobalSearchController::class, 'search']);

Route::get('layout/theatre/{id}', [LayoutController::class, 'viewLayout']);
Route::get('onboarding/org', [AgreementController::class, 'onboardingList']);
Route::post('onboarding/org/action', [AgreementController::class, 'organizerAction']);
