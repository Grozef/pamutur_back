<?php

namespace App\Services;

use Illuminate\Support\Collection;

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
        
        $p = $calculatedProb / 100;
        $q = 1 - $p;
        $b = $oddsRef - 1;
        
        $kellyFraction = (($b * $p) - $q) / $b;
        
        if ($kellyFraction <= 0) {
            return [
                'is_value' => false,
                'kelly_fraction' => 0,
                'recommended_stake' => 0,
                'edge' => round((($b * $p) - $q) * 100, 2),
                'expected_value' => round((($b * $p) - $q) * 100, 2)
            ];
        }
        
        $fractionalKelly = $kellyFraction * 0.25;
        $recommendedStake = round($bankroll * $fractionalKelly, 2);
        $edge = ($b * $p) - $q;
        $expectedValue = $edge * 100;
        
        return [
            'is_value' => true,
            'kelly_fraction' => round($fractionalKelly * 100, 2),
            'full_kelly' => round($kellyFraction * 100, 2),
            'recommended_stake' => max(1, $recommendedStake),
            'edge' => round($edge, 4),
            'expected_value' => round($expectedValue, 2),
            'roi_per_bet' => round(($edge / max(0.001, $fractionalKelly)) * 100, 2)
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
                $prediction['odds_ref'] ?? null,
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
