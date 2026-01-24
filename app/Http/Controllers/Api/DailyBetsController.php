<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Race;
use App\Services\PMUStatisticsService;
use App\Services\ValueBetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DailyBetsController extends Controller
{
    private PMUStatisticsService $stats;
    private ValueBetService $valueBets;

    public function __construct(
        PMUStatisticsService $stats,
        ValueBetService $valueBets
    ) {
        $this->stats = $stats;
        $this->valueBets = $valueBets;
    }

    /**
     * Get top 5 bets of the day
     */
    public function getTopDailyBets(Request $request): JsonResponse
    {
        $date = $request->query('date', date('Y-m-d'));
        
        // FIX: Validate bankroll like in PMUController
        $bankroll = (float) $request->query('bankroll', 1000);
        if ($bankroll < 10 || $bankroll > 1000000) {
            return response()->json([
                'error' => 'Bankroll must be between 10 and 1,000,000'
            ], 400);
        }
        
        $limit = min(max((int) $request->query('limit', 5), 1), 20);

        $cacheKey = "top_daily_bets_{$date}_{$bankroll}_{$limit}";

        $result = Cache::remember($cacheKey, 1800, function() use ($date, $bankroll, $limit) {
            return $this->calculateTopBets($date, $bankroll, $limit);
        });

        return response()->json($result);
    }

    /**
     * Calculate top bets for a given date
     */
    private function calculateTopBets(string $date, float $bankroll, int $limit): array
    {
        // Get all races for the date
        $races = Race::whereDate('race_date', $date)
            ->with('performances')
            ->orderBy('race_date')
            ->get();

        if ($races->isEmpty()) {
            return [
                'date' => $date,
                'races_count' => 0,
                'top_bets' => [],
                'message' => 'No races found for this date'
            ];
        }

        $allValueBets = [];

        // Calculate value bets for each race
        foreach ($races as $race) {
            try {
                $predictions = $this->stats->getRacePredictions($race->id);

                if ($predictions->isEmpty()) {
                    continue;
                }

                foreach ($predictions as $prediction) {
                    $kelly = $this->valueBets->calculateKellyBet(
                        $prediction['probability'],
                        $prediction['odds_ref'] ?? null,
                        $bankroll
                    );

                    if ($kelly['is_value']) {
                        $allValueBets[] = [
                            'race_id' => $race->id,
                            'race_code' => $race->race_code,
                            'race_time' => $race->race_date->format('H:i'),
                            'hippodrome' => $race->hippodrome,
                            'discipline' => $race->discipline,
                            'distance' => $race->distance,
                            'horse_id' => $prediction['horse_id'],
                            'horse_name' => $prediction['horse_name'],
                            'jockey_name' => $prediction['jockey_name'] ?? null,
                            'draw' => $prediction['draw'] ?? null,
                            'probability' => $prediction['probability'],
                            'odds' => $prediction['odds_ref'],
                            'kelly_data' => $kelly,
                            'value_bet' => $prediction['value_bet'] ?? false,
                            'in_top_group' => $prediction['in_top_group'] ?? false,
                            'scenario' => $prediction['race_scenario'] ?? null
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Failed to calculate predictions for race {$race->id}", [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Sort by expected value (descending)
        usort($allValueBets, function($a, $b) {
            return $b['kelly_data']['expected_value'] <=> $a['kelly_data']['expected_value'];
        });

        // Get top N bets
        $topBets = array_slice($allValueBets, 0, $limit);

        // Calculate total investment and expected return
        $totalStake = array_sum(array_column(array_column($topBets, 'kelly_data'), 'recommended_stake'));
        $totalEV = array_sum(array_column(array_column($topBets, 'kelly_data'), 'expected_value'));

        return [
            'date' => $date,
            'races_count' => $races->count(),
            'total_value_bets' => count($allValueBets),
            'top_bets' => $topBets,
            'summary' => [
                'total_stake' => round($totalStake, 2),
                'total_expected_value' => round($totalEV, 2),
                'average_ev' => count($topBets) > 0 ? round($totalEV / count($topBets), 2) : 0,
                'bankroll_usage' => round(($totalStake / $bankroll) * 100, 2),
                'estimated_roi' => $totalStake > 0 ? round(($totalEV / $totalStake) * 100, 2) : 0
            ]
        ];
    }

    /**
     * Get best combinations of the day (Tierce/Quinte)
     */
    public function getTopDailyCombinations(Request $request): JsonResponse
    {
        $date = $request->query('date', date('Y-m-d'));
        $type = $request->query('type', 'tierce');
        $limit = min(max((int) $request->query('limit', 3), 1), 20);

        // Validate type
        if (!in_array($type, ['tierce', 'quinte'])) {
            return response()->json([
                'error' => 'Type must be "tierce" or "quinte"'
            ], 400);
        }

        $cacheKey = "top_daily_combos_{$date}_{$type}_{$limit}";

        $result = Cache::remember($cacheKey, 1800, function() use ($date, $type, $limit) {
            return $this->calculateTopCombinations($date, $type, $limit);
        });

        return response()->json($result);
    }

    /**
     * Calculate top combinations for a given date
     */
    private function calculateTopCombinations(string $date, string $type, int $limit): array
    {
        $races = Race::whereDate('race_date', $date)
            ->with('performances')
            ->orderBy('race_date')
            ->get();

        if ($races->isEmpty()) {
            return [
                'date' => $date,
                'type' => $type,
                'combinations' => [],
                'message' => 'No races found for this date'
            ];
        }

        $allCombinations = [];

        foreach ($races as $race) {
            try {
                $predictions = $this->stats->getRacePredictions($race->id);

                if ($predictions->isEmpty() || $predictions->count() < 5) {
                    continue;
                }

                // Get best combination for this race
                if ($type === 'quinte' && $predictions->count() >= 5) {
                    $combo = $this->getBestQuinteForRace($race, $predictions);
                } else {
                    $combo = $this->getBestTierceForRace($race, $predictions);
                }

                if ($combo) {
                    $allCombinations[] = $combo;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to calculate combinations for race {$race->id}", [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        // Sort by probability (descending)
        usort($allCombinations, function($a, $b) {
            return $b['probability'] <=> $a['probability'];
        });

        return [
            'date' => $date,
            'type' => $type,
            'races_analyzed' => $races->count(),
            'combinations' => array_slice($allCombinations, 0, $limit)
        ];
    }

    /**
     * FIX: Calculate tierce probability using conditional probabilities
     * P(A, B, C in top 3 in any order) = sum of all 6 permutations
     */
    private function getBestTierceForRace($race, $predictions): ?array
    {
        $top3 = $predictions->take(3);

        if ($top3->count() < 3) {
            return null;
        }

        $horses = $top3->values()->toArray();
        $totalProb = $predictions->sum('probability');
        
        // Calculate probability using conditional probabilities for all 6 permutations
        $prob = $this->calculateTrioPermutationProbability(
            $horses[0]['probability'],
            $horses[1]['probability'],
            $horses[2]['probability'],
            $totalProb
        );

        return [
            'race_id' => $race->id,
            'race_code' => $race->race_code,
            'race_time' => $race->race_date->format('H:i'),
            'hippodrome' => $race->hippodrome,
            'type' => 'TIERCE_DESORDRE',
            'horses' => [
                $horses[0]['horse_name'],
                $horses[1]['horse_name'],
                $horses[2]['horse_name']
            ],
            'probability' => min(100, $prob * 100),
            'estimated_payout' => 50,
            'recommended_stake' => 2
        ];
    }

    /**
     * FIX: Calculate quinte probability using conditional probabilities
     */
    private function getBestQuinteForRace($race, $predictions): ?array
    {
        $top5 = $predictions->take(5);

        if ($top5->count() < 5) {
            return null;
        }

        $horses = $top5->values()->toArray();
        $totalProb = $predictions->sum('probability');
        
        // Calculate probability using conditional probabilities
        $prob = $this->calculateQuintePermutationProbability(
            $horses[0]['probability'],
            $horses[1]['probability'],
            $horses[2]['probability'],
            $horses[3]['probability'],
            $horses[4]['probability'],
            $totalProb
        );

        return [
            'race_id' => $race->id,
            'race_code' => $race->race_code,
            'race_time' => $race->race_date->format('H:i'),
            'hippodrome' => $race->hippodrome,
            'type' => 'QUINTE_DESORDRE',
            'horses' => [
                $horses[0]['horse_name'],
                $horses[1]['horse_name'],
                $horses[2]['horse_name'],
                $horses[3]['horse_name'],
                $horses[4]['horse_name']
            ],
            'probability' => min(100, $prob * 100),
            'estimated_payout' => 500,
            'recommended_stake' => 2
        ];
    }

    /**
     * Calculate probability of 3 horses finishing in top 3 in any order
     * Sum of conditional probabilities for all 6 permutations
     */
    private function calculateTrioPermutationProbability(
        float $pA,
        float $pB,
        float $pC,
        float $total
    ): float {
        if ($total <= 0) return 0;
        
        $prob = 0;
        $horses = [$pA, $pB, $pC];

        // Generate all 6 permutations
        $permutations = [
            [0, 1, 2], [0, 2, 1], [1, 0, 2],
            [1, 2, 0], [2, 0, 1], [2, 1, 0]
        ];

        foreach ($permutations as $perm) {
            $p1 = $horses[$perm[0]] / $total;
            $remaining1 = $total - $horses[$perm[0]];

            $p2 = $remaining1 > 0 ? $horses[$perm[1]] / $remaining1 : 0;
            $remaining2 = $remaining1 - $horses[$perm[1]];

            $p3 = $remaining2 > 0 ? $horses[$perm[2]] / $remaining2 : 0;

            $prob += $p1 * $p2 * $p3;
        }

        return $prob;
    }

    /**
     * Calculate probability of 5 horses in top 5 (any order)
     * Uses analytical approximation for efficiency
     */
    private function calculateQuintePermutationProbability(
        float $p1, float $p2, float $p3, float $p4, float $p5,
        float $total
    ): float {
        if ($total <= 0) return 0;
        
        $horses = [$p1, $p2, $p3, $p4, $p5];

        // Calculate probability that all 5 horses finish in top 5
        $probAllInTop5 = 1.0;
        $remaining = $total;

        foreach ($horses as $prob) {
            if ($remaining <= 0) {
                $probAllInTop5 = 0;
                break;
            }
            $positionProb = $prob / $remaining;
            $probAllInTop5 *= $positionProb;
            $remaining -= $prob;
        }

        // Multiply by 5! = 120 for all orderings
        return $probAllInTop5 * 120;
    }
}
