<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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
     * BUT filter out probabilities < 20% and invalid odds
     *
     * FIXED:
     * - Filter probability < 0.20 (20%)
     * - Filter invalid odds (null, 0, or <= 1.0)
     * - Calculate value_score for all valid bets
     * - Sort by PROBABILITY (as requested)
     * - Take top 20
     */
    public static function storeValueBets(string $date, array $predictions): int
    {
        // Filter and calculate value scores
        $valueBets = collect($predictions)
            ->filter(function ($bet) {
                // Filter out probabilities < 20%
                if (!isset($bet['probability']) || $bet['probability'] < 0.20 || $bet['probability'] > 1) {
                    return false;
                }

                // Skip if no odds or invalid odds
                if (!isset($bet['odds']) || $bet['odds'] === null || $bet['odds'] <= 1.0) {
                    return false;
                }

                return true;
            })
            ->map(function ($bet) {
                // Calculate value score for reference
                $bet['value_score'] = ($bet['probability'] * $bet['odds']) - 1;
                return $bet;
            })
            ->sortByDesc('probability')  // Sort by PROBABILITY (as requested)
            ->take(20)
            ->values();

        // Log statistics for debugging
        Log::info('ValueBets filtering', [
            'total_predictions' => count($predictions),
            'after_filter_20_percent' => $valueBets->count(),
            'best_probability' => $valueBets->first()['probability'] ?? 'none',
            'worst_probability' => $valueBets->last()['probability'] ?? 'none'
        ]);

        // Store top 20 value bets
        $count = 0;
        foreach ($valueBets as $index => $bet) {
            self::updateOrCreate(
                [
                    'bet_date' => $date,
                    'race_id' => $bet['race_id'],
                    'horse_id' => $bet['horse_id']
                ],
                [
                    'horse_name' => $bet['horse_name'],
                    'estimated_probability' => $bet['probability'],
                    'offered_odds' => $bet['odds'],
                    'value_score' => $bet['value_score'],
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

    /**
     * Get top N value bets for a date (ordered by probability)
     */
    public static function getTopValueBets(string $date, int $limit = 10)
    {
        return self::where('bet_date', $date)
            ->orderBy('ranking')
            ->limit($limit)
            ->with(['race', 'horse'])
            ->get();
    }

    /**
     * Calculate expected profit for this bet with 10€ stake
     */
    public function getExpectedProfit(float $stake = 10): float
    {
        return $stake * $this->value_score;
    }

    /**
     * Check if this is a valid value bet
     */
    public function isValidValueBet(): bool
    {
        return $this->estimated_probability >= 0.20
            && $this->offered_odds > 1.0
            && $this->estimated_probability <= 1;
    }
}
