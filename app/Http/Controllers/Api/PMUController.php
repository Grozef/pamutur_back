<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Race;
use App\Services\PMUStatisticsService;
use App\Services\PMUFetcherService;
use App\Services\ValueBetService;
use App\Services\CombinationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PMUController extends Controller
{
    private PMUStatisticsService $stats;
    private PMUFetcherService $fetcher;
    private ValueBetService $valueBets;
    private CombinationService $combinations;

    // FIX: Reduced cache durations for more accurate odds
    private const CACHE_PROGRAMME = 900;     // 15 min for programme
    private const CACHE_PARTICIPANTS = 300;  // 5 min for participants (odds change)
    private const CACHE_PREDICTIONS = 600;   // 10 min for predictions
    private const CACHE_RACES = 1800;        // 30 min for race list

    public function __construct(
        PMUStatisticsService $stats,
        PMUFetcherService $fetcher,
        ValueBetService $valueBets,
        CombinationService $combinations
    ) {
        $this->stats = $stats;
        $this->fetcher = $fetcher;
        $this->valueBets = $valueBets;
        $this->combinations = $combinations;
    }

    /**
     * Get all races by date (for TopHorses component)
     */
    public function getRacesByDate(Request $request): JsonResponse
    {
        $date = $request->query('date', date('Y-m-d'));

        $cacheKey = "races_by_date_{$date}";

        $races = Cache::remember($cacheKey, self::CACHE_RACES, function() use ($date) {
            return Race::whereDate('race_date', $date)
                ->withCount('performances')
                ->orderBy('race_date')
                ->get()
                ->map(function ($race) {
                    return [
                        'id' => $race->id,
                        'code' => $race->race_code,
                        'date' => $race->race_date->toIso8601String(),
                        'hippodrome' => $race->hippodrome,
                        'distance' => $race->distance,
                        'discipline' => $race->discipline,
                        'participants_count' => $race->performances_count
                    ];
                });
        });

        return response()->json($races);
    }

    /**
     * FIX: New endpoint to resolve race_id from PMU external data
     * Maps PMU race_code (R1C2) to internal database ID
     */
    public function resolveRaceId(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $raceCode = $request->query('race_code');

        if (!$date || !$raceCode) {
            return response()->json([
                'error' => 'Missing required parameters: date, race_code'
            ], 400);
        }

        // Try to find the race in local database
        $race = Race::whereDate('race_date', $date)
            ->where('race_code', $raceCode)
            ->first();

        if (!$race) {
            // Also try with hippodrome pattern if race_code doesn't match exactly
            $race = Race::whereDate('race_date', $date)
                ->where('race_code', 'LIKE', "%{$raceCode}%")
                ->first();
        }

        if (!$race) {
            return response()->json([
                'error' => 'Race not found in database',
                'date' => $date,
                'race_code' => $raceCode,
                'hint' => 'Run php artisan pmu:fetch to import race data'
            ], 404);
        }

        return response()->json([
            'race_id' => $race->id,
            'race_code' => $race->race_code,
            'date' => $race->race_date->toIso8601String(),
            'hippodrome' => $race->hippodrome,
            'distance' => $race->distance,
            'discipline' => $race->discipline,
            'participants_count' => $race->getParticipantsCount()
        ]);
    }

    /**
     * Get today's programme (fetch from PMU API)
     */
    public function getProgramme(string $date): JsonResponse
    {
        try {
            $cacheKey = "pmu_programme_{$date}";

            $data = Cache::remember($cacheKey, self::CACHE_PROGRAMME, function() use ($date) {
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

            $data = Cache::remember($cacheKey, self::CACHE_PROGRAMME, function() use ($date, $reunionNum) {
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
     * FIX: Reduced cache time for more accurate odds
     */
    public function getParticipants(string $date, int $reunionNum, int $courseNum): JsonResponse
    {
        try {
            $cacheKey = "pmu_participants_{$date}_R{$reunionNum}C{$courseNum}";

            // FIX: Shorter cache for participants (odds change frequently)
            $data = Cache::remember($cacheKey, self::CACHE_PARTICIPANTS, function() use ($date, $reunionNum, $courseNum) {
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
     * Get race predictions with value bets
     */
    public function getRacePredictions(int $raceId): JsonResponse
    {
        $race = Race::find($raceId);

        if (!$race) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        $cacheKey = "race_predictions_{$raceId}";

        $predictions = Cache::remember($cacheKey, self::CACHE_PREDICTIONS, function() use ($raceId) {
            return $this->stats->getRacePredictions($raceId);
        });

        return response()->json([
            'race' => [
                'id' => $race->id,
                'code' => $race->race_code,
                'date' => $race->race_date->toIso8601String(),
                'hippodrome' => $race->hippodrome,
                'distance' => $race->distance,
                'discipline' => $race->discipline
            ],
            'predictions' => $predictions,
            'cached' => Cache::has($cacheKey),
            'cache_ttl_seconds' => self::CACHE_PREDICTIONS
        ]);
    }

    /**
     * Get value bets with Kelly Criterion
     */
    public function getValueBets(int $raceId, Request $request): JsonResponse
    {
        $bankroll = (float) $request->query('bankroll', 1000);

        // Validate bankroll
        if ($bankroll < 10 || $bankroll > 1000000) {
            return response()->json([
                'error' => 'Bankroll must be between 10 and 1,000,000'
            ], 400);
        }

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json([
                'error' => 'No predictions available',
                'race_id' => $raceId,
                'hint' => 'Ensure race data has been imported'
            ], 404);
        }

        $analysis = $this->valueBets->analyzeRaceValueBets($predictions, $bankroll);

        return response()->json([
            'race_id' => $raceId,
            'bankroll' => $bankroll,
            'value_bets' => $analysis['value_bets'],
            'summary' => [
                'count' => $analysis['count'],
                'total_stake' => $analysis['total_stake'],
                'bankroll_usage' => $analysis['bankroll_usage'] . '%',
                'total_expected_value' => $analysis['total_expected_value'] . '%',
                'average_ev' => $analysis['average_ev'] . '%'
            ]
        ]);
    }

    /**
     * Get Tiercé combinations
     */
    public function getTierceCombinations(int $raceId, Request $request): JsonResponse
    {
        $ordre = filter_var($request->query('ordre', 'false'), FILTER_VALIDATE_BOOLEAN);
        $limit = min((int) $request->query('limit', 10), 50); // Max 50

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        if ($predictions->count() < 3) {
            return response()->json([
                'error' => 'Not enough horses for tiercé',
                'horses_count' => $predictions->count(),
                'required' => 3
            ], 400);
        }

        $combinations = $ordre
            ? $this->combinations->generateTierceOrdre($predictions, $limit)
            : $this->combinations->generateTierceDesordre($predictions, $limit);

        foreach ($combinations as &$combo) {
            $combo['ev_analysis'] = $this->combinations->calculateExpectedValue(
                $combo,
                $stake = 2,
                $estimatedPayout = $ordre ? 80 : 50
            );
        }

        return response()->json([
            'race_id' => $raceId,
            'type' => $ordre ? 'TIERCE_ORDRE' : 'TIERCE_DESORDRE',
            'combinations' => $combinations,
            'best_combination' => $combinations[0] ?? null,
            'total_combinations' => count($combinations)
        ]);
    }

    /**
     * Get Quinté combinations
     */
    public function getQuinteCombinations(int $raceId, Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 10), 50); // Max 50

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
        }

        if ($predictions->count() < 5) {
            return response()->json([
                'error' => 'Not enough horses for quinté',
                'horses_count' => $predictions->count(),
                'required' => 5
            ], 400);
        }

        $combinations = $this->combinations->generateQuinteDesordre($predictions, $limit);

        foreach ($combinations as &$combo) {
            $combo['ev_analysis'] = $this->combinations->calculateExpectedValue(
                $combo,
                $stake = 2,
                $estimatedPayout = 500
            );
        }

        return response()->json([
            'race_id' => $raceId,
            'type' => 'QUINTE_DESORDRE',
            'combinations' => $combinations,
            'best_combination' => $combinations[0] ?? null,
            'total_combinations' => count($combinations)
        ]);
    }

    /**
     * Health check
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'version' => '1.1.0',
            'cache_config' => [
                'programme_ttl' => self::CACHE_PROGRAMME,
                'participants_ttl' => self::CACHE_PARTICIPANTS,
                'predictions_ttl' => self::CACHE_PREDICTIONS
            ]
        ]);
    }
}