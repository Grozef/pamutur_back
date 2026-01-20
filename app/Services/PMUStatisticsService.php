<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Performance;
use Illuminate\Support\Collection;

class PMUStatisticsService
{
    /**
     * Calculate probability score for a horse in a race
     * Returns score 0-100
     */
    public function calculateProbability(Performance $performance): float
    {
        $formScore = $this->calculateFormScore($performance);
        $classScore = $this->calculateClassScore($performance);
        $jockeyScore = $this->calculateJockeyScore($performance);
        $aptitudeScore = $this->calculateAptitudeScore($performance);

        $rawScore = ($formScore * 4) + ($classScore * 2.5) + ($jockeyScore * 2.5) + ($aptitudeScore * 1);

        return max(1, min(100, $rawScore));
    }

    /**
     * Calculate form score from musique
     */
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

        preg_match_all('/(\d+[a-zA-Z]|\([0-9]{2}\)|[DT]a[a-z]?)/', $musique, $matches);

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
            elseif ($rank === 2) $score += 8;
            elseif ($rank === 3) $score += 6;
            elseif ($rank === 4) $score += 4;
            elseif ($rank === 5) $score += 3;
            elseif (preg_match('/[DT]/', $result)) $score += 0;
            else $score += 2;
        }

        return min(10, $score / count($results));
    }

    private function calculateClassScore(Performance $performance): float
    {
        $horse = $performance->horse;
        if (!$horse) return 5.0;

        $stats = $horse->getCareerStats();
        if ($stats['total_races'] === 0) return 5.0;

        $winRateBonus = $stats['win_rate'] / 10;
        $avgGains = $stats['average_gains'];
        $earningsScore = min(5, ($avgGains / 2000));

        return min(10, $winRateBonus + $earningsScore);
    }

    private function calculateJockeyScore(Performance $performance): float
    {
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
    }

    private function calculateAptitudeScore(Performance $performance): float
    {
        $score = 5.0;

        if ($performance->draw) {
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

        return max(0, min(10, $score));
    }

    /**
     * Detect race scenario based on score gaps
     */
    private function detectRaceScenario(array $sortedScores): array
    {
        $count = count($sortedScores);
        if ($count < 3) {
            return [
                'scenario' => 'INSUFFICIENT_DATA',
                'top_size' => $count,
                'top_percentage' => 100,
                'description' => 'Pas assez de partants'
            ];
        }

        // Calculate gaps
        $gaps = [];
        for ($i = 0; $i < min($count - 1, 5); $i++) {
            $gaps[$i] = $sortedScores[$i]['score'] - $sortedScores[$i + 1]['score'];
        }

        // Scenario 1: SUPERFAVORI (1 cheval domine)
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

        // Scenario 2: DUO DE TÊTE
        if (isset($gaps[0], $gaps[1]) && $gaps[0] > 10 && $gaps[1] > 10) {
            return [
                'scenario' => 'CLEAR_TOP_2',
                'top_size' => 2,
                'top_percentage' => 70,
                'rest_percentage' => 30,
                'description' => 'Deux favoris nets'
            ];
        }

        // Check if top is grouped
        $topGrouped = isset($gaps[0], $gaps[1]) &&
                      max($gaps[0], $gaps[1]) <= 5;

        if ($topGrouped) {
            // Check for TOP 5 GROUPED
            if (isset($gaps[2], $gaps[3]) && $gaps[2] <= 5 && $gaps[3] <= 5) {
                return [
                    'scenario' => 'GROUPED_TOP_5',
                    'top_size' => 5,
                    'top_percentage' => 80,
                    'rest_percentage' => 20,
                    'description' => 'Top 5 groupé'
                ];
            }

            // Check for TOP 4 GROUPED
            if (isset($gaps[2]) && $gaps[2] <= 5) {
                return [
                    'scenario' => 'GROUPED_TOP_4',
                    'top_size' => 4,
                    'top_percentage' => 75,
                    'rest_percentage' => 25,
                    'description' => 'Top 4 groupé'
                ];
            }
        }

        // Check for OPEN RACE (all between 50-70)
        $allScores = array_column($sortedScores, 'score');
        $maxScore = max($allScores);
        $minScore = min($allScores);

        if ($maxScore <= 70 && $minScore >= 50) {
            return [
                'scenario' => 'OPEN_RACE',
                'top_size' => 3,
                'top_percentage' => 60,
                'rest_percentage' => 40,
                'description' => 'Course ouverte'
            ];
        }

        // Default: TOP 3 STANDARD
        return [
            'scenario' => 'STANDARD_TOP_3',
            'top_size' => 3,
            'top_percentage' => 70,
            'rest_percentage' => 30,
            'description' => 'Top 3 standard'
        ];
    }

    /**
     * Get race predictions with adaptive algorithm
     */
    public function getRacePredictions(int $raceId): Collection
    {
        $performances = Performance::with(['horse', 'jockey', 'trainer'])
            ->where('race_id', $raceId)
            ->get();

        if ($performances->isEmpty()) {
            return collect([]);
        }

        // Calculate raw scores
        $scoredHorses = $performances->map(function ($performance) {
            return [
                'performance' => $performance,
                'score' => $this->calculateProbability($performance),
                'horse_id' => $performance->horse_id,
                'horse_name' => $performance->horse->name,
                'jockey_name' => $performance->jockey?->name,
                'odds_ref' => $performance->odds_ref,
                'draw' => $performance->draw,
                'weight' => $performance->weight
            ];
        })->sortByDesc('score')->values();

        // Detect scenario
        $scenario = $this->detectRaceScenario($scoredHorses->toArray());

        // Distribute probabilities based on scenario
        $predictions = $this->distributeProbabilities($scoredHorses, $scenario);

        // Add scenario info to first prediction for UI display
        if ($predictions->isNotEmpty()) {
            $firstPrediction = $predictions->first();
            $firstPrediction['race_scenario'] = $scenario;
        }

        return $predictions;
    }

    /**
     * Distribute probabilities based on detected scenario
     */
    private function distributeProbabilities(Collection $scoredHorses, array $scenario): Collection
    {
        $totalHorses = $scoredHorses->count();

        switch ($scenario['scenario']) {
            case 'DOMINANT_FAVORITE':
                return $this->distributeDominantFavorite($scoredHorses, $scenario);

            case 'CLEAR_TOP_2':
                return $this->distributeClearTop2($scoredHorses, $scenario);

            case 'GROUPED_TOP_4':
            case 'GROUPED_TOP_5':
            case 'STANDARD_TOP_3':
            case 'OPEN_RACE':
                return $this->distributeGroupedTop($scoredHorses, $scenario);

            default:
                // Fallback to equal distribution
                return $this->distributeEqual($scoredHorses);
        }
    }

    private function distributeDominantFavorite(Collection $scoredHorses, array $scenario): Collection
    {
        $totalHorses = $scoredHorses->count();

        return $scoredHorses->map(function ($horse, $index) use ($scenario, $totalHorses) {
            if ($index === 0) {
                $probability = 50.0;
            } elseif ($index === 1) {
                $probability = 18.0;
            } elseif ($index === 2) {
                $probability = 12.0;
            } else {
                // Distribute 20% among the rest
                $remaining = $totalHorses - 3;
                $probability = $remaining > 0 ? 20.0 / $remaining : 0;
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

        // Calculate total score for top group
        $topGroup = $scoredHorses->take($topSize);
        $topTotalScore = $topGroup->sum('score');

        // Calculate total score for rest
        $restGroup = $scoredHorses->slice($topSize);
        $restTotalScore = $restGroup->sum('score');

        return $scoredHorses->map(function ($horse, $index) use ($topSize, $topPercentage, $restPercentage, $topTotalScore, $restTotalScore) {
            if ($index < $topSize) {
                // In top group - proportional distribution
                $probability = $topTotalScore > 0
                    ? ($horse['score'] / $topTotalScore) * $topPercentage
                    : $topPercentage / $topSize;
            } else {
                // In rest - proportional distribution
                $probability = $restTotalScore > 0
                    ? ($horse['score'] / $restTotalScore) * $restPercentage
                    : $restPercentage / max(1, count($scoredHorses) - $topSize);
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
        $probability = 100.0 / $count;

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

    /**
     * Detect value bet
     */
    private function isValueBet(float $calculatedProb, ?float $oddsRef): bool
    {
        if (!$oddsRef || $oddsRef <= 1) return false;

        $marketProb = (1 / $oddsRef) * 100;
        $ourProb = $calculatedProb;

        $relativeEdge = $ourProb > ($marketProb * 1.2);
        $absoluteEdge = ($ourProb - $marketProb) > 5.0;

        return $relativeEdge || $absoluteEdge;
    }

    /**
     * Get stallion progeny statistics
     */
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