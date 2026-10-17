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

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        // Public Authentication Endpoints
        Route::post('/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register'])
            ->middleware('throttle:register');
        Route::post('/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login'])
            ->middleware('throttle:login');
        Route::post('/guest', [\App\Http\Controllers\Api\V1\AuthController::class, 'guest'])
            ->middleware('throttle:guest');

        // Universal Logout (Revokes current token or session for any authenticated principal)
        Route::post('/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout'])
            ->middleware('auth:sanctum');

        // Registered User Protected Endpoints
        Route::middleware(['auth:sanctum', 'auth.user:user:all'])->group(function () {
            Route::get('/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
            Route::post('/logout-all', [\App\Http\Controllers\Api\V1\AuthController::class, 'logoutAll']);
        });

        // Anonymous Guest Protected Endpoints
        Route::middleware(['auth:sanctum', 'auth.guest:guest:play'])->group(function () {
            Route::get('/guest/me', [\App\Http\Controllers\Api\V1\AuthController::class, 'guestMe']);
            Route::post('/guest/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        });
    });
});
