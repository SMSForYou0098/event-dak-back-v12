<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActivityStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // If no user found
        if (!$user || $user->status === 0) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized user'
            ], 401);
        }
        // If activity_status = TRUE → allow all APIs
        if ($user->activity_status === true) {
            return $next($request);
        }

        // If activity_status = FALSE → only allow update-user route
        // Example allowed routes:
        $allowedRoutes = [
            'update-user',
            'edit-user'
        ];

        if (in_array($request->route()->getName(), $allowedRoutes)) {
            return $next($request);
        }

        // Reject all other APIs
        return response()->json([
            'status' => false,
            'message' => 'Your account is in underprocess. Please contact administrator.'

        ], 423);
    }
}
