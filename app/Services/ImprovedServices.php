<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Service pour calculer les value bets optimaux avec Kelly Criterion
 */
class ValueBetService
{
    /**
     * Calculer la mise optimale selon Kelly Criterion
     */
    public function calculateKellyBet(float $calculatedProb, ?float $oddsRef, float $bankroll = 1000): array
    {
        if (!$oddsRef || $oddsRef <= 1 || $calculatedProb <= 0) {
            return [
                'is_value' => false,
                'kelly_fraction' => 0,
                'recommended_stake' => 0
            ];
        }

        $p = $calculatedProb / 100; // Probabilité de gagner (0-1)
        $q = 1 - $p; // Probabilité de perdre
        $b = $oddsRef - 1; // Net odds (gain net)

        // Kelly formula: f = (bp - q) / b
        $kellyFraction = (($b * $p) - $q) / $b;

        // Kelly doit être positif pour être un value bet
        if ($kellyFraction <= 0) {
            return [
                'is_value' => false,
                'kelly_fraction' => 0,
                'recommended_stake' => 0,
                'edge' => round((($b * $p) - $q) * 100, 2),
                'expected_value' => round((($b * $p) - $q) * 100, 2)
            ];
        }

        // Fractional Kelly (1/4 Kelly pour être plus conservateur)
        $fractionalKelly = $kellyFraction * 0.25;

        // Calculer la mise recommandée
        $recommendedStake = round($bankroll * $fractionalKelly, 2);

        // Edge et Expected Value
        $edge = ($b * $p) - $q;
        $expectedValue = $edge * 100; // En pourcentage

        return [
            'is_value' => true,
            'kelly_fraction' => round($fractionalKelly * 100, 2), // En %
            'full_kelly' => round($kellyFraction * 100, 2), // Kelly complet (risqué)
            'recommended_stake' => max(1, $recommendedStake), // Minimum 1€
            'edge' => round($edge, 4),
            'expected_value' => round($expectedValue, 2), // EV en %
            'roi_per_bet' => round(($edge / $fractionalKelly) * 100, 2) // ROI attendu
        ];
    }

    /**
     * Analyser tous les value bets d'une course
     */
    public function analyzeRaceValueBets(Collection $predictions, float $bankroll = 1000): array
    {
        $valueBets = [];
        $totalStake = 0;

        foreach ($predictions as $prediction) {
            $kelly = $this->calculateKellyBet(
                $prediction['probability'],
                $prediction['odds_ref'],
                $bankroll
            );

            if ($kelly['is_value']) {
                $valueBets[] = [
                    'horse_id' => $prediction['horse_id'],
                    'horse_name' => $prediction['horse_name'],
                    'probability' => $prediction['probability'],
                    'odds' => $prediction['odds_ref'],
                    'kelly_data' => $kelly
                ];

                $totalStake += $kelly['recommended_stake'];
            }
        }

        // Trier par EV décroissant
        usort($valueBets, fn($a, $b) =>
            $b['kelly_data']['expected_value'] <=> $a['kelly_data']['expected_value']
        );

        return [
            'value_bets' => $valueBets,
            'count' => count($valueBets),
            'total_stake' => round($totalStake, 2),
            'bankroll_usage' => round(($totalStake / $bankroll) * 100, 2),
            'total_expected_value' => round(
                array_sum(array_column(array_column($valueBets, 'kelly_data'), 'expected_value')),
                2
            )
        ];
    }
}

/**
 * Service pour générer et optimiser les combinaisons PMU
 */
class CombinationService
{
    /**
     * Générer les meilleures combinaisons Tiercé Ordre
     */
    public function generateTierceOrdre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(8)->values()->toArray(); // Top 8

