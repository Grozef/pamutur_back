<?php

use App\Http\Controllers\Api\PMUController;
use App\Http\Controllers\Api\MonitoringController;
use App\Http\Controllers\Api\DailyBetsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| FIX: Removed duplicate legacy routes. All routes now under /api/pmu prefix.
| For backward compatibility, consider using Route::redirect() if needed.
|
*/

// API PMU Routes
Route::prefix('pmu')->middleware('throttle:120,1')->group(function () {

    // Health check
    Route::get('/health', [PMUController::class, 'health']);

    // =====================================================
    // Daily Top Bets
    // =====================================================
    Route::get('/daily/top-bets', [DailyBetsController::class, 'getTopDailyBets']);
    Route::get('/daily/top-combinations', [DailyBetsController::class, 'getTopDailyCombinations']);

    // =====================================================
    // Monitoring routes (protected)
    // =====================================================
    Route::prefix('monitoring')->middleware('auth:sanctum')->group(function () {
        Route::get('/dashboard', [MonitoringController::class, 'dashboard']);
        Route::post('/races/{raceId}/record', [MonitoringController::class, 'recordPredictions'])
            ->where('raceId', '[0-9]+');
        Route::post('/races/{raceId}/update', [MonitoringController::class, 'updateResults'])
            ->where('raceId', '[0-9]+');
    });

    // =====================================================
    // Race Routes (internal database)
    // =====================================================

    // Get all races by date (for TopHorses)
    Route::get('/races', [PMUController::class, 'getRacesByDate']);

    // FIX: New endpoint to resolve race_id from PMU data
    Route::get('/races/resolve', [PMUController::class, 'resolveRaceId']);

    // Race predictions & analysis
    Route::get('/races/{raceId}/predictions', [PMUController::class, 'getRacePredictions'])
        ->where('raceId', '[0-9]+');

    // Value Bets avec Kelly Criterion
    Route::get('/races/{raceId}/value-bets', [PMUController::class, 'getValueBets'])
        ->where('raceId', '[0-9]+');

    // Combinaisons Tiercé
    Route::get('/races/{raceId}/combinations/tierce', [PMUController::class, 'getTierceCombinations'])
        ->where('raceId', '[0-9]+');

    // Combinaisons Quinté
    Route::get('/races/{raceId}/combinations/quinte', [PMUController::class, 'getQuinteCombinations'])
        ->where('raceId', '[0-9]+');

    // =====================================================
    // PMU External API Proxy Routes
    // =====================================================

    // Programme routes (fetch from PMU API)
    Route::get('/{date}', [PMUController::class, 'getProgramme'])
        ->where('date', '\d{8}|\d{4}-\d{2}-\d{2}');

    Route::get('/{date}/R{reunionNum}', [PMUController::class, 'getReunion'])
        ->where(['date' => '\d{8}|\d{4}-\d{2}-\d{2}', 'reunionNum' => '[0-9]+']);

    Route::get('/{date}/R{reunionNum}/C{courseNum}/participants', [PMUController::class, 'getParticipants'])
        ->where([
            'date' => '\d{8}|\d{4}-\d{2}-\d{2}',
            'reunionNum' => '[0-9]+',
            'courseNum' => '[0-9]+'
        ]);
});

// =====================================================
// API Versioning (v1) - Alias to main routes
// =====================================================
Route::prefix('v1')->group(function () {
    Route::redirect('/pmu/{path}', '/api/pmu/{path}')->where('path', '.*');
});