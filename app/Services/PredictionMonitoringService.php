<?php

namespace App\Services;

use App\Models\Race;
use App\Models\PredictionResult;
use Illuminate\Support\Facades\Log;

class PredictionMonitoringService
{
    private PMUStatisticsService $stats;

    public function __construct(PMUStatisticsService $stats)
    {
        $this->stats = $stats;
    }

    /**
     * Record predictions for a race (before race runs)
     */
    public function recordPredictions(int $raceId): ?PredictionResult
    {
        $race = Race::find($raceId);
        if (!$race) {
            return null;
        }

        $startTime = microtime(true);
        $predictions = $this->stats->getRacePredictions($raceId);
        $executionTime = (microtime(true) - $startTime) * 1000;

        $predictionData = $predictions->map(function($pred) {
            return [
                'horse_id' => $pred['horse_id'],
                'horse_name' => $pred['horse_name'],
                'probability' => $pred['probability'],
                'rank' => $pred['rank']
            ];
        })->toArray();

        $scenario = $predictions->first()['race_scenario'] ?? null;

        return PredictionResult::create([
            'race_id' => $raceId,
            'predictions' => $predictionData,
            'actual_results' => null,
            'accuracy_score' => null,
            'top_3_accuracy' => null,
            'winner_rank_predicted' => null,
            'scenario_detected' => $scenario['scenario'] ?? null,
            'execution_time_ms' => $executionTime
        ]);
    }

    /**
     * Update prediction result with actual race results
     */
    public function updateWithResults(int $raceId): ?PredictionResult
    {
        $predictionResult = PredictionResult::where('race_id', $raceId)
            ->whereNull('actual_results')
            ->first();

        if (!$predictionResult) {
            Log::warning("No prediction found for race", ['race_id' => $raceId]);
            return null;
        }

        $race = Race::with('performances')->find($raceId);
        if (!$race) {
            return null;
        }

        // Get actual results
        $actualResults = $race->performances()
            ->whereNotNull('rank')
            ->orderBy('rank')
            ->get()
            ->map(function($perf) {
                return [
                    'horse_id' => $perf->horse_id,
                    'rank' => $perf->rank
                ];
            })
            ->toArray();

        if (empty($actualResults)) {
            Log::info("No results yet for race", ['race_id' => $raceId]);
            return null;
        }

        // Calculate accuracy metrics
        $metrics = $this->calculateAccuracyMetrics(
            $predictionResult->predictions,
            $actualResults
        );

        $predictionResult->update([
            'actual_results' => $actualResults,
            'accuracy_score' => $metrics['accuracy_score'],
            'top_3_accuracy' => $metrics['top_3_accuracy'],
            'winner_rank_predicted' => $metrics['winner_rank_predicted']
        ]);

        Log::info('Prediction result updated', [
            'race_id' => $raceId,
            'accuracy_score' => $metrics['accuracy_score'],
            'winner_predicted_at' => $metrics['winner_rank_predicted']
        ]);

        return $predictionResult;
    }

    /**
     * Calculate accuracy metrics
     */
    private function calculateAccuracyMetrics(array $predictions, array $actualResults): array
    {
        $predTop3 = collect($predictions)->take(3)->pluck('horse_id')->toArray();
        $actualTop3 = collect($actualResults)->take(3)->pluck('horse_id')->toArray();
        $actualWinner = $actualResults[0]['horse_id'] ?? null;

        // How many of our top 3 predictions are in actual top 3?
        $correctTop3 = count(array_intersect($predTop3, $actualTop3));
        $top3Accuracy = ($correctTop3 / 3) * 100;

        // Where did we predict the actual winner?
        $winnerRankPredicted = null;
        foreach ($predictions as $index => $pred) {
            if ($pred['horse_id'] === $actualWinner) {
                $winnerRankPredicted = $index + 1;
                break;
            }
        }

        // Overall accuracy score
        $accuracyScore = 0;
        if ($winnerRankPredicted === 1) {
            $accuracyScore += 50;
        } elseif ($winnerRankPredicted === 2) {
            $accuracyScore += 30;
        } elseif ($winnerRankPredicted === 3) {
            $accuracyScore += 20;
        } elseif ($winnerRankPredicted !== null && $winnerRankPredicted <= 5) {
            $accuracyScore += 10;
        }

        $accuracyScore += ($top3Accuracy / 100) * 50;

        return [
            'accuracy_score' => $accuracyScore,
            'top_3_accuracy' => $top3Accuracy,
            'winner_rank_predicted' => $winnerRankPredicted
        ];
    }

    /**
     * Get monitoring dashboard data
     */
    public function getDashboardData(int $days = 30): array
    {
        $recentAccuracy = PredictionResult::getRecentAccuracy($days);

        // Get accuracy by scenario
        $byScenario = PredictionResult::where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('accuracy_score')
            ->get()
            ->groupBy('scenario_detected')
            ->map(function($group) {
                return [
                    'count' => $group->count(),
                    'avg_accuracy' => $group->avg('accuracy_score'),
                    'avg_top3_accuracy' => $group->avg('top_3_accuracy')
                ];
            });

        // Get accuracy trend over time
        $trend = PredictionResult::where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('accuracy_score')
            ->selectRaw('DATE(created_at) as date, AVG(accuracy_score) as avg_accuracy')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'overall' => $recentAccuracy,
            'by_scenario' => $byScenario,
            'trend' => $trend,
            'last_updated' => now()->toIso8601String()
        ];
    }
}