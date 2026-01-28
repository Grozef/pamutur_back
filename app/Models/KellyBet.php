<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KellyBet extends Model
{
    protected $fillable = [
        'bet_date',
        'race_id',
        'horse_id',
        'horse_name',
        'probability',
        'odds',
        'kelly_fraction',
        'bet_amount',
        'bankroll',
        'metadata',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'probability' => 'float',
        'odds' => 'float',
        'kelly_fraction' => 'float',
        'bet_amount' => 'decimal:2',
        'bankroll' => 'decimal:2',
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
     * Calculate Kelly fraction
     * Kelly% = (prob * odds - 1) / (odds - 1)
     */
    public static function calculateKellyFraction(float $probability, float $odds): float
    {
        if ($odds <= 1) return 0;
        
        $kellyFraction = ($probability * $odds - 1) / ($odds - 1);
        
        // Kelly fraction should be between 0 and 1
        return max(0, min(1, $kellyFraction));
    }

    /**
     * Add a manual Kelly bet
     */
    public static function addManualBet(array $betData): self
    {
        // Calculate Kelly fraction
        $kellyFraction = self::calculateKellyFraction(
            $betData['probability'],
            $betData['odds']
        );

        // Calculate bet amount if not provided
        if (!isset($betData['bet_amount'])) {
            $betData['bet_amount'] = $betData['bankroll'] * $kellyFraction;
        }

        return self::create([
            'bet_date' => $betData['bet_date'],
            'race_id' => $betData['race_id'],
            'horse_id' => $betData['horse_id'],
            'horse_name' => $betData['horse_name'],
            'probability' => $betData['probability'],
            'odds' => $betData['odds'],
            'kelly_fraction' => $kellyFraction,
            'bet_amount' => $betData['bet_amount'],
            'bankroll' => $betData['bankroll'],
            'metadata' => $betData['metadata'] ?? null
        ]);
    }

    /**
     * Get Kelly bets for a date
     */
    public static function getKellyBets(string $date)
    {
        return self::where('bet_date', $date)
            ->with(['race', 'horse'])
            ->orderByDesc('bet_amount')
            ->get();
    }

    /**
     * Mark as processed
     */
    public static function markAsProcessed(array $betIds): int
    {
        return self::whereIn('id', $betIds)->update(['is_processed' => true]);
    }
}
