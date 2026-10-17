<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Charyel API Routes
|--------------------------------------------------------------------------
|
| Versioned REST API endpoints for Charyel.
| Standard prefix: /api/v1/*
|
*/

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'service' => 'Charyel API',
            'version' => 'v1',
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
        ]);
    });

    // Authentication Routes (Registered Users)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register'])
            ->middleware('throttle:register');
        Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::middleware(['auth:sanctum', 'auth.user:user:all'])->group(function () {
            Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
            Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
            Route::post('/logout-all', [\App\Http\Controllers\Api\V1\AuthController::class, 'logoutAll']);
        });
    });
});
