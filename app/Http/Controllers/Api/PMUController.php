<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Race;
use App\Models\Horse;
use App\Services\PMUStatisticsService;
use App\Services\PMUFetcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\RaceRequest;
use App\Http\Requests\SearchRequest;

class PMUController extends Controller
{
    private PMUStatisticsService $stats;
    private PMUFetcherService $fetcher;

    public function __construct(PMUStatisticsService $stats, PMUFetcherService $fetcher)
    {
        $this->stats = $stats;
        $this->fetcher = $fetcher;
    }

    /**
     * Get today's programme (proxy to PMU API)
     */
    public function getProgramme(string $date): JsonResponse
    {
        try {
            $data = $this->fetcher->fetchProgramme($date);

            if (!$data) {
                Log::warning('Programme not found', ['date' => $date]);
                return response()->json(['error' => 'Programme not found for date'], 404);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Failed to fetch programme', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Get reunion data
     */
    public function getReunion(string $date, int $reunionNum): JsonResponse
    {
        try {
            $data = $this->fetcher->fetchReunion($date, $reunionNum);

            if (!$data) {
                Log::warning('Reunion not found', ['date' => $date, 'reunion' => $reunionNum]);
                return response()->json(['error' => 'Reunion not found'], 404);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Failed to fetch reunion', [
                'date' => $date,
                'reunion' => $reunionNum,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Get course participants
     */
    public function getParticipants(string $date, int $reunionNum, int $courseNum): JsonResponse
    {
        try {
            $data = $this->fetcher->fetchCourse($date, $reunionNum, $courseNum);

            if (!$data) {
                Log::warning('Course not found', [
                    'date' => $date,
                    'reunion' => $reunionNum,
                    'course' => $courseNum
                ]);
                return response()->json(['error' => 'Course not found'], 404);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Failed to fetch course', [
                'date' => $date,
                'reunion' => $reunionNum,
                'course' => $courseNum,
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /**
     * Get race predictions (calculated probabilities)
     */
    public function getRacePredictions(int $raceId): JsonResponse
    {
        $race = Race::find($raceId);

        if (!$race) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        $predictions = $this->stats->getRacePredictions($raceId);

        return response()->json([
            'race' => [
                'id' => $race->id,
                'date' => $race->race_date,
                'hippodrome' => $race->hippodrome,
                'distance' => $race->distance,
                'discipline' => $race->discipline
            ],
            'predictions' => $predictions
        ]);
    }

    /**
     * Get horse details with statistics
     */
    public function getHorseDetails(string $horseId): JsonResponse
    {
        $horse = Horse::with(['father', 'mother', 'performances.race'])
            ->find($horseId);

        if (!$horse) {
            return response()->json(['error' => 'Horse not found'], 404);
        }

        $careerStats = $horse->getCareerStats();

        $data = [
            'horse' => [
                'id' => $horse->id_cheval_pmu,
                'name' => $horse->name,
                'sex' => $horse->sex,
                'age' => $horse->age,
                'breed' => $horse->breed
            ],
            'genealogy' => [
                'father' => $horse->father ? [
                    'id' => $horse->father->id_cheval_pmu,
                    'name' => $horse->father->name
                ] : null,
                'mother' => $horse->mother ? [
                    'id' => $horse->mother->id_cheval_pmu,
                    'name' => $horse->mother->name
                ] : null,
                'dam_sire' => $horse->dam_sire_name
            ],
            'career_stats' => $careerStats,
            'recent_performances' => $horse->performances()
                ->with(['race', 'jockey', 'trainer'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($perf) {
                    return [
                        'date' => $perf->race->race_date,
                        'hippodrome' => $perf->race->hippodrome,
                        'distance' => $perf->race->distance,
                        'rank' => $perf->rank,
                        'jockey' => $perf->jockey?->name,
                        'trainer' => $perf->trainer?->name,
                        'odds' => $perf->odds_ref
                    ];
                })
        ];

        return response()->json($data);
    }

    /**
     * Get stallion progeny statistics
     */
    public function getStallionStats(string $horseId): JsonResponse
    {
        $stats = $this->stats->getStallionProgenyStats($horseId);

        if (empty($stats)) {
            return response()->json(['error' => 'Horse not found'], 404);
        }

        return response()->json($stats);
    }

    /**
     * Search horses by name
     */
    public function searchHorses(SearchRequest $request): JsonResponse
    {
        $query = $request->validated()['q'];

        $horses = Horse::where('name', 'LIKE', "%{$query}%")
            ->limit(20)
            ->get(['id_cheval_pmu', 'name', 'age', 'sex']);

        return response()->json($horses);
    }

    /**
     * Get races by date
     */
    public function getRacesByDate(RaceRequest $request): JsonResponse
    {
        $dateParam = $request->query('date', date('Y-m-d'));
        $date = $this->normalizeDateFormat($dateParam);

        $races = Race::whereDate('race_date', $date)
            ->with('performances')
            ->get();

        $mapped = $races->map(function ($race) {
            return [
                'id' => $race->id,
                'code' => $race->race_code,
                'date' => $race->race_date->toIso8601String(), // ← CORRECTION ICI
                'hippodrome' => $race->hippodrome,
                'distance' => $race->distance,
                'discipline' => $race->discipline,
                'participants' => $race->getParticipantsCount()
            ];
        });

        return response()->json($mapped->values()); // ← Ajouter ->values()
    }

    /**
     * Find race by code
     */
    public function findRaceByCode(Request $request): JsonResponse
    {
        $code = $request->query('code');

        if (!$code) {
            return response()->json(['error' => 'Code parameter required'], 400);
        }

        $race = Race::where('race_code', $code)->first();

        if (!$race) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        return response()->json([
            'id' => $race->id,
            'code' => $race->race_code,
            'date' => $race->race_date->toIso8601String(),
            'hippodrome' => $race->hippodrome,
            'distance' => $race->distance,
            'discipline' => $race->discipline
        ]);
    }

    /**
     * Normalize date format - accept both Y-m-d and dmY formats
     */
    private function normalizeDateFormat(string $date): string
    {
        // Accept both Y-m-d and dmY formats
        if (strlen($date) === 10 && strpos($date, '-') !== false) {
            // Already in Y-m-d format
            return $date;
        }

        if (strlen($date) === 8) {
            // Convert dmY to Y-m-d
            $dateObj = \DateTime::createFromFormat('dmY', $date);
            return $dateObj ? $dateObj->format('Y-m-d') : $date;
        }

        return $date;
    }
}
