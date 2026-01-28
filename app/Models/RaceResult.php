<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceResult extends Model
{
    protected $fillable = [
        'race_id',
        'race_date',
        'hippodrome',
        'race_number',
        'final_rankings',
        'rapports',
        'dividends',
        'fetched_at'
    ];

    protected $casts = [
        'race_date' => 'date',
        'final_rankings' => 'array',
        'rapports' => 'array',
        'dividends' => 'array',
        'fetched_at' => 'datetime'
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * Store race results from PMU API
     */
    public static function storeRaceResult(array $raceData): ?self
    {
        if (!isset($raceData['race_id'])) {
            return null;
        }

        return self::updateOrCreate(
            ['race_id' => $raceData['race_id']],
            [
                'race_date' => $raceData['race_date'],
                'hippodrome' => $raceData['hippodrome'],
                'race_number' => $raceData['race_number'] ?? null,
                'final_rankings' => $raceData['final_rankings'] ?? [],
                'rapports' => $raceData['rapports'] ?? [],
                'dividends' => $raceData['dividends'] ?? null,
                'fetched_at' => now()
            ]
        );
    }

    /**
     * Get results for a specific date
     */
    public static function getResultsForDate(string $date)
    {
        return self::where('race_date', $date)
            ->with('race')
            ->orderBy('hippodrome')
            ->orderBy('race_number')
            ->get();
    }

    /**
     * Get winner for this race
     */
    public function getWinner(): ?array
    {
        if (empty($this->final_rankings)) {
            return null;
        }

        return collect($this->final_rankings)
            ->firstWhere('rank', 1);
    }

    /**
     * Get top 3 horses
     */
    public function getTop3(): array
    {
        if (empty($this->final_rankings)) {
            return [];
        }

        return collect($this->final_rankings)
            ->whereIn('rank', [1, 2, 3])
            ->sortBy('rank')
            ->values()
            ->all();
    }

    /**
     * Get payout for bet type
     */
    public function getPayout(string $betType): ?float
    {
        return $this->rapports[$betType] ?? null;
    }

    /**
     * Check if horse won
     */
    public function didHorseWin(string $horseId): bool
    {
        $winner = $this->getWinner();
        return $winner && $winner['horse_id'] === $horseId;
    }

    /**
     * Check if horse placed in top 3
     */
    public function didHorsePlace(string $horseId): bool
    {
        return collect($this->getTop3())
            ->pluck('horse_id')
            ->contains($horseId);
    }
}
