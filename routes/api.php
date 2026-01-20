<?php

use App\Http\Controllers\Api\PMUController;
use Illuminate\Support\Facades\Route;

// Routes PMU avec préfixe /api/pmu et rate limiting
Route::prefix('pmu')->middleware('throttle:60,1')->group(function () {

    // METTRE LES ROUTES SPÉCIFIQUES EN PREMIER
    Route::get('/races', [PMUController::class, 'getRacesByDate']);
    Route::get('/races/{raceId}/predictions', [PMUController::class, 'getRacePredictions']);
    Route::get('/find-race', [PMUController::class, 'findRaceByCode']);

    Route::prefix('horses')->group(function () {
        // More restrictive rate limit for search
        Route::get('/search', [PMUController::class, 'searchHorses'])
            ->middleware('throttle:30,1');
        Route::get('/{horseId}', [PMUController::class, 'getHorseDetails']);
        Route::get('/{horseId}/stallion-stats', [PMUController::class, 'getStallionStats']);
    });

    // ROUTES AVEC PARAMÈTRES À LA FIN
    Route::get('/{date}', [PMUController::class, 'getProgramme']);
    Route::get('/{date}/R{reunionNum}', [PMUController::class, 'getReunion']);
    Route::get('/{date}/R{reunionNum}/C{courseNum}/participants', [PMUController::class, 'getParticipants']);
});