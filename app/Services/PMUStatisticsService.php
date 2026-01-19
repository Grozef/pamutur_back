<?php

namespace App\Services;

use App\Models\Horse;
use App\Models\Performance;
use Illuminate\Support\Collection;

class PMUStatisticsService
{
    /**
     * Calculate probability score for a horse in a race
     * Based on algorithm: Score = (Form * 0.4) + (Class * 0.25) + (Jockey * 0.25) + (Aptitude * 0.1)
     */
    public function calculateProbability(Performance $performance): float
    {
        $formScore = $this->calculateFormScore($performance);
        $classScore = $this->calculateClassScore($performance);
        $jockeyScore = $this->calculateJockeyScore($performance);
        $aptitudeScore = $this->calculateAptitudeScore($performance);

        return ($formScore * 0.4) + ($classScore * 0.25) + ($jockeyScore * 0.25) + ($aptitudeScore * 0.1);
    }

    /**
     * Calculate form score from musique (recent performance)
     * Weighted by year: 2026 = 1.0, 2025 = 0.5
     */
    private function calculateFormScore(Performance $performance): float
    {
        $musique = $performance->raw_musique;
        if (!$musique) return 0.0;

        $parsed = $this->parseMusique($musique);
        
        $score = 0;
        $totalWeight = 0;

        foreach ($parsed as $year => $results) {
            $weight = $this->getYearWeight($year);
            $yearScore = $this->calculateYearScore($results);
            
            $score += $yearScore * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $score / $totalWeight : 0.0;
    }

    /**
     * Parse musique string into year-based results
     * Example: "1p(25)4p1p" -> [2026 => ['1p'], 2025 => ['4p', '1p']]
     */
    private function parseMusique(string $musique): array
    {
        $currentYear = (int)date('Y');
        $results = [];
        $currentYearResults = [];
        
        preg_match_all('/(\d+[a-zA-Z]|\([0-9]{2}\)|[DT]a[a-z]?)/', $musique, $matches);
        
        $activeYear = $currentYear;
        
        foreach ($matches[0] as $token) {
            // Check if year marker
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

    /**
     * Get temporal weight for a year
     */
    private function getYearWeight(int $year): float
    {
        $currentYear = (int)date('Y');
        $diff = $currentYear - $year;
        
        if ($diff === 0) return 1.0;
        if ($diff === 1) return 0.5;
        if ($diff === 2) return 0.25;
        
        return 0.1;
    }

    /**
     * Calculate score from year results
     */
    private function calculateYearScore(array $results): float
    {
        if (empty($results)) return 0.0;

        $score = 0;
        foreach ($results as $result) {
            $rank = (int)preg_replace('/[^0-9]/', '', $result);
            
            if ($rank === 1) $score += 10;
            elseif ($rank === 2) $score += 7;
            elseif ($rank === 3) $score += 5;
            elseif ($rank <= 5) $score += 3;
            elseif (preg_match('/[DT]/', $result)) $score += 0; // DNF
            else $score += 1;
        }

        return $score / count($results);
    }

    /**
     * Calculate class score (career earnings / races)
     */
    private function calculateClassScore(Performance $performance): float
    {
        $horse = $performance->horse;
        if (!$horse) return 0.0;

        $stats = $horse->getCareerStats();
        
        if ($stats['total_races'] === 0) return 0.0;

        // Normalize gains per race to 0-10 scale
        $avgGains = $stats['average_gains'];
        
        // Typical scale: 0-50000 euros per race
        $normalized = min(10, ($avgGains / 5000));
        
        return $normalized;
    }

    /**
     * Calculate jockey/trainer synergy score
     */
    private function calculateJockeyScore(Performance $performance): float
    {
        $jockey = $performance->jockey;
        $trainer = $performance->trainer;

        if (!$jockey || !$trainer) return 5.0; // Neutral

        $synergyRate = $jockey->getSynergyWithTrainer($trainer->id);
        
        // Normalize to 0-10 scale
        return $synergyRate / 10;
    }

    /**
     * Calculate aptitude score based on draw and weight
     */
    private function calculateAptitudeScore(Performance $performance): float
    {
        $score = 5.0; // Base neutral

        // Draw position (placeCorde)
        if ($performance->draw) {
            if ($performance->draw <= 3) {
                $score += 2; // Good draw
            } elseif ($performance->draw >= 12) {
                $score -= 2; // Bad draw
            }
        }

        // Weight penalty (handicapPoids)
        if ($performance->weight) {
            $weightKg = $performance->weight / 1000;
            
            if ($weightKg > 60) {
                $penalty = ($weightKg - 60) * 0.5;
                $score -= $penalty;
            }
        }

        return max(0, min(10, $score));
    }

    /**
     * Get race predictions with all horses ranked
     */
    public function getRacePredictions(int $raceId): Collection
    {
        $performances = Performance::with(['horse', 'jockey', 'trainer'])
            ->where('race_id', $raceId)
            ->get();

        return $performances->map(function ($performance) {
            $probability = $this->calculateProbability($performance);
            
            return [
                'horse_id' => $performance->horse_id,
                'horse_name' => $performance->horse->name,
                'jockey_name' => $performance->jockey?->name,
                'probability' => round($probability, 2),
                'odds_ref' => $performance->odds_ref,
                'value_bet' => $this->isValueBet($probability, $performance->odds_ref),
                'draw' => $performance->draw,
                'weight' => $performance->weight
            ];
        })->sortByDesc('probability')->values();
    }

    /**
     * Detect value bet (our probability > market probability)
     */
    private function isValueBet(float $calculatedProb, ?float $oddsRef): bool
    {
        if (!$oddsRef || $oddsRef <= 1) return false;

        $marketProb = 1 / $oddsRef;
        $ourProb = $calculatedProb / 10; // Normalize to 0-1

        return $ourProb > ($marketProb * 1.2); // 20% edge
    }

    /**
     * Get genealogy statistics for a stallion
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

        // Get top 5 performing offspring
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
