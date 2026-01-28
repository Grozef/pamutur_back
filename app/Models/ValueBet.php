<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValueBet extends Model
{
    protected $fillable = [
        'bet_date',
        'race_id',
        'horse_id',
        'horse_name',
        'estimated_probability',
        'offered_odds',
        'value_score',
        'ranking',
        'metadata',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'estimated_probability' => 'float',
        'offered_odds' => 'float',
        'value_score' => 'float',
        'ranking' => 'integer',
        'metadata' => 'array',
        'is_processed' => 'boolean'
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'horse_id', 'id_cheval_pmu');
    }

    /**
     * Store top 20 value bets BY PROBABILITY (highest first)
     */
    public static function storeValueBets(string $date, array $predictions): int
    {
        // Sort by PROBABILITY (not Kelly score) and take top 20
        $valueBets = collect($predictions)
            ->sortByDesc('probability')
            ->take(20)
            ->values();

        // Store top 20
        $count = 0;
        foreach ($valueBets as $index => $bet) {
            // Calculate value score for reference
            $valueScore = ($bet['probability'] * ($bet['odds'] ?? 0)) - 1;
            
            self::updateOrCreate(
                [
                    'bet_date' => $date,
                    'race_id' => $bet['race_id'],
                    'horse_id' => $bet['horse_id']
                ],
                [
                    'horse_name' => $bet['horse_name'],
                    'estimated_probability' => $bet['probability'],
                    'offered_odds' => $bet['odds'] ?? null,
                    'value_score' => $valueScore,
                    'ranking' => $index + 1,
                    'metadata' => $bet['metadata'] ?? null
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Get unprocessed value bets for a date
     */
    public static function getUnprocessedBets(string $date)
    {
        return self::where('bet_date', $date)
            ->where('is_processed', false)
            ->orderBy('ranking')
            ->with(['race', 'horse'])
            ->get();
    }

    /**
     * Mark value bets as processed
     */
    public static function markAsProcessed(array $betIds): int
    {
        return self::whereIn('id', $betIds)->update(['is_processed' => true]);
    }
}
