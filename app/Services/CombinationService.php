<?php

namespace App\Services;

use Illuminate\Support\Collection;

class CombinationService
{
    public function generateTierceOrdre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(8)->values()->toArray();
        
        for ($i = 0; $i < count($horses); $i++) {
            for ($j = 0; $j < count($horses); $j++) {
                if ($j === $i) continue;
                for ($k = 0; $k < count($horses); $k++) {
                    if ($k === $i || $k === $j) continue;
                    
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
                        'probability' => $prob * 100,
                        'estimated_odds' => $this->estimateTierceOdds($prob, true),
                        'ranks' => [$i + 1, $j + 1, $k + 1]
                    ];
                }
            }
        }
        
        usort($combinations, fn($a, $b) => $b['probability'] <=> $a['probability']);
        return array_slice($combinations, 0, $limit);
    }
    
    public function generateTierceDesordre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(10)->values()->toArray();
        
        for ($i = 0; $i < count($horses) - 2; $i++) {
            for ($j = $i + 1; $j < count($horses) - 1; $j++) {
                for ($k = $j + 1; $k < count($horses); $k++) {
                    $prob = ($horses[$i]['probability'] / 100) 
                          * ($horses[$j]['probability'] / 100) 
                          * ($horses[$k]['probability'] / 100);
                    
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
    
    public function generateQuinteDesordre(Collection $predictions, int $limit = 10): array
    {
        $combinations = [];
        $horses = $predictions->take(10)->values()->toArray();
        
        for ($i = 0; $i < min(count($horses) - 4, 6); $i++) {
            for ($j = $i + 1; $j < min(count($horses) - 3, 7); $j++) {
                for ($k = $j + 1; $k < min(count($horses) - 2, 8); $k++) {
                    for ($l = $k + 1; $l < min(count($horses) - 1, 9); $l++) {
                        for ($m = $l + 1; $m < min(count($horses), 10); $m++) {
                            $prob = ($horses[$i]['probability'] / 100)
                                  * ($horses[$j]['probability'] / 100)
                                  * ($horses[$k]['probability'] / 100)
                                  * ($horses[$l]['probability'] / 100)
                                  * ($horses[$m]['probability'] / 100);
                            
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
    
    private function estimateTierceOdds(float $probability, bool $ordre = true): float
    {
        if ($probability <= 0) return 0;
        
        $baseOdds = 1 / $probability;
        $factor = $ordre ? 1.5 : 1.2;
        $houseTake = 0.85;
        
        return round($baseOdds * $factor * $houseTake, 1);
    }
    
    private function estimateQuinteOdds(float $probability): float
    {
        if ($probability <= 0) return 0;
        
        $baseOdds = 1 / $probability;
        $factor = 2.0;
        $houseTake = 0.70;
        
        return round($baseOdds * $factor * $houseTake, 1);
    }
}