        // Générer permutations de 3 chevaux
        for ($i = 0; $i < count($horses); $i++) {
            for ($j = 0; $j < count($horses); $j++) {
                if ($j === $i) continue;
                for ($k = 0; $k < count($horses); $k++) {
                    if ($k === $i || $k === $j) continue;

                    // Probabilité = P(1er) × P(2ème|1er) × P(3ème|1er,2ème)
                    // Simplification: produit des probabilités
                    $prob = ($horses[$i]['probability'] / 100)
                          * ($horses[$j]['probability'] / 100)
                          * ($horses[$k]['probability'] / 100);

                    $combinations[] = [
                        'type' => 'TIERCE_ORDRE',
                        'horses' => [
                            $horses[$i]['horse_name'],
                            $horses[$j]['horse_name'],
                            $horses[$k]['horse_name']
                        ],
                        'horse_ids' => [
                            $horses[$i]['horse_id'],
                            $horses[$j]['horse_id'],
                            $horses[$k]['horse_id']
                        ],
                        'probability' => $prob * 100, // En %
                        'estimated_odds' => $this->estimateTierceOdds($prob),
                        'ranks' => [$i + 1, $j + 1, $k + 1]
                    ];
                }
            }
        }

        // Trier par probabilité décroissante
        usort($combinations, fn($a, $b) => $b['probability'] <=> $a['probability']);

