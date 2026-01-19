<?php

use App\Http\Controllers\Api\PMUController;
use Illuminate\Support\Facades\Route;

Route::prefix('pmu')->group(function () {
    // Programme and race data (proxy to PMU API)
    Route::get('/{date}', [PMUController::class, 'getProgramme']);
    Route::get('/{date}/R{reunion}', [PMUController::class, 'getReunion']);
    Route::get('/{date}/R{reunion}/C{course}/participants', [PMUController::class, 'getParticipants']);

    // Database-backed endpoints with statistics
    Route::prefix('races')->group(function () {
        Route::get('/', [PMUController::class, 'getRacesByDate']);
        Route::get('/{raceId}/predictions', [PMUController::class, 'getRacePredictions']);
    });

    Route::prefix('horses')->group(function () {
        Route::get('/search', [PMUController::class, 'searchHorses']);
        Route::get('/{horseId}', [PMUController::class, 'getHorseDetails']);
        Route::get('/{horseId}/stallion-stats', [PMUController::class, 'getStallionStats']);
    });
});