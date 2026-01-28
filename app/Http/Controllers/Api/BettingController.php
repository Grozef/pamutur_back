<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BettingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
     */
    public function processPredictions(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'predictions' => 'required|array',
            'predictions.*.race_id' => 'required|integer',
            'predictions.*.horse_id' => 'required|string',
            'predictions.*.horse_name' => 'required|string',
            'predictions.*.probability' => 'required|numeric|min:0|max:1',
            'predictions.*.odds' => 'nullable|numeric'
        ]);

        $stats = $this->bettingService->processDailyPredictions(
            $request->date,
            $request->predictions
        );

        return response()->json([
            'success' => true,
            'message' => 'Predictions processed successfully',
            'data' => $stats
        ]);
    }

    /**
     * Get daily bets
     */
    public function getDailyBets(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        $bets = \App\Models\DailyBet::where('bet_date', $date)
            ->with(['race', 'horse'])
            ->orderByDesc('probability')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bets
        ]);
    }

    /**
     * Get value bets
     */
    public function getValueBets(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        $bets = \App\Models\ValueBet::where('bet_date', $date)
            ->with(['race', 'horse'])
            ->orderBy('ranking')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bets
        ]);
    }

    /**
     * Get combinations
     */
    public function getCombinations(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        $combinations = \App\Models\BetCombination::where('bet_date', $date)
            ->with('race')
            ->orderByDesc('combined_probability')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $combinations
        ]);
    }

    /**
     * Generate daily report
     */
    public function generateReport(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::yesterday()->format('Y-m-d'));

        $report = $this->bettingService->generateDailyReport($date);

        return response()->json([
            'success' => true,
            'data' => $report
        ]);
    }

    /**
     * Fetch previous day results
     */
    public function fetchResults(): JsonResponse
    {
        $results = $this->bettingService->fetchPreviousDayResults();

        return response()->json([
            'success' => true,
            'message' => 'Results fetched successfully',
            'data' => [
                'count' => count($results),
                'results' => $results
            ]
        ]);
    }

    /**
     * Get race results for date
     */
    public function getRaceResults(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::yesterday()->format('Y-m-d'));

        $results = \App\Models\RaceResult::getResultsForDate($date);

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }

    /**
     * Delete all bets for a date
     */
    public function deleteBets(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        try {
            $dailyDeleted = \App\Models\DailyBet::where('bet_date', $date)->delete();
            $valueDeleted = \App\Models\ValueBet::where('bet_date', $date)->delete();
            $comboDeleted = \App\Models\BetCombination::where('bet_date', $date)->delete();

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
            return response()->json([
                'success' => false,
                'message' => 'Error deleting bets: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add manual Kelly bet
     */
    public function addKellyBet(Request $request): JsonResponse
    {
        $request->validate([
            'bet_date' => 'required|date',
            'race_id' => 'required|integer',
            'horse_id' => 'required|string',
            'horse_name' => 'required|string',
            'probability' => 'required|numeric|min:0|max:1',
            'odds' => 'required|numeric|min:1',
            'bankroll' => 'required|numeric|min:0',
            'bet_amount' => 'required|numeric|min:0'
        ]);

        try {
            $kellyBet = \App\Models\KellyBet::addManualBet($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Kelly bet added successfully',
                'data' => $kellyBet
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding Kelly bet: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Kelly bets for date
     */
    public function getKellyBets(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        $kellyBets = \App\Models\KellyBet::getKellyBets($date);

        return response()->json([
            'success' => true,
            'data' => $kellyBets
        ]);
    }

    /**
     * Add manual bet
     */
    public function addManualBet(Request $request): JsonResponse
    {
        $request->validate([
            'bet_date' => 'required|date',
            'horse_id' => 'required|string',
            'horse_name' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'bet_type' => 'required|in:SIMPLE,COUPLE_PLACE'
        ]);

        try {
            $manualBet = \App\Models\ManualBet::addBet($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Manual bet added successfully',
                'data' => $manualBet
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding manual bet: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get manual bets for date
     */
    public function getManualBets(Request $request): JsonResponse
    {
        $date = $request->get('date', Carbon::today()->format('Y-m-d'));

        $manualBets = \App\Models\ManualBet::getManualBets($date);

        return response()->json([
            'success' => true,
            'data' => $manualBets
        ]);
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
            return response()->json([
                'success' => false,
                'message' => 'Error deleting manual bet: ' . $e->getMessage()
            ], 500);
        }
    }
}