        return array_slice($combinations, 0, $limit);
    }

    /**
     * Générer les meilleures combinaisons Tiercé Désordre
     */
    public function generateTierceDesordre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(10)->values()->toArray();

        // Générer toutes les combinaisons de 3 chevaux (sans ordre)
        for ($i = 0; $i < count($horses) - 2; $i++) {
            for ($j = $i + 1; $j < count($horses) - 1; $j++) {
                for ($k = $j + 1; $k < count($horses); $k++) {
                    $prob = ($horses[$i]['probability'] / 100)
                          * ($horses[$j]['probability'] / 100)
                          * ($horses[$k]['probability'] / 100);

                    // En désordre, multiplier par 6 (3! permutations possibles)
                    $probDesordre = $prob * 6;

                    $combinations[] = [
                        'type' => 'TIERCE_DESORDRE',
                        'horses' => [
                            $horses[$i]['horse_name'],
                            $horses[$j]['horse_name'],
                            $horses[$k]['horse_name']
                        ],
                        'horse_ids' => [
                            $horses[$i]['horse_id'],
                            $horses[$j]['horse_id'],
                            $horses[$k]['horse_id']
                        ],
                        'probability' => min(100, $probDesordre * 100),
                        'estimated_odds' => $this->estimateTierceOdds($probDesordre, false),
                        'base_ranks' => [$i + 1, $j + 1, $k + 1]
                    ];
                }
            }
        }

        usort($combinations, fn($a, $b) => $b['probability'] <=> $a['probability']);

        return array_slice($combinations, 0, $limit);
    }

    /**
     * Générer les meilleures combinaisons Quinté Désordre
     */
    public function generateQuinteDesordre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(12)->values()->toArray();

        // Limiter le nombre de combinaisons (C(12,5) = 792)
        for ($i = 0; $i < min(count($horses) - 4, 8); $i++) {
            for ($j = $i + 1; $j < min(count($horses) - 3, 9); $j++) {
                for ($k = $j + 1; $k < min(count($horses) - 2, 10); $k++) {
                    for ($l = $k + 1; $l < min(count($horses) - 1, 11); $l++) {
                        for ($m = $l + 1; $m < min(count($horses), 12); $m++) {
                            $prob = ($horses[$i]['probability'] / 100)
                                  * ($horses[$j]['probability'] / 100)
                                  * ($horses[$k]['probability'] / 100)
                                  * ($horses[$l]['probability'] / 100)
                                  * ($horses[$m]['probability'] / 100);

                            // En désordre, multiplier par 120 (5! permutations)
                            $probDesordre = $prob * 120;

                            $combinations[] = [
                                'type' => 'QUINTE_DESORDRE',
                                'horses' => [
                                    $horses[$i]['horse_name'],
                                    $horses[$j]['horse_name'],
                                    $horses[$k]['horse_name'],
                                    $horses[$l]['horse_name'],
                                    $horses[$m]['horse_name']
                                ],
                                'horse_ids' => [
                                    $horses[$i]['horse_id'],
                                    $horses[$j]['horse_id'],
                                    $horses[$k]['horse_id'],
                                    $horses[$l]['horse_id'],
                                    $horses[$m]['horse_id']
                                ],
                                'probability' => min(100, $probDesordre * 100),
                                'estimated_odds' => $this->estimateQuinteOdds($probDesordre),
                                'base_ranks' => [$i + 1, $j + 1, $k + 1, $l + 1, $m + 1]
                            ];
                        }
                    }
                }
            }
        }

        usort($combinations, fn($a, $b) => $b['probability'] <=> $a['probability']);

        return array_slice($combinations, 0, $limit);
    }

    /**
     * Calculer l'espérance de gain pour une combinaison
     */
    public function calculateExpectedValue(array $combination, float $stake, float $estimatedPayout): array
    {
        $prob = $combination['probability'] / 100;
        $expectedGain = $prob * ($estimatedPayout * $stake);
        $expectedLoss = (1 - $prob) * $stake;
        $ev = $expectedGain - $expectedLoss;

        return [
            'stake' => $stake,
            'estimated_payout' => $estimatedPayout,
            'probability' => $combination['probability'],
            'expected_gain' => round($expectedGain, 2),
            'expected_loss' => round($expectedLoss, 2),
            'expected_value' => round($ev, 2),
            'ev_percentage' => round(($ev / $stake) * 100, 2),
            'is_profitable' => $ev > 0
        ];
    }

    /**
     * Estimer les cotes Tiercé (approximation)
     */
    private function estimateTierceOdds(float $probability, bool $ordre = true): float
    {
        if ($probability <= 0) return 0;

        // Base odds
        $baseOdds = 1 / $probability;

        // Appliquer un facteur selon ordre/désordre
        $factor = $ordre ? 1.5 : 1.2; // Ordre paie plus

        // Ajouter une marge de la maison (15%)
        $houseTake = 0.85;

        return round($baseOdds * $factor * $houseTake, 1);
    }

    /**
     * Estimer les cotes Quinté (approximation)
     */
    private function estimateQuinteOdds(float $probability): float
    {
        if ($probability <= 0) return 0;

        $baseOdds = 1 / $probability;
        $factor = 2.0; // Quinté paie beaucoup plus
        $houseTake = 0.70; // Prélèvement plus important

        return round($baseOdds * $factor * $houseTake, 1);
    }

    /**
     * Recommander la meilleure stratégie de combinaisons
     */
    public function recommendBestStrategy(Collection $predictions, float $budget): array
    {
        $tierce = $this->generateTierceDesordre($predictions, 3);
        $quinte = $this->generateQuinteDesordre($predictions, 3);

        $recommendations = [];

        // Analyser les Tiercés
        foreach ($tierce as $combo) {
            $ev = $this->calculateExpectedValue($combo, 2, 50); // 2€, rapport moyen 50
            if ($ev['is_profitable']) {
                $recommendations[] = [
                    'type' => $combo['type'],
                    'combination' => $combo,
                    'ev_data' => $ev,
                    'priority' => $ev['ev_percentage']
                ];
            }
        }

        // Analyser les Quintés
        foreach ($quinte as $combo) {
            $ev = $this->calculateExpectedValue($combo, 2, 500); // 2€, rapport moyen 500
            if ($ev['is_profitable']) {
                $recommendations[] = [
                    'type' => $combo['type'],
                    'combination' => $combo,
                    'ev_data' => $ev,
                    'priority' => $ev['ev_percentage']
                ];
            }
        }

        // Trier par EV décroissant
        usort($recommendations, fn($a, $b) => $b['priority'] <=> $a['priority']);

        // Calculer la distribution du budget
        $distribution = $this->distributeBudget($recommendations, $budget);

        return [
            'recommendations' => array_slice($recommendations, 0, 5),
            'budget_distribution' => $distribution,
            'total_expected_value' => array_sum(array_column($distribution, 'expected_value'))
        ];
    }

    /**
     * Distribuer le budget de manière optimale
     */
    private function distributeBudget(array $recommendations, float $budget): array
    {
        $distribution = [];
        $remainingBudget = $budget;

        foreach ($recommendations as $rec) {
            if ($remainingBudget <= 0) break;

            // Allouer en fonction de l'EV
            $allocation = min($remainingBudget, $rec['ev_data']['stake'] * 2);
            $remainingBudget -= $allocation;

            $distribution[] = [
                'type' => $rec['type'],
                'horses' => $rec['combination']['horses'],
                'stake' => $allocation,
                'expected_value' => round(
                    ($rec['ev_data']['expected_value'] / $rec['ev_data']['stake']) * $allocation,
                    2
                )
            ];
        }

        return $distribution;
    }
}