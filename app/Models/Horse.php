<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    public function father(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'father_id', 'id_cheval_pmu');
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'mother_id', 'id_cheval_pmu');
    }

    public function offspringAsFather(): HasMany
    {
        return $this->hasMany(Horse::class, 'father_id', 'id_cheval_pmu');
    }

    public function offspringAsMother(): HasMany
    {
        return $this->hasMany(Horse::class, 'mother_id', 'id_cheval_pmu');
    }

    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class, 'horse_id', 'id_cheval_pmu');
    }

    /**
     * Calculate average offspring win rate efficiently with single query
     */
    public function getOffspringWinRate(): float
    {
        $stats = DB::table('performances')
            ->join('horses', 'performances.horse_id', '=', 'horses.id_cheval_pmu')
            ->where('horses.father_id', $this->id_cheval_pmu)
            ->whereNotNull('performances.rank')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN performances.rank = 1 THEN 1 ELSE 0 END) as wins
            ')
            ->first();

        if (!$stats || $stats->total === 0) {
            return 0.0;
        }

        return ($stats->wins / $stats->total) * 100;
    }

    /**
     * Get horse career statistics (optimized with single SQL query)
     */
    public function getCareerStats(): array
    {
        $stats = $this->performances()
            ->selectRaw('
                COUNT(*) as total_races,
                COUNT(rank) as completed_races,
                SUM(CASE WHEN rank = 1 THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN rank IN (1,2,3) THEN 1 ELSE 0 END) as places,
                COALESCE(SUM(gains_race), 0) as total_gains
            ')
            ->first();

        if (!$stats || $stats->total_races === 0) {
            return [
                'total_races' => 0,
                'completed_races' => 0,
                'wins' => 0,
                'places' => 0,
                'total_gains' => 0,
                'average_gains' => 0,
                'win_rate' => 0,
                'place_rate' => 0,
            ];
        }

        return [
            'total_races' => (int)$stats->total_races,
            'completed_races' => (int)$stats->completed_races,
            'wins' => (int)$stats->wins,
            'places' => (int)$stats->places,
            'total_gains' => (int)$stats->total_gains,
            'average_gains' => $stats->total_races > 0
                ? round($stats->total_gains / $stats->total_races, 2)
                : 0,
            'win_rate' => $stats->completed_races > 0
                ? round(($stats->wins / $stats->completed_races) * 100, 2)
                : 0,
            'place_rate' => $stats->completed_races > 0
                ? round(($stats->places / $stats->completed_races) * 100, 2)
                : 0,
        ];
    }
}