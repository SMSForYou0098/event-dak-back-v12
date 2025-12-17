<?php

use App\Http\Controllers\AttndyController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ComplimentaryBookingController;
use App\Http\Controllers\ContactUsController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FooterGrouController;
use App\Http\Controllers\FooterMenuController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MenuGroupController;
use App\Http\Controllers\MisController;
use App\Http\Controllers\NavigationMenuController;
use App\Http\Controllers\PagesController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SocialMediaController;
use App\Http\Controllers\SuccessfulEventController;
use App\Http\Controllers\WhatsappConfigurationsController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

//import zip file
Route::post('/import-zip', [ImportController::class, 'importZip']);
Route::get('/merge-profile-photos', [ImportController::class, 'mergeProfilePhotos']);
Route::get('/copy-original-profile-photos', [ImportController::class, 'copyOriginalProfilePhotos']);

//ContactUs and  query
Route::get('contac-list', [ContactUsController::class, 'index']);
Route::post('contac-store', [ContactUsController::class, 'store']);
Route::post('contac-update/{id}', [ContactUsController::class, 'update']);
Route::get('contac-show/{id}', [ContactUsController::class, 'show']);
Route::delete('contac-destroy/{id}', [ContactUsController::class, 'destroy']);

//query
Route::get('query-list', [QueryController::class, 'index']);
Route::post('query-store', [QueryController::class, 'store']);
Route::post('query-update/{id}', [QueryController::class, 'update']);
Route::get('query-show/{id}', [QueryController::class, 'show']);
Route::delete('query-destroy/{id}', [QueryController::class, 'destroy']);

//faq
Route::get('faq-list', [FaqController::class, 'index']);
Route::post('faq-store', [FaqController::class, 'store']);
Route::post('faq-update/{id}', [FaqController::class, 'update']);
Route::get('faq-show/{id}', [FaqController::class, 'show']);
Route::delete('faq-destroy/{id}', [FaqController::class, 'destroy']);

//mis report
Route::get('/mis-report', [MisController::class, 'misData']);
Route::get('box-office-bookings/{number}', [BookingController::class, 'boxOfficeBooking']);

// Route::post('wallet-user-transaction', [BalanceController::class, 'processTransaction']);
// Route::post('verify-ticket/{orderId}', [BookingController::class, 'verifyTicket']);
//SuccessfulEvent
Route::GET('successfulEvent', [SuccessfulEventController::class, 'index']);
Route::post('successfulEvent-store', [SuccessfulEventController::class, 'store']);
Route::post('successfulEvent-update/{id}', [SuccessfulEventController::class, 'update']);
Route::delete('successfulEvent-destroy/{id}', [SuccessfulEventController::class, 'destroy']);
Route::get('expired-events', [SuccessfulEventController::class, 'getExpiredEvents']);

// whatsapp configuration dynamic
Route::get('whatsapp-config-show/{id}', [WhatsappConfigurationsController::class, 'show']);
Route::post('whatsapp-config-store/{id}', [WhatsappConfigurationsController::class, 'store']);
Route::delete('whatsapp-config-destroy/{id}', [WhatsappConfigurationsController::class, 'destroy']);
Route::get('whatsapp-api-show', [WhatsappConfigurationsController::class, 'listData']);
Route::get('whatsapp-api-show/{id}', [WhatsappConfigurationsController::class, 'list']);
Route::post('whatsapp-api-store', [WhatsappConfigurationsController::class, 'storeApi']);
Route::post('whatsapp-api-update/{id}', [WhatsappConfigurationsController::class, 'updateApi']);
Route::delete('whatsapp-api-destroy/{id}', [WhatsappConfigurationsController::class, 'deleteApi']);
Route::get('whatsapp-api/{id}/{title}', [WhatsappConfigurationsController::class, 'whatsappData']);
Route::get('whatsapp-apiData/{title}', [WhatsappConfigurationsController::class, 'whatsappTitleData']);

//
Route::post('/complimentary-booking/check/users', [ComplimentaryBookingController::class, 'checkUsers']);

//live user count
// Route::get('/live-user-count', [LiveUserController::class, 'getLiveUserCount']);
// Route::post('/live-user-count-store', [LiveUserController::class, 'store']);
// Route::delete('/live-user-count-destroy', [LiveUserController::class, 'destroy']);

//retrav iamges path
Route::post('get-image/retrive', [BookingController::class, 'imagesRetrive']);
Route::post('get-user-image/retrive', [BookingController::class, 'userImagesRetrive']);




