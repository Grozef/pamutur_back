<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Race;
use App\Models\Horse;
use App\Services\PMUStatisticsService;
use App\Services\PMUFetcherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function getProgramme(Request $request): JsonResponse
    {
        $date = $request->query('date', $this->fetcher->getTodayDate());
        $data = $this->fetcher->fetchProgramme($date);

        if (!$data) {
            return response()->json(['error' => 'Failed to fetch programme'], 500);
        }

        return response()->json($data);
    }

    /**
     * Get reunion data
     */
    public function getReunion(Request $request, int $reunionNum): JsonResponse
    {
        $date = $request->query('date', $this->fetcher->getTodayDate());
        $data = $this->fetcher->fetchReunion($date, $reunionNum);

        if (!$data) {
            return response()->json(['error' => 'Failed to fetch reunion'], 500);
        }

        return response()->json($data);
    }

    /**
     * Get course participants
     */
    public function getParticipants(Request $request, int $reunionNum, int $courseNum): JsonResponse
    {
        $date = $request->query('date', $this->fetcher->getTodayDate());
        $data = $this->fetcher->fetchCourse($date, $reunionNum, $courseNum);

        if (!$data) {
            return response()->json(['error' => 'Failed to fetch course'], 500);
        }

        return response()->json($data);
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
    public function searchHorses(Request $request): JsonResponse
    {
        $query = $request->query('q');

        if (!$query || strlen($query) < 3) {
            return response()->json(['error' => 'Query too short'], 400);
        }

        $horses = Horse::where('name', 'LIKE', "%{$query}%")
            ->limit(20)
            ->get(['id_cheval_pmu', 'name', 'age', 'sex']);

        return response()->json($horses);
    }

    /**
     * Get races by date
     */
    public function getRacesByDate(Request $request): JsonResponse
    {
        $date = $request->query('date', date('Y-m-d'));

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
}
