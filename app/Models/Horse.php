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
     * FIXED: Calculate average offspring win rate efficiently with single query
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
     * Get horse career statistics
     */
    public function getCareerStats(): array
    {
        $performances = $this->performances;

        $totalRaces = $performances->count();
        $completedRaces = $performances->whereNotNull('rank')->count();
        $wins = $performances->where('rank', 1)->count();
        $places = $performances->whereIn('rank', [1, 2, 3])->count();
        $totalGains = $performances->sum('gains_race');

        return [
            'total_races' => $totalRaces,
            'completed_races' => $completedRaces,
            'wins' => $wins,
            'places' => $places,
            'total_gains' => $totalGains,
            'average_gains' => $totalRaces > 0 ? $totalGains / $totalRaces : 0,
            'win_rate' => $completedRaces > 0 ? ($wins / $completedRaces) * 100 : 0,
            'place_rate' => $completedRaces > 0 ? ($places / $completedRaces) * 100 : 0,
        ];
    }
}