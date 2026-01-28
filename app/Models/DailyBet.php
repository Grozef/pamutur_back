<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBet extends Model
{
    protected $fillable = [
        'bet_date',
        'race_id',
        'horse_id',
        'horse_name',
        'probability',
        'odds',
        'bet_type',
        'metadata',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'probability' => 'float',
        'odds' => 'float',
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
     * Store bets with probability > 40%
     */
    public static function storeDailyBets(string $date, array $predictions): int
    {
        $count = 0;
        
        foreach ($predictions as $prediction) {
            if ($prediction['probability'] > 0.40) {
                self::updateOrCreate(
                    [
                        'bet_date' => $date,
                        'race_id' => $prediction['race_id'],
                        'horse_id' => $prediction['horse_id']
                    ],
                    [
                        'horse_name' => $prediction['horse_name'],
                        'probability' => $prediction['probability'],
                        'odds' => $prediction['odds'] ?? null,
                        'bet_type' => $prediction['bet_type'] ?? 'SIMPLE',
                        'metadata' => $prediction['metadata'] ?? null
                    ]
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get unprocessed bets for a date
     */
    public static function getUnprocessedBets(string $date)
    {
        return self::where('bet_date', $date)
            ->where('is_processed', false)
            ->with(['race', 'horse'])
            ->get();
    }

    /**
     * Mark bets as processed
     */
    public static function markAsProcessed(array $betIds): int
    {
        return self::whereIn('id', $betIds)->update(['is_processed' => true]);
    }
}
