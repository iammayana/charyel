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
            Route::put('/guest/contact', [\App\Http\Controllers\Api\V1\AuthController::class, 'attachGuestContact']);
            Route::post('/guest/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        });

        // Contact Verification Endpoints (Accessible by authenticated guests or users)
        Route::middleware(['auth:sanctum'])->prefix('verification')->group(function () {
            Route::post('/challenge', [\App\Http\Controllers\Api\V1\VerificationController::class, 'challenge'])
                ->middleware('throttle:verification-challenge');
            Route::post('/verify', [\App\Http\Controllers\Api\V1\VerificationController::class, 'verify'])
                ->middleware('throttle:verification-verify');
            Route::get('/status', [\App\Http\Controllers\Api\V1\VerificationController::class, 'status']);
        });
    });

    // Online Quiz Gameplay Endpoints (Guest Only)
    Route::prefix('quiz/online')->group(function () {
        // Gameplay initiation and completion strictly require attached contact details
        Route::middleware(['auth:sanctum', 'auth.guest:guest:play', 'guest.contact'])->group(function () {
            Route::post('/start', [\App\Http\Controllers\Api\V1\OnlineQuizController::class, 'start']);
            Route::post('/complete', [\App\Http\Controllers\Api\V1\OnlineQuizController::class, 'complete']);
        });

        // Abandon and trial-status require authenticated guest principal with play capability
        Route::middleware(['auth:sanctum', 'auth.guest:guest:play'])->group(function () {
            Route::post('/abandon', [\App\Http\Controllers\Api\V1\OnlineQuizController::class, 'abandon']);
            Route::get('/trial-status', [\App\Http\Controllers\Api\V1\OnlineQuizController::class, 'trialStatus']);
        });
    });
});
