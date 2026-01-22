<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PMUStatisticsService;
use App\Services\ValueBetService;
use App\Services\CombinationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedPMUController extends Controller
{
    private PMUStatisticsService $stats;
    private ValueBetService $valueBets;
    private CombinationService $combinations;

    public function __construct(
        PMUStatisticsService $stats,
        ValueBetService $valueBets,
        CombinationService $combinations
    ) {
        $this->stats = $stats;
        $this->valueBets = $valueBets;
        $this->combinations = $combinations;
    }

    /**
     * GET /api/v1/pmu/races/{raceId}/value-bets
     * Obtenir les value bets optimisés avec Kelly Criterion
     */
    public function getValueBets(int $raceId, Request $request): JsonResponse
    {
        $bankroll = $request->query('bankroll', 1000);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        $analysis = $this->valueBets->analyzeRaceValueBets($predictions, $bankroll);

        return response()->json([
            'race_id' => $raceId,
            'bankroll' => $bankroll,
            'value_bets' => $analysis['value_bets'],
            'summary' => [
                'count' => $analysis['count'],
                'total_stake' => $analysis['total_stake'],
                'bankroll_usage' => $analysis['bankroll_usage'] . '%',
                'total_expected_value' => $analysis['total_expected_value'] . '%'
            ],
            'recommendation' => $this->getValueBetRecommendation($analysis)
        ]);
    }

    /**
     * GET /api/v1/pmu/races/{raceId}/combinations/tierce
     * Obtenir les meilleures combinaisons Tiercé
     */
    public function getTierceCombinations(int $raceId, Request $request): JsonResponse
    {
        $ordre = filter_var($request->query('ordre', 'false'), FILTER_VALIDATE_BOOLEAN);
        $limit = $request->query('limit', 10);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        $combinations = $ordre
            ? $this->combinations->generateTierceOrdre($predictions, $limit)
            : $this->combinations->generateTierceDesordre($predictions, $limit);

        // Ajouter l'EV pour chaque combinaison
        foreach ($combinations as &$combo) {
            $combo['ev_analysis'] = $this->combinations->calculateExpectedValue(
                $combo,
                $stake = 2,
                $estimatedPayout = $ordre ? 80 : 50
            );
        }

        return response()->json([
            'race_id' => $raceId,
            'type' => $ordre ? 'TIERCE_ORDRE' : 'TIERCE_DESORDRE',
            'combinations' => $combinations,
            'best_combination' => $combinations[0] ?? null,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'total_combinations' => count($combinations)
            ]
        ]);
    }

    /**
     * GET /api/v1/pmu/races/{raceId}/combinations/quinte
     * Obtenir les meilleures combinaisons Quinté
     */
    public function getQuinteCombinations(int $raceId, Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        $combinations = $this->combinations->generateQuinteDesordre($predictions, $limit);

        // Ajouter l'EV pour chaque combinaison
        foreach ($combinations as &$combo) {
            $combo['ev_analysis'] = $this->combinations->calculateExpectedValue(
                $combo,
                $stake = 2,
                $estimatedPayout = 500 // Rapport moyen Quinté
            );
        }

        return response()->json([
            'race_id' => $raceId,
            'type' => 'QUINTE_DESORDRE',
            'combinations' => $combinations,
            'best_combination' => $combinations[0] ?? null,
            'metadata' => [
                'generated_at' => now()->toIso8601String(),
                'total_combinations' => count($combinations)
            ]
        ]);
    }

    /**
     * GET /api/v1/pmu/races/{raceId}/strategy
     * Obtenir la meilleure stratégie de paris pour une course
     */
    public function getBettingStrategy(int $raceId, Request $request): JsonResponse
    {
        $budget = $request->query('budget', 50);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        $strategy = $this->combinations->recommendBestStrategy($predictions, $budget);

        return response()->json([
            'race_id' => $raceId,
            'budget' => $budget,
            'strategy' => [
                'recommendations' => $strategy['recommendations'],
                'budget_distribution' => $strategy['budget_distribution'],
                'total_expected_value' => $strategy['total_expected_value']
            ],
            'summary' => $this->getStrategySummary($strategy, $budget)
        ]);
    }

    /**
     * POST /api/v1/pmu/races/{raceId}/simulate
     * Simuler différentes stratégies de paris
     */
    public function simulateStrategies(int $raceId, Request $request): JsonResponse
    {
        $request->validate([
            'bankroll' => 'required|numeric|min:10',
            'strategies' => 'required|array',
            'strategies.*.type' => 'required|string|in:value_bets,tierce,quinte,mixed',
            'strategies.*.budget' => 'required|numeric|min:1'
        ]);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        $results = [];

        foreach ($request->strategies as $strategy) {
            $results[] = $this->simulateStrategy($strategy, $predictions);
        }

        return response()->json([
            'race_id' => $raceId,
            'bankroll' => $request->bankroll,
            'simulations' => $results,
            'best_strategy' => $this->findBestStrategy($results)
        ]);
    }

    /**
     * Simuler une stratégie spécifique
     */
    private function simulateStrategy(array $strategy, $predictions): array
    {
        $type = $strategy['type'];
        $budget = $strategy['budget'];

        switch ($type) {
            case 'value_bets':
                $analysis = $this->valueBets->analyzeRaceValueBets($predictions, $budget);
                return [
                    'type' => 'VALUE_BETS',
                    'budget' => $budget,
                    'expected_return' => $analysis['total_expected_value'],
                    'risk_level' => 'MEDIUM',
                    'details' => $analysis
                ];

            case 'tierce':
                $combos = $this->combinations->generateTierceDesordre($predictions, 5);
                $totalEv = 0;
                foreach ($combos as $combo) {
                    $ev = $this->combinations->calculateExpectedValue($combo, 2, 50);
                    $totalEv += $ev['expected_value'];
                }
                return [
                    'type' => 'TIERCE',
                    'budget' => $budget,
                    'expected_return' => $totalEv,
                    'risk_level' => 'HIGH',
                    'combinations_count' => count($combos)
                ];

            case 'quinte':
                $combos = $this->combinations->generateQuinteDesordre($predictions, 3);
                $totalEv = 0;
                foreach ($combos as $combo) {
                    $ev = $this->combinations->calculateExpectedValue($combo, 2, 500);
                    $totalEv += $ev['expected_value'];
                }
                return [
                    'type' => 'QUINTE',
                    'budget' => $budget,
                    'expected_return' => $totalEv,
                    'risk_level' => 'VERY_HIGH',
                    'combinations_count' => count($combos)
                ];

            default:
                return [
                    'type' => 'UNKNOWN',
                    'budget' => $budget,
                    'expected_return' => 0
                ];
        }
    }

    /**
     * Trouver la meilleure stratégie parmi les simulations
     */
    private function findBestStrategy(array $results): array
    {
        usort($results, fn($a, $b) => $b['expected_return'] <=> $a['expected_return']);

        return $results[0] ?? [];
    }

    /**
     * Générer une recommandation pour les value bets
     */
    private function getValueBetRecommendation(array $analysis): string
    {
        if ($analysis['count'] === 0) {
            return "No value bets found for this race.";
        }

        $avgEv = $analysis['total_expected_value'] / $analysis['count'];

        if ($avgEv > 10) {
            return "Excellent value bets detected! Strong betting opportunity.";
        } elseif ($avgEv > 5) {
            return "Good value bets available. Consider betting on top picks.";
        } else {
            return "Marginal value bets. Proceed with caution.";
        }
    }

    /**
     * Générer un résumé de stratégie
     */
    private function getStrategySummary(array $strategy, float $budget): array
    {
        $totalStake = array_sum(array_column($strategy['budget_distribution'], 'stake'));
        $totalEv = $strategy['total_expected_value'];

        return [
            'total_bets' => count($strategy['budget_distribution']),
            'total_stake' => round($totalStake, 2),
            'remaining_budget' => round($budget - $totalStake, 2),
            'total_expected_profit' => round($totalEv, 2),
            'roi_estimate' => $totalStake > 0
                ? round(($totalEv / $totalStake) * 100, 2) . '%'
                : '0%',
            'risk_level' => $this->assessRiskLevel($strategy)
        ];
    }

    /**
     * Évaluer le niveau de risque d'une stratégie
     */
    private function assessRiskLevel(array $strategy): string
    {
        $tierceCount = 0;
        $quinteCount = 0;

        foreach ($strategy['recommendations'] as $rec) {
            if (strpos($rec['type'], 'TIERCE') !== false) {
                $tierceCount++;
            } elseif (strpos($rec['type'], 'QUINTE') !== false) {
                $quinteCount++;
            }
        }

        if ($quinteCount > $tierceCount) {
            return 'VERY_HIGH';
        } elseif ($tierceCount > 0) {
            return 'HIGH';
        } else {
            return 'MEDIUM';
        }
    }
}