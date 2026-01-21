<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PredictionMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    private PredictionMonitoringService $monitoring;

    public function __construct(PredictionMonitoringService $monitoring)
    {
        $this->monitoring = $monitoring;
    }

    /**
     * Get monitoring dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $days = $request->query('days', 30);
        $data = $this->monitoring->getDashboardData($days);

        return response()->json($data);
    }

    /**
     * Record predictions for a race
     */
    public function recordPredictions(int $raceId): JsonResponse
    {
        $result = $this->monitoring->recordPredictions($raceId);

        if (!$result) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        return response()->json([
            'message' => 'Predictions recorded',
            'prediction_id' => $result->id
        ]);
    }

    /**
     * Update predictions with actual results
     */
    public function updateResults(int $raceId): JsonResponse
    {
        $result = $this->monitoring->updateWithResults($raceId);

        if (!$result) {
            return response()->json(['error' => 'Prediction not found or no results available'], 404);
        }

        return response()->json([
            'message' => 'Results updated',
            'accuracy_score' => $result->accuracy_score,
            'top_3_accuracy' => $result->top_3_accuracy,
            'winner_predicted_at' => $result->winner_rank_predicted
        ]);
    }
}