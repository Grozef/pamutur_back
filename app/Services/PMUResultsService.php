<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PMUResultsService
{
    private $baseUrl = 'https://online.turfinfo.api.pmu.fr/rest/client/61';

    /**
     * Fetch race results for a specific date
     */
    public function fetchRaceResults(string $date): array
    {
        try {
            $formattedDate = $this->formatDate($date);
            $response = Http::get("{$this->baseUrl}/programme/{$formattedDate}");

            if (!$response->successful()) {
                Log::error("Failed to fetch PMU programme for {$date}");
                return [];
            }

            $data = $response->json();
            $results = [];

            // Parse reunions and races
            if (isset($data['programme']['reunions'])) {
                foreach ($data['programme']['reunions'] as $reunion) {
                    $hippodrome = $reunion['hippodrome']['libelleCourt'] ?? null;
                    
                    if (isset($reunion['courses'])) {
                        foreach ($reunion['courses'] as $course) {
                            $raceResult = $this->extractRaceResult($course, $hippodrome, $date);
                            if ($raceResult) {
                                $results[] = $raceResult;
                            }
                        }
                    }
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error fetching PMU results: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch results for a specific race
     */
    public function fetchRaceResult(string $date, int $reunionNumber, int $courseNumber): ?array
    {
        try {
            $formattedDate = $this->formatDate($date);
            $url = "{$this->baseUrl}/programme/{$formattedDate}/R{$reunionNumber}/C{$courseNumber}/participants";
            
            $response = Http::get($url);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $this->extractDetailedRaceResult($data, $date);
        } catch (\Exception $e) {
            Log::error("Error fetching race result: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract race result from course data
     */
    private function extractRaceResult(array $course, ?string $hippodrome, string $date): ?array
    {
        // Only extract if race is completed
        if (!isset($course['arriveeDefinitive']) || !$course['arriveeDefinitive']) {
            return null;
        }

        $raceNumber = $course['numOrdre'] ?? null;
        $finalRankings = [];
        $rapports = [];

        // Extract final rankings
        if (isset($course['participants'])) {
            foreach ($course['participants'] as $participant) {
                if (isset($participant['ordreArrivee']) && $participant['ordreArrivee'] > 0) {
                    $finalRankings[] = [
                        'horse_id' => $participant['cheval']['id'] ?? null,
                        'horse_name' => $participant['cheval']['nom'] ?? null,
                        'rank' => $participant['ordreArrivee']
                    ];
                }
            }
        }

        // Extract rapports (dividends)
        if (isset($course['rapports'])) {
            foreach ($course['rapports'] as $rapport) {
                $type = strtolower($rapport['typePari'] ?? '');
                $dividend = $rapport['dividendePourUnEuro'] ?? null;
                
                if ($dividend) {
                    $rapports[$type] = $dividend;
                }
            }
        }

        return [
            'race_date' => $date,
            'hippodrome' => $hippodrome,
            'race_number' => $raceNumber,
            'final_rankings' => $finalRankings,
            'rapports' => $rapports
        ];
    }

    /**
     * Extract detailed race result with all information
     */
    private function extractDetailedRaceResult(array $data, string $date): ?array
    {
        if (!isset($data['participants'])) {
            return null;
        }

        $course = $data['course'] ?? [];
        $hippodrome = $course['hippodrome']['libelleCourt'] ?? null;
        $raceNumber = $course['numOrdre'] ?? null;
        
        $finalRankings = [];
        $rapports = [];

        // Extract final rankings
        foreach ($data['participants'] as $participant) {
            if (isset($participant['ordreArrivee']) && $participant['ordreArrivee'] > 0) {
                $finalRankings[] = [
                    'horse_id' => $participant['cheval']['id'] ?? null,
                    'horse_name' => $participant['cheval']['nom'] ?? null,
                    'rank' => $participant['ordreArrivee'],
                    'jockey' => $participant['driver']['nom'] ?? null,
                    'trainer' => $participant['entraineur']['nom'] ?? null
                ];
            }
        }

        // Extract rapports
        if (isset($data['rapports'])) {
            foreach ($data['rapports'] as $rapport) {
                $type = strtolower($rapport['typePari'] ?? '');
                $dividend = $rapport['dividendePourUnEuro'] ?? null;
                
                if ($dividend) {
                    $rapports[$type] = $dividend;
                }
            }
        }

        return [
            'race_date' => $date,
            'hippodrome' => $hippodrome,
            'race_number' => $raceNumber,
            'final_rankings' => $finalRankings,
            'rapports' => $rapports,
            'dividends' => $data['rapports'] ?? null
        ];
    }

    /**
     * Format date for PMU API
     */
    private function formatDate(string $date): string
    {
        // Convert YYYY-MM-DD to DDMMYYYY or keep YYYYMMDD as is
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return str_replace('-', '', $date);
        }
        return $date;
    }

    /**
     * Get race ID from internal database
     */
    public function findRaceId(string $date, string $hippodrome, int $raceNumber): ?int
    {
        $race = \App\Models\Race::whereDate('race_date', $date)
            ->where('hippodrome', 'LIKE', "%{$hippodrome}%")
            ->first();

        return $race ? $race->id : null;
    }
}
