<?php

use App\Http\Controllers\AgreementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\LoginHistoryController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserTicketController;
use Illuminate\Support\Facades\Route;

Route::get('verify-user-settion', [AuthController::class, 'verifyUserSession']);

Route::get('login-history', [LoginHistoryController::class, 'index'])->middleware('permission:View Login History');

// Route::get('/getAllData/{id}', [DashboardController::class, 'getAllData']);

Route::post('/create-role', [RolePermissionController::class, 'createRole'])->middleware('permission:Create Role');
Route::get('/role-list', [RolePermissionController::class, 'getRoles']);
// Route::get('/role-list', [RolePermissionController::class, 'getRoles'])->middleware('permission:View Role');
// ;

Route::get('/role-edit/{id}', [RolePermissionController::class, 'EditRole'])->middleware('permission:Edit Role');
Route::post('/role-update', [RolePermissionController::class, 'UpdateRole']);
// permisson
Route::post('/create-permission', [RolePermissionController::class, 'createPermission']);
Route::get('/permission-list', [RolePermissionController::class, 'getPermissions']);
// });
Route::get('/permission-edit/{id}', [RolePermissionController::class, 'EditPermission']);
Route::post('/permission-update', [RolePermissionController::class, 'UpdatePermission']);

// role permission
Route::get('/role-permission/{id}', [RolePermissionController::class, 'getRolePermissions'])->middleware('permission:View Permission');
Route::post('/role-permission/{id}', [RolePermissionController::class, 'giveRolePermissions']);
// role permission
Route::get('/user-permission/{id}', [RolePermissionController::class, 'getUserPermissions']);
Route::post('/user-permission/{id}', [RolePermissionController::class, 'giveUserPermissions']);


//user route
Route::get('users', [UserController::class, 'index'])->middleware('permission:View User');
Route::get('users/list', [UserController::class, 'indexlist']);
Route::get('users-by-role/{role}', [UserController::class, 'getUsersByRole']);
Route::delete('user-delete/{id}', [UserController::class, 'destroy'])
    ->middleware('permission:Delete User');

Route::post('chek-email', [UserController::class, 'checkEmail']);
Route::post('chek-number-email', [UserController::class, 'checkMobile']);

Route::get('low-credit-users/{id}', [UserController::class, 'lowBalanceUser']);
// Route::get('edit-user/{id}', [UserController::class, 'edit']);
Route::get('chek-user/{id}', [UserController::class, 'CheckValidUser']);
Route::post('chek-password', [UserController::class, 'checkPassword']);
Route::post('update-security', [UserController::class, 'UpdateUserSecurity']);
// Route::post('update-user/{id}', [UserController::class, 'update']);
Route::post('update-user-alert/{id}', [UserController::class, 'updateAlerts']);
Route::get('scanner-token-length/{id}', [UserController::class, 'getQrLength']);

Route::post('create-bulk-user', [UserController::class, 'createBulkUsers']);

//org aggreement pdf
// Route::get('org-agreement-pdf/{id}', [UserController::class, 'orgAgreementPdf']);
// Route::get('agreement/view/{id}', [UserController::class, 'viewAgreement'])->name('agreement.view');
Route::get('agreement/download/{id}', [UserController::class, 'downloadAgreement'])->name('agreement.download');
//event-ticket
Route::get('event-ticket/{event_id}', [UserController::class, 'eventTicket']);

// passwrord change after login
Route::post('update-password/{id}', [AuthController::class, 'changePassword']);


Route::get('/logs', [UserController::class, 'logs']);
Route::delete('/clear-logs', [UserController::class, 'destroyLogs']);

//mail templates
Route::get('/email-templates/{id}', [EmailTemplateController::class, 'index']);
Route::post('/store-templates', [EmailTemplateController::class, 'store']);
Route::post('/update-templates', [EmailTemplateController::class, 'update']);

//user ticket
Route::get('user-ticket-list/{user_id}', [UserTicketController::class, 'index']);
Route::post('ticket-transfer', [UserTicketController::class, 'ticketTransfer']);

// org list
Route::get('organizers', [UserController::class, 'organizerList'])->middleware('permission:View Organizers');
Route::post('impersonate', [UserController::class, 'oneClickLogin']);
Route::post('revert-impersonation', [UserController::class, 'revertImpersonation']);

//export
Route::post('/export-users', [UserController::class, 'export'])->middleware('permission:Export Users');

//new agreement
Route::get('onboarding/org', [AgreementController::class, 'onboardingList']);
Route::post('onboarding/org/action', [AgreementController::class, 'organizerAction']);
Route::get('agreement', [AgreementController::class, 'index']);

Route::post('agreement', [AgreementController::class, 'store']);
Route::post('agreement/{id}', [AgreementController::class, 'update']);
Route::get('agreement-show/{id}', [AgreementController::class, 'show']);
Route::delete('agreement{id}', [AgreementController::class, 'destroy']);