//attendy
Route::get('fields-list', [AttndyController::class, 'fieldsList']);
Route::get('fields-name', [AttndyController::class, 'fieldsListName']);
Route::post('field-store', [AttndyController::class, 'store']);
Route::post('field-update/{id}', [AttndyController::class, 'update']);
Route::delete('field-delete/{id}', [AttndyController::class, 'destroy']);
Route::get('catrgoty-fields-list', [AttndyController::class, 'catrgotyFieldsList']);
Route::get('catrgoty-fields-list/{title}', [AttndyController::class, 'catrgotyFieldsListId']);
Route::post('catrgoty-fields-store', [AttndyController::class, 'catrgotyFields']);
Route::post('catrgoty-fields-update/{id}', [AttndyController::class, 'catrgotyFieldsUpdate']);
Route::delete('catrgoty-fields-delelte/{id}', [AttndyController::class, 'catrgotyFieldsdestroy']);
Route::post('/rearrange-CustomField', [AttndyController::class, 'rearrangeCustomField']);
Route::post('/attndy-store', [AttndyController::class, 'attndyStore']);
Route::get('/user-attendee/{userId}/{category_id}', [AttndyController::class, 'userAttendy']);
Route::post('/attendees/update/{id}', [AttndyController::class, 'attndyUpdate']);
Route::get('/attendee-list/{userId}/{event_id}', [AttndyController::class, 'attendyList']);

//eazebuzz
Route::get('/getSponsorsImages', [SettingController::class, 'getSponsorsImages']);
Route::get('/getPcSponsorsImages', [SettingController::class, 'getPcSponsorsImages']);
Route::get('pages-get-title', [PagesController::class, 'getTitle']);
Route::get('pages-title/{title}', [PagesController::class, 'pageTitle']);
Route::post('/footer-data', [SettingController::class, 'footerData']);
Route::get('/footer-data-get', [SettingController::class, 'footerDataGet']);

Route::get('footer-group', [FooterGrouController::class, 'index']);
Route::post('/footer-group-store', [FooterGrouController::class, 'store']);
Route::post('/footer-group-update/{id}', [FooterGrouController::class, 'update']);
Route::get('footer-group-show/{id}', [FooterGrouController::class, 'show']);
Route::delete('footer-group-destroy/{id}', [FooterGrouController::class, 'destroy']);

Route::get('footer-menu/{id}', [FooterMenuController::class, 'index']);
Route::post('/footer-menu-store', [FooterMenuController::class, 'store']);
Route::post('/footer-menu-update/{id}', [FooterMenuController::class, 'update']);
Route::get('footer-menu-show/{id}', [FooterMenuController::class, 'show']);
Route::delete('footer-menu-destroy/{id}', [FooterMenuController::class, 'destroy']);

Route::get('nav-menu', [NavigationMenuController::class, 'index']);
Route::post('/nav-menu-store', [NavigationMenuController::class, 'store']);
Route::post('/nav-menu-update/{id}', [NavigationMenuController::class, 'update']);
Route::get('nav-menu-show/{id}', [NavigationMenuController::class, 'show']);
Route::delete('nav-menu-destroy/{id}', [NavigationMenuController::class, 'destroy']);
Route::post('/rearrange-menu', [NavigationMenuController::class, 'rearrangeMenu']);

Route::get('menu-group', [MenuGroupController::class, 'index']);
Route::post('/menu-group-store', [MenuGroupController::class, 'store']);
Route::post('/menu-group-update/{id}', [MenuGroupController::class, 'update']);
Route::get('menu-group-show/{id}', [MenuGroupController::class, 'show']);
Route::delete('menu-group-destroy/{id}', [MenuGroupController::class, 'destroy']);
Route::post('/update-status', [MenuGroupController::class, 'updateStatus']);
Route::get('/active-menu', [MenuGroupController::class, 'activeStatus']);
Route::get('/menu-title/{title}', [MenuGroupController::class, 'menuTitle']);

Route::get('category', [CategoryController::class, 'index']);
Route::post('/category-store', [CategoryController::class, 'store']);
Route::post('/category-update/{id}', [CategoryController::class, 'update']);
Route::get('/category-show/{id}', [CategoryController::class, 'show']);
Route::delete('/category-destroy/{id}', [CategoryController::class, 'destroy']);
Route::get('/category-data/{id}', [CategoryController::class, 'categoryTitle']);
Route::get('/category-title', [CategoryController::class, 'allCategoryTitle']);
Route::get('/category-images', [CategoryController::class, 'allCategoryImages']);
Route::post('/payment-log', [CategoryController::class, 'allData']);
Route::get('get-layout/{user_id}', [CategoryController::class, 'layoutList']);

Route::get('socialMedia', [SocialMediaController::class, 'index']);
Route::post('/socialMedia-store', [SocialMediaController::class, 'store']);
Route::post('/socialMedia-update/{id}', [SocialMediaController::class, 'update']);
Route::get('/socialMedia-show/{id}', [SocialMediaController::class, 'show']);
Route::delete('/socialMedia-destroy/{id}', [SocialMediaController::class, 'destroy']);

//dairect data base mathi data export
Route::post('/import-tickets', [SocialMediaController::class, 'importExcel']);

// Route::post('/attndy-store-whatsapp', [AttndyController::class, 'attndyStorewhatsapp']);

// prem
Route::get('/attendee_images/{event_id}', [AttndyController::class, 'attendeeImages']);
Route::get('/attendee_jsone', [AttndyController::class, 'attendeeJsone']);
