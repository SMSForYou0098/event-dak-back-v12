<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('edit-user/{id}', [UserController::class, 'edit']);
Route::post('update-user/{id}', [UserController::class, 'update']);
