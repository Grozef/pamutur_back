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
     * Calculate trainer success rate
     */
    public function getSuccessRate(): float
    {
        $total = $this->performances()->count();
        if ($total === 0) return 0.0;

        $wins = $this->performances()->where('rank', 1)->count();
        return ($wins / $total) * 100;
    }
}
