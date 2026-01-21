<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jockey extends Model
{
    protected $fillable = ['name'];

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }

    /**
     * FIXED: Calculate jockey success rate with single query
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
     * FIXED: Get synergy with a specific trainer using single query
     */
    public function getSynergyWithTrainer(int $trainerId): float
    {
        $stats = $this->performances()
            ->where('trainer_id', $trainerId)
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
}