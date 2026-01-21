<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionResult extends Model
{
    protected $fillable = [
        'race_id',
        'predictions',
        'actual_results',
        'accuracy_score',
        'top_3_accuracy',
        'winner_rank_predicted',
        'scenario_detected',
        'execution_time_ms'
    ];

    protected $casts = [
        'predictions' => 'array',
        'actual_results' => 'array',
        'accuracy_score' => 'float',
        'top_3_accuracy' => 'float',
        'execution_time_ms' => 'float'
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * Calculate accuracy metrics after race completion
     */
    public static function createFromRace(Race $race, array $predictions): ?self
    {
        $actualResults = $race->performances()
            ->whereNotNull('rank')
            ->orderBy('rank')
            ->get(['horse_id', 'rank', 'horse_name' => 'horses.name'])
            ->map(function($perf) {
                return [
                    'horse_id' => $perf->horse_id,
                    'rank' => $perf->rank
                ];
            })
            ->toArray();

        if (empty($actualResults)) {
            return null;
        }

        // Calculate accuracy metrics
        $metrics = self::calculateAccuracyMetrics($predictions, $actualResults);

        return self::create([
            'race_id' => $race->id,
            'predictions' => $predictions,
            'actual_results' => $actualResults,
            'accuracy_score' => $metrics['accuracy_score'],
            'top_3_accuracy' => $metrics['top_3_accuracy'],
            'winner_rank_predicted' => $metrics['winner_rank_predicted'],
            'scenario_detected' => $predictions[0]['race_scenario']['scenario'] ?? null,
            'execution_time_ms' => 0
        ]);
    }

    /**
     * Calculate accuracy metrics
     */
    private static function calculateAccuracyMetrics(array $predictions, array $actualResults): array
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

        // Overall accuracy score (weighted: winner position most important)
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
     * Get average accuracy for recent predictions
     */
    public static function getRecentAccuracy(int $days = 7): array
    {
        $results = self::where('created_at', '>=', now()->subDays($days))
            ->get();

        if ($results->isEmpty()) {
            return [
                'avg_accuracy' => 0,
                'avg_top3_accuracy' => 0,
                'winner_predicted_rank_1' => 0,
                'winner_predicted_top_3' => 0,
                'total_races' => 0
            ];
        }

        $winnerRank1 = $results->where('winner_rank_predicted', 1)->count();
        $winnerTop3 = $results->whereIn('winner_rank_predicted', [1, 2, 3])->count();

        return [
            'avg_accuracy' => $results->avg('accuracy_score'),
            'avg_top3_accuracy' => $results->avg('top_3_accuracy'),
            'winner_predicted_rank_1' => ($winnerRank1 / $results->count()) * 100,
            'winner_predicted_top_3' => ($winnerTop3 / $results->count()) * 100,
            'total_races' => $results->count(),
            'period_days' => $days
        ];
    }
}