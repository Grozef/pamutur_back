<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Performance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PMUStatisticsService
{
    public function calculateProbability(Performance $performance): float
    {
        $startTime = microtime(true);

        try {
            $formScore = $this->calculateFormScore($performance);
            $classScore = $this->calculateClassScore($performance);
            $jockeyScore = $this->calculateJockeyScore($performance);
            $aptitudeScore = $this->calculateAptitudeScore($performance);

            $weightedScore = ($formScore * 0.4) + ($classScore * 0.25) +
                            ($jockeyScore * 0.25) + ($aptitudeScore * 0.1);

            $finalScore = max(1, min(100, $weightedScore * 10));

            $duration = (microtime(true) - $startTime) * 1000;
            Log::channel('algorithm')->info('Probability calculated', [
                'horse_id' => $performance->horse_id,
                'race_id' => $performance->race_id,
                'scores' => [
                    'form' => round($formScore, 2),
                    'class' => round($classScore, 2),
                    'jockey' => round($jockeyScore, 2),
                    'aptitude' => round($aptitudeScore, 2),
                    'final' => round($finalScore, 2)
                ],
                'duration_ms' => round($duration, 2)
            ]);

            return $finalScore;

        } catch (\Exception $e) {
            Log::error('Error calculating probability', [
                'horse_id' => $performance->horse_id,
                'race_id' => $performance->race_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return neutral score on error
            return 50.0;
        }
    }

    private function calculateFormScore(Performance $performance): float
    {
        $musique = $performance->raw_musique;
        if (!$musique) return 5.0;

        $parsed = $this->parseMusique($musique);
        if (empty($parsed)) return 5.0;

        $score = 0;
        $totalWeight = 0;

        foreach ($parsed as $year => $results) {
            $weight = $this->getYearWeight($year);
            $yearScore = $this->calculateYearScore($results);

            $score += $yearScore * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $score / $totalWeight : 5.0;
    }

    private function parseMusique(string $musique): array
    {
        $currentYear = (int)date('Y');
        $results = [];

        preg_match_all('/(\d+[a-zA-Z]+|\([0-9]{2}\)|[DT]a[a-z]*|Tombé|Arr[êe]t[ée]?|0[a-zA-Z]*)/', $musique, $matches);

        $activeYear = $currentYear;

        foreach ($matches[0] as $token) {
            if (preg_match('/\((\d{2})\)/', $token, $yearMatch)) {
                $activeYear = 2000 + (int)$yearMatch[1];
                continue;
            }

            if (!isset($results[$activeYear])) {
                $results[$activeYear] = [];
            }

            $results[$activeYear][] = $token;
        }

        return $results;
    }

    private function getYearWeight(int $year): float
    {
        $currentYear = (int)date('Y');
        $diff = $currentYear - $year;

        if ($diff === 0) return 1.0;
        if ($diff === 1) return 0.5;
        if ($diff === 2) return 0.25;

        return 0.1;
    }

    private function calculateYearScore(array $results): float
    {
        if (empty($results)) return 5.0;

        $score = 0;
        foreach ($results as $result) {
            $rank = (int)preg_replace('/[^0-9]/', '', $result);

            if ($rank === 1) $score += 10;
            elseif ($rank === 2) $score += 7;
            elseif ($rank === 3) $score += 5;
            elseif ($rank === 4) $score += 3;
            elseif ($rank === 5) $score += 2;
            elseif (preg_match('/[DT]|Tombé|Arr/', $result)) $score += 0;
            else $score += 1;
        }

        return min(10, $score / count($results));
    }

    private function calculateClassScore(Performance $performance): float
    {
        try {
            $horse = $performance->horse;
            if (!$horse) return 5.0;

            $stats = $horse->getCareerStats();
            if ($stats['total_races'] === 0) return 5.0;

            $confidenceFactor = min(1.0, $stats['total_races'] / 20);

            $winRateBonus = ($stats['win_rate'] / 10) * $confidenceFactor;
            $avgGains = $stats['average_gains'];
            $earningsScore = min(5, ($avgGains / 2000));

            return min(10, $winRateBonus + $earningsScore);

        } catch (\Exception $e) {
            Log::warning('Error in calculateClassScore', [
                'horse_id' => $performance->horse_id,
                'error' => $e->getMessage()
            ]);
            return 5.0;
        }
    }

    private function calculateJockeyScore(Performance $performance): float
    {
        try {
            $jockey = $performance->jockey;
            $trainer = $performance->trainer;

            if (!$jockey && !$trainer) return 5.0;

            $score = 5.0;

            if ($jockey) {
                $jockeyRate = $jockey->getSuccessRate();
                $score += ($jockeyRate / 10) - 0.5;
            }

            if ($jockey && $trainer) {
                $synergyRate = $jockey->getSynergyWithTrainer($trainer->id);
                $score += ($synergyRate / 20);
            }

            return max(0, min(10, $score));

        } catch (\Exception $e) {
            Log::warning('Error in calculateJockeyScore', [
                'horse_id' => $performance->horse_id,
                'error' => $e->getMessage()
            ]);
            return 5.0;
        }
    }

    private function calculateAptitudeScore(Performance $performance): float
    {
        $score = 5.0;

        try {
            // Safe access to race with null check
            if ($performance->draw) {
                $race = $performance->race;

                if ($race) {
                    $totalRunners = $race->getParticipantsCount();

                    if ($totalRunners > 0) {
                        $drawPercentile = $performance->draw / $totalRunners;

                        if ($drawPercentile <= 0.2) {
                            $score += 2;
                        } elseif ($drawPercentile <= 0.4) {
                            $score += 1;
                        } elseif ($drawPercentile >= 0.8) {
                            $score -= 2;
                        } elseif ($drawPercentile >= 0.6) {
                            $score -= 1;
                        }
                    } else {
                        // Fallback to simple draw logic
                        if ($performance->draw <= 3) {
                            $score += 2;
                        } elseif ($performance->draw <= 5) {
                            $score += 1;
                        } elseif ($performance->draw >= 12) {
                            $score -= 2;
                        } elseif ($performance->draw >= 10) {
                            $score -= 1;
                        }
                    }
                } else {
                    // No race info, use simple draw logic
                    if ($performance->draw <= 3) {
                        $score += 2;
                    } elseif ($performance->draw <= 5) {
                        $score += 1;
                    } elseif ($performance->draw >= 12) {
                        $score -= 2;
                    } elseif ($performance->draw >= 10) {
                        $score -= 1;
                    }
                }
            }

            if ($performance->weight) {
                $weightKg = $performance->weight / 1000;

                if ($weightKg > 60) {
                    $penalty = ($weightKg - 60) * 0.3;
                    $score -= $penalty;
                } elseif ($weightKg < 52) {
                    $bonus = (52 - $weightKg) * 0.2;
                    $score += $bonus;
                }
            }

        } catch (\Exception $e) {
            Log::warning('Error in calculateAptitudeScore', [
                'horse_id' => $performance->horse_id,
                'error' => $e->getMessage()
            ]);
        }

        return max(0, min(10, $score));
    }

    private function detectRaceScenario(array $sortedScores): array
    {
        $count = count($sortedScores);
        if ($count < 3) {
            return [
                'scenario' => 'INSUFFICIENT_DATA',
                'top_size' => $count,
                'top_percentage' => 100,
                'rest_percentage' => 0,
                'description' => 'Pas assez de partants'
            ];
        }

        $gaps = [];
        for ($i = 0; $i < min($count - 1, 5); $i++) {
            $gaps[$i] = $sortedScores[$i]['score'] - $sortedScores[$i + 1]['score'];
        }

        if (isset($gaps[0]) && $gaps[0] > 15) {
            return [
                'scenario' => 'DOMINANT_FAVORITE',
                'top_size' => 1,
                'top_percentage' => 50,
                'second_percentage' => 18,
                'third_percentage' => 12,
                'rest_percentage' => 20,
                'description' => 'Favori dominant détecté'
            ];
        }

        if (isset($gaps[0], $gaps[1]) && $gaps[0] > 10 && $gaps[1] > 10) {
            return [
                'scenario' => 'CLEAR_TOP_2',
                'top_size' => 2,
                'top_percentage' => 70,
                'rest_percentage' => 30,
                'description' => 'Deux favoris nets'
            ];
        }

        $topGrouped = isset($gaps[0], $gaps[1]) && max($gaps[0], $gaps[1]) <= 5;

        if ($topGrouped) {
            if (isset($gaps[2], $gaps[3]) && $gaps[2] <= 5 && $gaps[3] <= 5) {
                return [
                    'scenario' => 'GROUPED_TOP_5',
                    'top_size' => 5,
                    'top_percentage' => 80,
                    'rest_percentage' => 20,
                    'description' => 'Top 5 groupé'
                ];
            }

            if (isset($gaps[2]) && $gaps[2] <= 5) {
                return [
                    'scenario' => 'GROUPED_TOP_4',
                    'top_size' => 4,
                    'top_percentage' => 75,
                    'rest_percentage' => 25,
                    'description' => 'Top 4 groupé'
                ];
            }

            return [
                'scenario' => 'GROUPED_TOP_3',
                'top_size' => 3,
                'top_percentage' => 70,
                'rest_percentage' => 30,
                'description' => 'Top 3 groupé'
            ];
        }

        return [
            'scenario' => 'STANDARD_TOP_3',
            'top_size' => 3,
            'top_percentage' => 70,
            'rest_percentage' => 30,
            'description' => 'Top 3 standard'
        ];
    }

    public function getRacePredictions(int $raceId): Collection
    {
        $startTime = microtime(true);

        try {
            // Ensure race relationship is loaded
            $performances = Performance::with(['horse', 'jockey', 'trainer', 'race'])
                ->where('race_id', $raceId)
                ->get();

            if ($performances->isEmpty()) {
                Log::info('No performances found for race', ['race_id' => $raceId]);
                return collect([]);
            }

            $scoredHorses = $performances->map(function ($performance) {
                try {
                    return [
                        'performance' => $performance,
                        'score' => $this->calculateProbability($performance),
                        'horse_id' => $performance->horse_id,
                        'horse_name' => $performance->horse?->name ?? 'Unknown',
                        'jockey_name' => $performance->jockey?->name,
                        'odds_ref' => $performance->odds_ref,
                        'draw' => $performance->draw,
                        'weight' => $performance->weight
                    ];
                } catch (\Exception $e) {
                    Log::error('Error scoring horse', [
                        'horse_id' => $performance->horse_id,
                        'race_id' => $performance->race_id,
                        'error' => $e->getMessage()
                    ]);

                    return [
                        'performance' => $performance,
                        'score' => 50.0,
                        'horse_id' => $performance->horse_id,
                        'horse_name' => $performance->horse?->name ?? 'Unknown',
                        'jockey_name' => $performance->jockey?->name,
                        'odds_ref' => $performance->odds_ref,
                        'draw' => $performance->draw,
                        'weight' => $performance->weight
                    ];
                }
            })->sortByDesc('score')->values();

            $scenario = $this->detectRaceScenario($scoredHorses->toArray());
            $predictions = $this->distributeProbabilities($scoredHorses, $scenario);

            // FIX: Properly propagate race_scenario to the first prediction
            if ($predictions->isNotEmpty()) {
                $predictions = $predictions->map(function ($pred, $index) use ($scenario) {
                    if ($index === 0) {
                        $pred['race_scenario'] = $scenario;
                    }
                    return $pred;
                });
            }

            $duration = (microtime(true) - $startTime) * 1000;
            Log::channel('algorithm')->info('Race predictions calculated', [
                'race_id' => $raceId,
                'horses_count' => $predictions->count(),
                'scenario' => $scenario['scenario'],
                'duration_ms' => round($duration, 2)
            ]);

            return $predictions;

        } catch (\Exception $e) {
            Log::error('Error in getRacePredictions', [
                'race_id' => $raceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return collect([]);
        }
    }

    private function distributeProbabilities(Collection $scoredHorses, array $scenario): Collection
    {
        $totalHorses = $scoredHorses->count();

        if ($totalHorses <= 3) {
            return $this->distributeEqual($scoredHorses);
        }

        switch ($scenario['scenario']) {
            case 'DOMINANT_FAVORITE':
                return $this->distributeDominantFavorite($scoredHorses, $scenario);
            case 'CLEAR_TOP_2':
                return $this->distributeClearTop2($scoredHorses, $scenario);
            case 'GROUPED_TOP_4':
            case 'GROUPED_TOP_5':
            case 'GROUPED_TOP_3':
            case 'STANDARD_TOP_3':
                return $this->distributeGroupedTop($scoredHorses, $scenario);
            default:
                return $this->distributeEqual($scoredHorses);
        }
    }

    private function distributeDominantFavorite(Collection $scoredHorses, array $scenario): Collection
    {
        $totalHorses = $scoredHorses->count();

        return $scoredHorses->map(function ($horse, $index) use ($totalHorses) {
            if ($index === 0) {
                $probability = 50.0;
            } elseif ($index === 1) {
                $probability = 18.0;
            } elseif ($index === 2) {
                $probability = 12.0;
            } else {
                $remaining = max(1, $totalHorses - 3);
                $probability = 20.0 / $remaining;
            }

            return [
                'horse_id' => $horse['horse_id'],
                'horse_name' => $horse['horse_name'],
                'jockey_name' => $horse['jockey_name'],
                'probability' => round($probability, 2),
                'odds_ref' => $horse['odds_ref'],
                'value_bet' => $this->isValueBet($probability, $horse['odds_ref']),
                'draw' => $horse['draw'],
                'weight' => $horse['weight'],
                'in_top_group' => $index < 3,
                'rank' => $index + 1
            ];
        });
    }

    private function distributeClearTop2(Collection $scoredHorses, array $scenario): Collection
    {
        $totalHorses = $scoredHorses->count();
        $restCount = max(1, $totalHorses - 2);

        return $scoredHorses->map(function ($horse, $index) use ($restCount) {
            if ($index === 0) {
                $probability = 38.0;
            } elseif ($index === 1) {
                $probability = 32.0;
            } else {
                $probability = 30.0 / $restCount;
            }

            return [
                'horse_id' => $horse['horse_id'],
                'horse_name' => $horse['horse_name'],
                'jockey_name' => $horse['jockey_name'],
                'probability' => round($probability, 2),
                'odds_ref' => $horse['odds_ref'],
                'value_bet' => $this->isValueBet($probability, $horse['odds_ref']),
                'draw' => $horse['draw'],
                'weight' => $horse['weight'],
                'in_top_group' => $index < 2,
                'rank' => $index + 1
            ];
        });
    }

    private function distributeGroupedTop(Collection $scoredHorses, array $scenario): Collection
    {
        $topSize = $scenario['top_size'];
        $topPercentage = $scenario['top_percentage'];
        $restPercentage = $scenario['rest_percentage'];

        $topGroup = $scoredHorses->take($topSize);
        $topTotalScore = $topGroup->sum('score');

        $restGroup = $scoredHorses->slice($topSize);
        $restTotalScore = $restGroup->sum('score');

        return $scoredHorses->map(function ($horse, $index) use ($topSize, $topPercentage, $restPercentage, $topTotalScore, $restTotalScore, $scoredHorses) {
            if ($index < $topSize) {
                $probability = $topTotalScore > 0
                    ? ($horse['score'] / $topTotalScore) * $topPercentage
                    : $topPercentage / $topSize;
            } else {
                $restCount = max(1, $scoredHorses->count() - $topSize);
                $probability = $restTotalScore > 0
                    ? ($horse['score'] / $restTotalScore) * $restPercentage
                    : $restPercentage / $restCount;
            }

            return [
                'horse_id' => $horse['horse_id'],
                'horse_name' => $horse['horse_name'],
                'jockey_name' => $horse['jockey_name'],
                'probability' => round($probability, 2),
                'odds_ref' => $horse['odds_ref'],
                'value_bet' => $this->isValueBet($probability, $horse['odds_ref']),
                'draw' => $horse['draw'],
                'weight' => $horse['weight'],
                'in_top_group' => $index < $topSize,
                'rank' => $index + 1
            ];
        });
    }

    private function distributeEqual(Collection $scoredHorses): Collection
    {
        $count = $scoredHorses->count();
        $probability = 100.0 / max(1, $count);

        return $scoredHorses->map(function ($horse, $index) use ($probability) {
            return [
                'horse_id' => $horse['horse_id'],
                'horse_name' => $horse['horse_name'],
                'jockey_name' => $horse['jockey_name'],
                'probability' => round($probability, 2),
                'odds_ref' => $horse['odds_ref'],
                'value_bet' => $this->isValueBet($probability, $horse['odds_ref']),
                'draw' => $horse['draw'],
                'weight' => $horse['weight'],
                'in_top_group' => false,
                'rank' => $index + 1
            ];
        });
    }

    private function isValueBet(float $calculatedProb, ?float $oddsRef): bool
    {
        if (!$oddsRef || $oddsRef <= 1) return false;

        $marketProb = (1 / $oddsRef) * 100;
        $ourProb = $calculatedProb;

        $relativeEdge = $ourProb > ($marketProb * 1.2);
        $absoluteEdge = ($ourProb - $marketProb) > 5.0;

        return $relativeEdge || $absoluteEdge;
    }

    public function getStallionProgenyStats(string $horseId): array
    {
        $horse = Horse::find($horseId);
        if (!$horse) return [];

        $offspring = $horse->offspringAsFather;

        $stats = [
            'total_offspring' => $offspring->count(),
            'offspring_win_rate' => $horse->getOffspringWinRate(),
            'best_offspring' => []
        ];

        $stats['best_offspring'] = $offspring->map(function ($child) {
            $childStats = $child->getCareerStats();
            return [
                'name' => $child->name,
                'win_rate' => $childStats['win_rate'],
                'total_gains' => $childStats['total_gains']
            ];
        })->sortByDesc('win_rate')->take(5)->values()->toArray();

        return $stats;
    }
}
