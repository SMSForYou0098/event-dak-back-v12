<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/opration', function () {
    // Artisan::call('storage:link');
    // Artisan::call('cache:clear');
    Artisan::call('optimize:clear');

    return 'cleared';
});

Route::prefix('dark')->group(function () {
    // Public / Webhooks
    require __DIR__ . '/api/public/webhooks.php';

    Route::middleware(['restrict.ip'])->group(function () {
        // Public General Routes
        require __DIR__ . '/api/public/general.php';

        Route::middleware(['auth:api'])->group(function () {
            // Auth User Routes (No check.activity)
            require __DIR__ . '/api/auth_user.php';

            Route::middleware(['check.activity'])->group(function () {
                // Protected Routes
                require __DIR__ . '/api/protected/dashboard.php';
                require __DIR__ . '/api/protected/users.php';
                require __DIR__ . '/api/protected/events.php';
                require __DIR__ . '/api/protected/bookings.php';
                require __DIR__ . '/api/protected/settings.php';
                require __DIR__ . '/api/protected/content.php';
                require __DIR__ . '/api/protected/labelPrint.php';
            });
        });

        // Public Content Routes (Under restrict.ip but not auth:api)
        require __DIR__ . '/api/public/content.php';
    });

    // Public Notifications (No restrict.ip)
    require __DIR__ . '/api/public/notifications.php';
});
