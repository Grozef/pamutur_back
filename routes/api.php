<?php

use App\Http\Controllers\Api\PMUController;
use App\Http\Controllers\Api\MonitoringController;
use Illuminate\Support\Facades\Route;

// API Version 1
Route::prefix('v1/pmu')->middleware('throttle:60,1')->group(function () {

    // Health check
    Route::get('/health', [PMUController::class, 'health']);

    // Monitoring routes
    Route::prefix('monitoring')->group(function () {
        Route::get('/dashboard', [MonitoringController::class, 'dashboard']);
        Route::post('/races/{raceId}/record', [MonitoringController::class, 'recordPredictions']);
        Route::post('/races/{raceId}/update', [MonitoringController::class, 'updateResults']);
    });

    // Specific routes first
    Route::get('/races', [PMUController::class, 'getRacesByDate']);
    Route::get('/races/{raceId}/predictions', [PMUController::class, 'getRacePredictions']);
    Route::delete('/races/{raceId}/cache', [PMUController::class, 'clearRaceCache']);
    Route::get('/find-race', [PMUController::class, 'findRaceByCode']);

    // Horse routes
    Route::prefix('horses')->group(function () {
        Route::get('/search', [PMUController::class, 'searchHorses'])
            ->middleware('throttle:30,1');
        Route::get('/{horseId}', [PMUController::class, 'getHorseDetails']);
        Route::get('/{horseId}/stallion-stats', [PMUController::class, 'getStallionStats']);
    });

    // Programme routes with parameters (at the end)
    Route::get('/{date}', [PMUController::class, 'getProgramme']);
    Route::get('/{date}/R{reunionNum}', [PMUController::class, 'getReunion']);
    Route::get('/{date}/R{reunionNum}/C{courseNum}/participants', [PMUController::class, 'getParticipants']);
});

// Legacy routes (backward compatibility) - redirect to v1
Route::prefix('pmu')->middleware('throttle:60,1')->group(function () {
    Route::get('/health', [PMUController::class, 'health']);
    Route::get('/races', [PMUController::class, 'getRacesByDate']);
    Route::get('/races/{raceId}/predictions', [PMUController::class, 'getRacePredictions']);
    Route::get('/find-race', [PMUController::class, 'findRaceByCode']);

    Route::prefix('horses')->group(function () {
        Route::get('/search', [PMUController::class, 'searchHorses'])
            ->middleware('throttle:30,1');
        Route::get('/{horseId}', [PMUController::class, 'getHorseDetails']);
        Route::get('/{horseId}/stallion-stats', [PMUController::class, 'getStallionStats']);
    });

    Route::get('/{date}', [PMUController::class, 'getProgramme']);
    Route::get('/{date}/R{reunionNum}', [PMUController::class, 'getReunion']);
    Route::get('/{date}/R{reunionNum}/C{courseNum}/participants', [PMUController::class, 'getParticipants']);
});