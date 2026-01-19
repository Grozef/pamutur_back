<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Horse extends Model
{
    protected $primaryKey = 'id_cheval_pmu';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id_cheval_pmu',
        'name',
        'sex',
        'age',
        'father_id',
        'mother_id',
        'dam_sire_name',
        'breed'
    ];

    /**
     * Get father relationship
     */
    public function father(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'father_id', 'id_cheval_pmu');
    }

    /**
     * Get mother relationship
     */
    public function mother(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'mother_id', 'id_cheval_pmu');
    }

    /**
     * Get offspring (as father)
     */
    public function offspringAsFather(): HasMany
    {
        return $this->hasMany(Horse::class, 'father_id', 'id_cheval_pmu');
    }

    /**
     * Get offspring (as mother)
     */
    public function offspringAsMother(): HasMany
    {
        return $this->hasMany(Horse::class, 'mother_id', 'id_cheval_pmu');
    }

    /**
     * Get performances
     */
    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class, 'horse_id', 'id_cheval_pmu');
    }

    /**
     * Calculate average offspring win rate (for stallions)
     */
    public function getOffspringWinRate(): float
    {
        $offspring = $this->offspringAsFather;
        if ($offspring->isEmpty()) return 0.0;

        $totalRaces = 0;
        $totalWins = 0;

        foreach ($offspring as $child) {
            $races = $child->performances()->count();
            $wins = $child->performances()->where('rank', 1)->count();
            
            $totalRaces += $races;
            $totalWins += $wins;
        }

        return $totalRaces > 0 ? ($totalWins / $totalRaces) * 100 : 0.0;
    }

    /**
     * Get horse career statistics
     */
    public function getCareerStats(): array
    {
        $performances = $this->performances;
        
        $totalRaces = $performances->count();
        $wins = $performances->where('rank', 1)->count();
        $places = $performances->whereIn('rank', [1, 2, 3])->count();
        $totalGains = $performances->sum('gains_race');

        return [
            'total_races' => $totalRaces,
            'wins' => $wins,
            'places' => $places,
            'total_gains' => $totalGains,
            'average_gains' => $totalRaces > 0 ? $totalGains / $totalRaces : 0,
            'win_rate' => $totalRaces > 0 ? ($wins / $totalRaces) * 100 : 0,
        ];
    }
}
