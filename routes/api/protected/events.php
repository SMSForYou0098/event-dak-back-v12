<?php

use App\Http\Controllers\AccessAreaController;
use App\Http\Controllers\AdditionalCategoryController;
use App\Http\Controllers\ArtistController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EventAttendyFieldController;
use App\Http\Controllers\EventContactController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventGetController;
use App\Http\Controllers\LRowController;
use App\Http\Controllers\LSeatController;
use App\Http\Controllers\LSectionController;
use App\Http\Controllers\LTiersController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\LZoneController;
use App\Http\Controllers\SeatConfigController;
use App\Http\Controllers\VanueController;
use Illuminate\Support\Facades\Route;

//eventroutes
Route::get('/events/active', [EventController::class, 'activeEvents']);
Route::get('pos-events/{id}', [EventController::class, 'eventByUser']);
Route::get('events/list/{id}', [EventController::class, 'eventList'])->middleware('permission:View Event');
Route::get('event-ticket-info/{id}', [EventController::class, 'info']);
Route::post('create-event', [EventController::class, 'create']);
Route::post('update-event/{id}', [EventController::class, 'update'])->middleware('permission:Edit Event');
Route::delete('junk-event/{id}', [EventController::class, 'junk']);
Route::get('org-event/{id}', [EventController::class, 'eventData']);
Route::get('events/attendee', [EventController::class, 'allEventData']);
Route::get('/layout/{eventKey}', [EventController::class, 'getLayoutByEventId']);

//new
Route::delete('event/delete/{event_id}', [EventController::class, 'deleteEvent'])->middleware('permission:Delete Event');
Route::post('event/restore/{event_id}', [EventController::class, 'restoreEvent']);
Route::get('event/junk/{user_id}', [EventController::class, 'deleteGetEvent']);
Route::delete('event/destroy/{event_id}', [EventController::class, 'destroy']);

//seatConfig
Route::get('seat-config/{id}', [SeatConfigController::class, 'index']);
Route::post('seat-config-store', [SeatConfigController::class, 'store']);
Route::post('event-seat-store', [SeatConfigController::class, 'storeEventSeat']);

//event gets
Route::get('event-gate-list/{event_id}', [EventGetController::class, 'index']);
Route::post('event-gate-store', [EventGetController::class, 'store']);
Route::post('event-gate-update/{id}', [EventGetController::class, 'update']);
Route::get('event-gate-show/{id}', [EventGetController::class, 'show']);
Route::delete('event-gate-destroy/{id}', [EventGetController::class, 'destroy']);

//event gets
Route::get('accessarea-list/{event_id}', [AccessAreaController::class, 'index']);
Route::post('accessarea-store', [AccessAreaController::class, 'store']);
Route::post('accessarea-update/{id}', [AccessAreaController::class, 'update']);
Route::get('accessarea-show/{id}', [AccessAreaController::class, 'show']);
Route::delete('accessarea-destroy/{id}', [AccessAreaController::class, 'destroy']);

//new layout
Route::get('layouts/theatre', [LayoutController::class, 'index']);
Route::post('auditorium/layout/save', [LayoutController::class, 'storeLayout']);
Route::post('auditorium/clone/layout', [LayoutController::class, 'duplicateLayout']);
Route::post('auditorium/layout/{layoutId}', [LayoutController::class, 'updateLayout']);

Route::post('event/layout/{event_key}', [LayoutController::class, 'eventLayoutSubmit']);
Route::get('event/layout/{event_key}', [LayoutController::class, 'eventLayoutGet']);
Route::delete('layouts/theatre/{id}', [LayoutController::class, 'destroy']);

Route::get('zone-list', [LZoneController::class, 'index']);
Route::post('zone-store', [LZoneController::class, 'store']);
Route::post('zone-update/{id}', [LZoneController::class, 'update']);
Route::get('zone-show/{id}', [LZoneController::class, 'show']);
Route::delete('zone-destroy/{id}', [LZoneController::class, 'destroy']);

Route::get('tier-list', [LTiersController::class, 'index']);
Route::post('tier-store', [LTiersController::class, 'store']);
Route::post('tier-update/{id}', [LTiersController::class, 'update']);
Route::get('tier-show/{id}', [LTiersController::class, 'show']);
Route::delete('tier-destroy/{id}', [LTiersController::class, 'destroy']);

Route::get('section-list', [LSectionController::class, 'index']);
Route::post('section-store', [LSectionController::class, 'store']);
Route::post('section-update/{id}', [LSectionController::class, 'update']);
Route::get('section-show/{id}', [LSectionController::class, 'show']);
Route::delete('section-destroy/{id}', [LSectionController::class, 'destroy']);

Route::get('row-list', [LRowController::class, 'index']);
Route::post('row-store', [LRowController::class, 'store']);
Route::post('row-update/{id}', [LRowController::class, 'update']);
Route::get('row-show/{id}', [LRowController::class, 'show']);
Route::delete('row-destroy/{id}', [LRowController::class, 'destroy']);

Route::get('seat-list', [LSeatController::class, 'index']);
Route::post('seat-store', [LSeatController::class, 'store']);
Route::post('seat-update/{id}', [LSeatController::class, 'update']);
Route::get('seat-show/{id}', [LSeatController::class, 'show']);
Route::delete('seat-destroy/{id}', [LSeatController::class, 'destroy']);

Route::post('/export-events', [EventController::class, 'export'])->middleware('permission:Export Events');

//new artist
Route::get('artist-list/{id}', [ArtistController::class, 'index']);
Route::get('artists', [ArtistController::class, 'artistsData']);
Route::post('artist-store', [ArtistController::class, 'store']);
Route::post('artist-update/{id}', [ArtistController::class, 'update']);
Route::get('artist-show/{id}', [ArtistController::class, 'show']);
Route::delete('artist-destroy/{id}', [ArtistController::class, 'destroy']);

//new venu
Route::get('venue-list', [VanueController::class, 'index']);
Route::get('venues', [VanueController::class, 'venusData']);
Route::post('venue-store', [VanueController::class, 'store']);
Route::post('venue-update/{id}', [VanueController::class, 'update']);
Route::get('venue-show/{id}', [VanueController::class, 'show']);
Route::delete('venue-destroy/{id}', [VanueController::class, 'destroy']);

//new AddCategory
Route::get('addCate-list/{id}', [AdditionalCategoryController::class, 'index']);
Route::post('addCate-store', [AdditionalCategoryController::class, 'store']);
Route::post('addCate-update/{id}', [AdditionalCategoryController::class, 'update']);
Route::get('addCate-show/{id}', [AdditionalCategoryController::class, 'show']);
Route::delete('addCate-destroy/{id}', [AdditionalCategoryController::class, 'destroy']);

//new event fields
Route::get('event-fields-list', [EventAttendyFieldController::class, 'eventFieldsList']);
Route::get('event-fields-list/{title}', [EventAttendyFieldController::class, 'eventFieldsListId']);
Route::post('event-fields-store', [EventAttendyFieldController::class, 'eventFields']);
Route::post('event-fields-update/{id}', [EventAttendyFieldController::class, 'eventFieldsUpdate']);
Route::delete('event-fields-delelte/{id}', [EventAttendyFieldController::class, 'eventFieldsdestroy']);

//new
Route::get('event/category/fields/{eventId}', [CategoryController::class, 'getEventFields']);

// Event Contacts
Route::post('event/contacts', [EventContactController::class, 'getContacts']);
