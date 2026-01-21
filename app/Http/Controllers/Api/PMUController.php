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
use Illuminate\Support\Facades\Cache;
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
            // Cache programme for 30 minutes
            $cacheKey = "pmu_programme_{$date}";

            $data = Cache::remember($cacheKey, 1800, function() use ($date) {
                return $this->fetcher->fetchProgramme($date);
            });

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
            $cacheKey = "pmu_reunion_{$date}_R{$reunionNum}";

            $data = Cache::remember($cacheKey, 1800, function() use ($date, $reunionNum) {
                return $this->fetcher->fetchReunion($date, $reunionNum);
            });

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
            $cacheKey = "pmu_participants_{$date}_R{$reunionNum}C{$courseNum}";

            $data = Cache::remember($cacheKey, 1800, function() use ($date, $reunionNum, $courseNum) {
                return $this->fetcher->fetchCourse($date, $reunionNum, $courseNum);
            });

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
     * Get race predictions (calculated probabilities) with caching
     */
    public function getRacePredictions(int $raceId): JsonResponse
    {
        $race = Race::find($raceId);

        if (!$race) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        // Cache predictions for 1 hour
        $cacheKey = "race_predictions_{$raceId}";

        $predictions = Cache::remember($cacheKey, 3600, function() use ($raceId) {
            return $this->stats->getRacePredictions($raceId);
        });

        return response()->json([
            'race' => [
                'id' => $race->id,
                'date' => $race->race_date->format('c'), // FIXED: Use format('c') for ISO 8601
                'hippodrome' => $race->hippodrome,
                'distance' => $race->distance,
                'discipline' => $race->discipline
            ],
            'predictions' => $predictions,
            'cached' => Cache::has($cacheKey),
            'cache_expires_at' => now()->addHour()->toIso8601String()
        ]);
    }

    /**
     * Clear cache for a specific race (useful after race results are updated)
     */
    public function clearRaceCache(int $raceId): JsonResponse
    {
        Cache::forget("race_predictions_{$raceId}");

        return response()->json([
            'message' => 'Cache cleared successfully',
            'race_id' => $raceId
        ]);
    }

    /**
     * Get horse details with statistics
     */
    public function getHorseDetails(string $horseId): JsonResponse
    {
        $cacheKey = "horse_details_{$horseId}";

        $data = Cache::remember($cacheKey, 7200, function() use ($horseId) {
            $horse = Horse::with(['father', 'mother', 'performances.race'])
                ->find($horseId);

            if (!$horse) {
                return null;
            }

            $careerStats = $horse->getCareerStats();

            return [
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
                            'date' => $perf->race->race_date->format('Y-m-d'),
                            'hippodrome' => $perf->race->hippodrome,
                            'distance' => $perf->race->distance,
                            'rank' => $perf->rank,
                            'jockey' => $perf->jockey?->name,
                            'trainer' => $perf->trainer?->name,
                            'odds' => $perf->odds_ref
                        ];
                    })
            ];
        });

        if (!$data) {
            return response()->json(['error' => 'Horse not found'], 404);
        }

        return response()->json($data);
    }

    /**
     * Get stallion progeny statistics
     */
    public function getStallionStats(string $horseId): JsonResponse
    {
        $cacheKey = "stallion_stats_{$horseId}";

        $stats = Cache::remember($cacheKey, 7200, function() use ($horseId) {
            return $this->stats->getStallionProgenyStats($horseId);
        });

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

        $cacheKey = "races_by_date_{$date}";

        $races = Cache::remember($cacheKey, 1800, function() use ($date) {
            return Race::whereDate('race_date', $date)
                ->with('performances')
                ->get();
        });

        $mapped = $races->map(function ($race) {
            return [
                'id' => $race->id,
                'code' => $race->race_code,
                'date' => $race->race_date->format('c'), // FIXED: Use format('c')
                'hippodrome' => $race->hippodrome,
                'distance' => $race->distance,
                'discipline' => $race->discipline,
                'participants' => $race->getParticipantsCount()
            ];
        });

        return response()->json($mapped->values());
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
            'date' => $race->race_date->format('c'), // FIXED: Use format('c')
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
        if (strlen($date) === 10 && strpos($date, '-') !== false) {
            return $date;
        }

        if (strlen($date) === 8) {
            $dateObj = \DateTime::createFromFormat('dmY', $date);
            return $dateObj ? $dateObj->format('Y-m-d') : $date;
        }

        return $date;
    }

    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'cache_driver' => config('cache.default'),
            'version' => 'v1'
        ]);
    }
}