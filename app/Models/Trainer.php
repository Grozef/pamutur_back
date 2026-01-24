<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trainer extends Model
{
    protected $fillable = ['name'];

    /**
     * Get performances for this trainer
     */
    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }

    /**
     * Calculate trainer success rate with single optimized query
     */
    public function getSuccessRate(): float
    {
        $stats = $this->performances()
            ->whereNotNull('rank')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins
            ')
            ->first();

        if (!$stats || $stats->total === 0) {
            return 0.0;
        }

        return ($stats->wins / $stats->total) * 100;
    }

    /**
     * Get trainer statistics with single query
     */
    public function getStats(): array
    {
        $stats = $this->performances()
            ->whereNotNull('rank')
            ->selectRaw('
                COUNT(*) as total_races,
                SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN rank IN (1,2,3) THEN 1 ELSE 0 END) as places,
                COALESCE(SUM(gains_race), 0) as total_gains
            ')
            ->first();

        if (!$stats || $stats->total_races === 0) {
            return [
                'total_races' => 0,
                'wins' => 0,
                'places' => 0,
                'total_gains' => 0,
                'win_rate' => 0,
                'place_rate' => 0
            ];
        }

        return [
            'total_races' => (int)$stats->total_races,
            'wins' => (int)$stats->wins,
            'places' => (int)$stats->places,
            'total_gains' => (int)$stats->total_gains,
            'win_rate' => round(($stats->wins / $stats->total_races) * 100, 2),
            'place_rate' => round(($stats->places / $stats->total_races) * 100, 2)
        ];
    }
}
