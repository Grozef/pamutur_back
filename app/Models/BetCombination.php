<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BetCombination extends Model
{
    protected $fillable = [
        'bet_date',
        'race_id',
        'combination_type',
        'horses',
        'combined_probability',
        'source_bets',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'horses' => 'array',
        'combined_probability' => 'float',
        'source_bets' => 'array',
        'is_processed' => 'boolean'
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * Generate combinations from daily bets and value bets
     *
     * FIXED: Added validation that race_id exists before creating combinations
     */
    public static function generateCombinations(string $date): int
    {
        $count = 0;

        // Get all bets for the date grouped by race
        $dailyBets = DailyBet::where('bet_date', $date)
            ->where('is_processed', false)
            ->get()
            ->groupBy('race_id');

        $valueBets = ValueBet::where('bet_date', $date)
            ->where('is_processed', false)
            ->get()
            ->groupBy('race_id');

        // Merge bets by race
        $allRaces = $dailyBets->keys()->merge($valueBets->keys())->unique();

        foreach ($allRaces as $raceId) {
            // FIXED: Validate that race exists in database
            $race = Race::find($raceId);
            if (!$race) {
                Log::warning("Race {$raceId} not found in database, skipping combinations", [
                    'date' => $date,
                    'race_id' => $raceId
                ]);
                continue;
            }

            $raceDailyBets = $dailyBets->get($raceId, collect());
            $raceValueBets = $valueBets->get($raceId, collect());

            // Merge and deduplicate horses
            $allHorses = $raceDailyBets->merge($raceValueBets)
                ->unique('horse_id')
                ->sortByDesc('probability');

            if ($allHorses->count() >= 2) {
                $created = self::createCombinationsForRace($date, $raceId, $allHorses);
                $count += $created;

                Log::info("Created {$created} combinations for race {$raceId}", [
                    'date' => $date,
                    'horses_count' => $allHorses->count()
                ]);
            }
        }

        return $count;
    }

    /**
     * Create combinations for a specific race
     */
    private static function createCombinationsForRace(string $date, int $raceId, $horses): int
    {
        $count = 0;
        $horseArray = $horses->values()->all();

        // Generate COUPLE combinations (all pairs)
        for ($i = 0; $i < count($horseArray); $i++) {
            for ($j = $i + 1; $j < count($horseArray); $j++) {
                $horse1 = $horseArray[$i];
                $horse2 = $horseArray[$j];

                // Simple multiplication (assumes independence - could be improved with conditional probabilities)
                $combinedProb = $horse1->probability * $horse2->probability;

                self::updateOrCreate(
                    [
                        'bet_date' => $date,
                        'race_id' => $raceId,
                        'combination_type' => 'COUPLE',
                        'horses' => json_encode([
                            ['horse_id' => $horse1->horse_id, 'horse_name' => $horse1->horse_name],
                            ['horse_id' => $horse2->horse_id, 'horse_name' => $horse2->horse_name]
                        ])
                    ],
                    [
                        'combined_probability' => $combinedProb,
                        'source_bets' => [
                            'daily_bets' => [$horse1->id ?? null, $horse2->id ?? null],
                            'value_bets' => []
                        ]
                    ]
                );
                $count++;
            }
        }

        // Generate TRIO combinations (top 3 horses)
        if (count($horseArray) >= 3) {
            $topThree = array_slice($horseArray, 0, 3);
            $combinedProb = array_product(array_map(fn($h) => $h->probability, $topThree));

            self::updateOrCreate(
                [
                    'bet_date' => $date,
                    'race_id' => $raceId,
                    'combination_type' => 'TRIO',
                    'horses' => json_encode(array_map(fn($h) => [
                        'horse_id' => $h->horse_id,
                        'horse_name' => $h->horse_name
                    ], $topThree))
                ],
                [
                    'combined_probability' => $combinedProb,
                    'source_bets' => [
                        'daily_bets' => array_map(fn($h) => $h->id ?? null, $topThree),
                        'value_bets' => []
                    ]
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Get unprocessed combinations for a date
     */
    public static function getUnprocessedCombinations(string $date)
    {
        return self::where('bet_date', $date)
            ->where('is_processed', false)
            ->with('race')
            ->orderByDesc('combined_probability')
            ->get();
    }

    /**
     * Mark combinations as processed
     */
    public static function markAsProcessed(array $combinationIds): int
    {
        return self::whereIn('id', $combinationIds)->update(['is_processed' => true]);
    }

    /**
     * Get best combinations for a race
     */
    public static function getBestCombinationsForRace(int $raceId, string $type = null, int $limit = 5)
    {
        $query = self::where('race_id', $raceId)
            ->orderByDesc('combined_probability')
            ->limit($limit);

        if ($type) {
            $query->where('combination_type', strtoupper($type));
        }

        return $query->get();
    }
}
