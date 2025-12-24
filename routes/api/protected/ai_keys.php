<?php

use App\Http\Controllers\AiApiKeyController;
use Illuminate\Support\Facades\Route;

Route::apiResource('ai-api-keys', AiApiKeyController::class);
