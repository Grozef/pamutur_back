<?php

use App\Http\Controllers\Api\PMUController;
use Illuminate\Support\Facades\Route;

// Routes PMU avec préfixe /api/pmu
Route::prefix('pmu')->group(function () {

    // METTRE LES ROUTES SPÉCIFIQUES EN PREMIER
    Route::get('/races', [PMUController::class, 'getRacesByDate']);
    Route::get('/races/{raceId}/predictions', [PMUController::class, 'getRacePredictions']);
    Route::get('/find-race', [PMUController::class, 'findRaceByCode']);

    Route::prefix('horses')->group(function () {
        Route::get('/search', [PMUController::class, 'searchHorses']);
        Route::get('/{horseId}', [PMUController::class, 'getHorseDetails']);
        Route::get('/{horseId}/stallion-stats', [PMUController::class, 'getStallionStats']);
    });

    // ROUTES AVEC PARAMÈTRES À LA FIN
    Route::get('/{date}', [PMUController::class, 'getProgramme']);
    Route::get('/{date}/R{reunion}', [PMUController::class, 'getReunion']);
    Route::get('/{date}/R{reunion}/C{course}/participants', [PMUController::class, 'getParticipants']);
});