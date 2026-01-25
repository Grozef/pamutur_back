<?php

namespace App\Services;

use Illuminate\Support\Collection;

class ValueBetService
{
    /**
     * Minimum Kelly fraction to consider as value bet
     */
    private const MIN_KELLY_THRESHOLD = 0.001;

    /**
     * Kelly divisor for fractional Kelly (risk management)
     * 0.25 = quarter Kelly, recommended for volatility reduction
     */
    private const KELLY_FRACTION = 0.25;

    /**
     * Calculate optimal bet size using Kelly Criterion
     *
     * Formula: f* = (bp - q) / b
     * where:
     *   f* = fraction of bankroll to bet
     *   b  = odds - 1 (net odds)
     *   p  = probability of winning
     *   q  = probability of losing (1 - p)
     */
    public function calculateKellyBet(float $calculatedProb, ?float $oddsRef, float $bankroll = 1000): array
    {
        // Validate inputs
        if (!$oddsRef || $oddsRef <= 1 || $calculatedProb <= 0 || $calculatedProb > 100) {
            return [
                'is_value' => false,
                'kelly_fraction' => 0,
                'recommended_stake' => 0,
                'edge' => 0,
                'expected_value' => 0
            ];
        }

        $p = $calculatedProb / 100;
        $q = 1 - $p;
        $b = $oddsRef - 1;

        // Kelly formula: (b*p - q) / b
        $kellyFraction = (($b * $p) - $q) / $b;

        // No value bet if Kelly is negative or zero
        if ($kellyFraction <= self::MIN_KELLY_THRESHOLD) {
            return [
                'is_value' => false,
                'kelly_fraction' => 0,
                'full_kelly' => round($kellyFraction * 100, 4),
                'recommended_stake' => 0,
                'edge' => round((($b * $p) - $q) * 100, 2),
                'expected_value' => round((($b * $p) - $q) * 100, 2)
            ];
        }

        // Use fractional Kelly for risk management
        $fractionalKelly = $kellyFraction * self::KELLY_FRACTION;
        $recommendedStake = round($bankroll * $fractionalKelly, 2);

        // Edge = expected profit per unit bet
        $edge = ($b * $p) - $q;
        $expectedValue = $edge * 100;

        // FIX: Safe ROI calculation - avoid division by very small numbers
        $roiPerBet = 0;
        if ($fractionalKelly > self::MIN_KELLY_THRESHOLD) {
            $roiPerBet = round(($edge / $fractionalKelly) * 100, 2);
        }

        return [
            'is_value' => true,
            'kelly_fraction' => round($fractionalKelly * 100, 2),
            'full_kelly' => round($kellyFraction * 100, 2),
            'recommended_stake' => max(1, $recommendedStake), // Minimum 1€
            'edge' => round($edge, 4),
            'expected_value' => round($expectedValue, 2),
            'roi_per_bet' => $roiPerBet,
            'implied_probability' => round((1 / $oddsRef) * 100, 2),
            'our_probability' => round($calculatedProb, 2),
            'probability_edge' => round($calculatedProb - (1 / $oddsRef) * 100, 2)
        ];
    }

    /**
     * Analyze all value bets for a race
     */
    public function analyzeRaceValueBets(Collection $predictions, float $bankroll = 1000): array
    {
        $valueBets = [];
        $totalStake = 0;
        $totalEV = 0;

        foreach ($predictions as $prediction) {
            $kelly = $this->calculateKellyBet(
                $prediction['probability'],
                $prediction['odds_ref'] ?? null,
                $bankroll
            );

            if ($kelly['is_value']) {
                $valueBets[] = [
                    'horse_id' => $prediction['horse_id'],
                    'horse_name' => $prediction['horse_name'],
                    'draw' => $prediction['draw'],
                    'probability' => $prediction['probability'],
                    'odds' => $prediction['odds_ref'],
                    'kelly_data' => $kelly
                ];

                $totalStake += $kelly['recommended_stake'];
                $totalEV += $kelly['expected_value'];
            }
        }

        // Sort by expected value (highest first)
        usort($valueBets, fn($a, $b) =>
            $b['kelly_data']['expected_value'] <=> $a['kelly_data']['expected_value']
        );

        $count = count($valueBets);
        $bankrollUsage = ($totalStake / $bankroll) * 100;

        return [
            'value_bets' => $valueBets,
            'count' => $count,
            'total_stake' => round($totalStake, 2),
            'bankroll_usage' => round($bankrollUsage, 2),
            'total_expected_value' => round($totalEV, 2),
            'average_ev' => $count > 0 ? round($totalEV / $count, 2) : 0,
            // FIX: Safe average ROI calculation
            'average_roi' => $count > 0
                ? round(array_sum(array_column(array_column($valueBets, 'kelly_data'), 'roi_per_bet')) / $count, 2)
                : 0
        ];
    }

    /**
     * Get recommended bet sizing based on confidence level
     */
    public function getRecommendedBetSizing(float $kellyFraction, float $bankroll): array
    {
        return [
            'conservative' => round($bankroll * $kellyFraction * 0.25, 2), // Quarter Kelly
            'moderate' => round($bankroll * $kellyFraction * 0.5, 2),     // Half Kelly
            'aggressive' => round($bankroll * $kellyFraction, 2),         // Full Kelly
        ];
    }
}