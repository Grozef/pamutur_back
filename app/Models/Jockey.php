<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jockey extends Model
{
    protected $fillable = ['name'];

    /**
     * Get performances for this jockey
     */
    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }

    /**
     * Calculate jockey success rate
     */
    public function getSuccessRate(): float
    {
        $total = $this->performances()->count();
        if ($total === 0) return 0.0;

        $wins = $this->performances()->where('rank', 1)->count();
        return ($wins / $total) * 100;
    }

    /**
     * Get synergy with a specific trainer
     */
    public function getSynergyWithTrainer(int $trainerId): float
    {
        $total = $this->performances()->where('trainer_id', $trainerId)->count();
        if ($total === 0) return 0.0;

        $wins = $this->performances()
            ->where('trainer_id', $trainerId)
            ->where('rank', 1)
            ->count();

        return ($wins / $total) * 100;
    }
}
