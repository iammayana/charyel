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

    // Future route groups (to be implemented in Phase 3B):
    // Route::post('/sync', [SyncController::class, 'handle']);
    // Route::get('/questions/delta', [QuestionDeltaController::class, 'delta']);
    // Route::get('/leaderboard/{scope}', [LeaderboardController::class, 'show']);
});
