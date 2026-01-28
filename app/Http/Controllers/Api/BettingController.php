<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BettingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BettingController extends Controller
{
    protected $bettingService;

    public function __construct(BettingService $bettingService)
    {
        $this->bettingService = $bettingService;
    }

    /**
     * Process daily predictions
     *
     * Expected input format:
     * {
     *   "date": "2026-01-28",
     *   "predictions": [
     *     {
     *       "race_id": 1,                    // INTEGER (required)
     *       "horse_id": "HORSE-ID-STRING",   // STRING (required)
     *       "horse_name": "FURIOSO",         // STRING (required)
     *       "probability": 0.174,            // FLOAT 0-1 (required)
     *       "odds": 9.9,                     // FLOAT >1 (nullable)
     *       "metadata": {                    // OBJECT (nullable)
     *         "jockey": "L.BRECHET",
     *         "hippodrome": "VINCENNES",
     *         "discipline": "TROT",
     *         "in_top_group": true
     *       }
     *     }
     *   ]
     * }
     *
     * FIXED: Added comprehensive error handling and logging
     */
    public function processPredictions(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'date' => 'required|date',
                'predictions' => 'required|array|min:1',
                'predictions.*.race_id' => 'required|integer',
                'predictions.*.horse_id' => 'required|string',
                'predictions.*.horse_name' => 'required|string',
                'predictions.*.probability' => 'required|numeric|min:0|max:1',
                'predictions.*.odds' => 'nullable|numeric|gt:0'
            ]);

            Log::info('Processing predictions', [
                'date' => $validated['date'],
                'count' => count($validated['predictions'])
            ]);

            // Process predictions
            $stats = $this->bettingService->processDailyPredictions(
                $validated['date'],
                $validated['predictions']
            );

            // Check if there were errors
            if (isset($stats['errors']) && !empty($stats['errors'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Predictions processed with errors',
                    'data' => $stats,
                    'errors' => $stats['errors']
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Predictions processed successfully',
                'data' => $stats
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed for predictions', [
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to process predictions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_date' => $request->date ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing predictions',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get daily bets
     */
    public function getDailyBets(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $bets = \App\Models\DailyBet::where('bet_date', $date)
                ->with(['race', 'horse'])
                ->orderByDesc('probability')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $bets
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get daily bets', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve daily bets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get value bets
     */
    public function getValueBets(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $bets = \App\Models\ValueBet::where('bet_date', $date)
                ->with(['race', 'horse'])
                ->orderBy('ranking')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $bets
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get value bets', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve value bets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get combinations
     */
    public function getCombinations(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $combinations = \App\Models\BetCombination::where('bet_date', $date)
                ->with('race')
                ->orderByDesc('combined_probability')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $combinations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get combinations', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve combinations',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate daily report
     */
    public function generateReport(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::yesterday()->format('Y-m-d'));

            $report = $this->bettingService->generateDailyReport($date);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate report', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Fetch previous day results
     */
    public function fetchResults(): JsonResponse
    {
        try {
            $results = $this->bettingService->fetchPreviousDayResults();

            return response()->json([
                'success' => true,
                'message' => 'Results fetched successfully',
                'data' => [
                    'count' => count($results),
                    'results' => $results
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch results', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get race results for date
     */
    public function getRaceResults(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::yesterday()->format('Y-m-d'));

            $results = \App\Models\RaceResult::getResultsForDate($date);

            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get race results', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve race results',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete all bets for a date
     */
    public function deleteBets(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $dailyDeleted = \App\Models\DailyBet::where('bet_date', $date)->delete();
            $valueDeleted = \App\Models\ValueBet::where('bet_date', $date)->delete();
            $comboDeleted = \App\Models\BetCombination::where('bet_date', $date)->delete();

            Log::info('Deleted bets', [
                'date' => $date,
                'daily' => $dailyDeleted,
                'value' => $valueDeleted,
                'combos' => $comboDeleted
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bets deleted successfully',
                'data' => [
                    'daily_bets_deleted' => $dailyDeleted,
                    'value_bets_deleted' => $valueDeleted,
                    'combinations_deleted' => $comboDeleted
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete bets', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting bets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Add manual Kelly bet
     */
    public function addKellyBet(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'bet_date' => 'required|date',
                'race_id' => 'required|integer',
                'horse_id' => 'required|string',
                'horse_name' => 'required|string',
                'probability' => 'required|numeric|min:0|max:1',
                'odds' => 'required|numeric|min:1',
                'bankroll' => 'required|numeric|min:0',
                'bet_amount' => 'required|numeric|min:0'
            ]);

            $kellyBet = \App\Models\KellyBet::addManualBet($validated);

            return response()->json([
                'success' => true,
                'message' => 'Kelly bet added successfully',
                'data' => $kellyBet
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add Kelly bet', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error adding Kelly bet',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get Kelly bets for date
     */
    public function getKellyBets(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $kellyBets = \App\Models\KellyBet::getKellyBets($date);

            return response()->json([
                'success' => true,
                'data' => $kellyBets
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Kelly bets', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve Kelly bets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Add manual bet
     */
    public function addManualBet(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'bet_date' => 'required|date',
                'horse_id' => 'required|string',
                'horse_name' => 'required|string',
                'amount' => 'required|numeric|min:0',
                'bet_type' => 'required|in:SIMPLE,COUPLE_PLACE'
            ]);

            $manualBet = \App\Models\ManualBet::addBet($validated);

            return response()->json([
                'success' => true,
                'message' => 'Manual bet added successfully',
                'data' => $manualBet
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add manual bet', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error adding manual bet',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get manual bets for date
     */
    public function getManualBets(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $manualBets = \App\Models\ManualBet::getManualBets($date);

            return response()->json([
                'success' => true,
                'data' => $manualBets
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manual bets', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve manual bets',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete manual bet
     */
    public function deleteManualBet(int $id): JsonResponse
    {
        try {
            $deleted = \App\Models\ManualBet::deleteBet($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bet not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Manual bet deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete manual bet', [
                'error' => $e->getMessage(),
                'bet_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting manual bet',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Add manual combination (COUPLE or TRIO)
     */
    public function addManualCombination(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'bet_date' => 'required|date',
                'reunion_number' => 'required|integer|min:1|max:20',
                'course_number' => 'required|integer|min:1|max:20',
                'combination_type' => 'required|in:COUPLE,TRIO',
                'horses' => 'required|array|min:2|max:3',
                'horses.*.horse_id' => 'required|string',
                'horses.*.horse_name' => 'required|string',
                'amount' => 'required|numeric|min:1|max:1000'
            ]);

            // Try to find race_id
            $race = \App\Models\Race::where('race_date', $validated['bet_date'])
                ->where('reunion_number', $validated['reunion_number'])
                ->where('course_number', $validated['course_number'])
                ->first();

            $validated['race_id'] = $race ? $race->id : null;

            $combination = \App\Models\ManualCombination::addCombination($validated);

            return response()->json([
                'success' => true,
                'message' => 'Combination added successfully',
                'data' => $combination
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to add manual combination', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error adding combination: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get manual combinations for date
     */
    public function getManualCombinations(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));
            $type = $request->get('type'); // Optional filter by COUPLE or TRIO

            if ($type) {
                $combinations = \App\Models\ManualCombination::getByType($date, $type);
            } else {
                $combinations = \App\Models\ManualCombination::getManualCombinations($date);
            }

            return response()->json([
                'success' => true,
                'data' => $combinations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manual combinations', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve manual combinations',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Delete manual combination
     */
    public function deleteManualCombination(int $id): JsonResponse
    {
        try {
            $deleted = \App\Models\ManualCombination::deleteCombination($id);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Combination not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Manual combination deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete manual combination', [
                'error' => $e->getMessage(),
                'combination_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error deleting manual combination',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get summary of all manual bets and combinations for a date
     */
    public function getManualBetsSummary(Request $request): JsonResponse
    {
        try {
            $date = $request->get('date', Carbon::today()->format('Y-m-d'));

            $manualBets = \App\Models\ManualBet::getManualBets($date);
            $manualCombinations = \App\Models\ManualCombination::getManualCombinations($date);

            $summary = [
                'date' => $date,
                'manual_bets' => [
                    'count' => $manualBets->count(),
                    'total_amount' => $manualBets->sum('amount'),
                    'bets' => $manualBets
                ],
                'manual_combinations' => [
                    'count' => $manualCombinations->count(),
                    'couples' => $manualCombinations->where('combination_type', 'COUPLE')->count(),
                    'trios' => $manualCombinations->where('combination_type', 'TRIO')->count(),
                    'total_amount' => $manualCombinations->sum('amount'),
                    'combinations' => $manualCombinations
                ],
                'total_amount' => $manualBets->sum('amount') + $manualCombinations->sum('amount')
            ];

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get manual bets summary', [
                'error' => $e->getMessage(),
                'date' => $request->get('date')
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve manual bets summary',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
 * Delete individual daily bet
 */
public function deleteDailyBet(int $id): JsonResponse
{
    try {
        $bet = \App\Models\DailyBet::find($id);

        if (!$bet) {
            return response()->json([
                'success' => false,
                'message' => 'Paris quotidien non trouvé'
            ], 404);
        }

        $bet->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pari quotidien supprimé'
        ]);

    } catch (\Exception $e) {
        // \Log::error('Failed to delete daily bet', [
        //     'error' => $e->getMessage(),
        //     'bet_id' => $id
        // ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la suppression'
        ], 500);
    }
}

/**
 * Delete individual value bet
 */
public function deleteValueBet(int $id): JsonResponse
{
    try {
        $bet = \App\Models\ValueBet::find($id);

        if (!$bet) {
            return response()->json([
                'success' => false,
                'message' => 'Value bet non trouvé'
            ], 404);
        }

        $bet->delete();

        return response()->json([
            'success' => true,
            'message' => 'Value bet supprimé'
        ]);

    } catch (\Exception $e) {
        // \Log::error('Failed to delete value bet', [
        //     'error' => $e->getMessage(),
        //     'bet_id' => $id
        // ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la suppression'
        ], 500);
    }
}

/**
 * Delete individual combination
 */
public function deleteCombination(int $id): JsonResponse
{
    try {
        $combo = \App\Models\BetCombination::find($id);

        if (!$combo) {
            return response()->json([
                'success' => false,
                'message' => 'Combinaison non trouvée'
            ], 404);
        }

        $combo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Combinaison supprimée'
        ]);

    } catch (\Exception $e) {
        // \Log::error('Failed to delete combination', [
        //     'error' => $e->getMessage(),
        //     'combo_id' => $id
        // ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la suppression'
        ], 500);
    }
}

}


