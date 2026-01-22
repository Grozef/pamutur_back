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

        $races = Cache::remember($cacheKey, 1800, function() use ($date) {
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
     * Get today's programme (fetch from PMU API)
     */
    public function getProgramme(string $date): JsonResponse
    {
        try {
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
     * Get race predictions with value bets
     */
    public function getRacePredictions(int $raceId): JsonResponse
    {
        $race = Race::find($raceId);

        if (!$race) {
            return response()->json(['error' => 'Race not found'], 404);
        }

        $cacheKey = "race_predictions_{$raceId}";

        $predictions = Cache::remember($cacheKey, 3600, function() use ($raceId) {
            return $this->stats->getRacePredictions($raceId);
        });

        return response()->json([
            'race' => [
                'id' => $race->id,
                'date' => $race->race_date->toIso8601String(),
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
     * Get value bets with Kelly Criterion
     */
    public function getValueBets(int $raceId, Request $request): JsonResponse
    {
        $bankroll = $request->query('bankroll', 1000);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
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
                'total_expected_value' => $analysis['total_expected_value'] . '%'
            ]
        ]);
    }

    /**
     * Get Tiercé combinations
     */
    public function getTierceCombinations(int $raceId, Request $request): JsonResponse
    {
        $ordre = filter_var($request->query('ordre', 'false'), FILTER_VALIDATE_BOOLEAN);
        $limit = $request->query('limit', 10);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
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
            'best_combination' => $combinations[0] ?? null
        ]);
    }

    /**
     * Get Quinté combinations
     */
    public function getQuinteCombinations(int $raceId, Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $predictions = $this->stats->getRacePredictions($raceId);

        if ($predictions->isEmpty()) {
            return response()->json(['error' => 'No predictions available'], 404);
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
            'best_combination' => $combinations[0] ?? null
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
            'version' => 'v1'
        ]);
    }
}