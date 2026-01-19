<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Performance extends Model
{
    protected $fillable = [
        'horse_id',
        'race_id',
        'jockey_id',
        'trainer_id',
        'rank',
        'weight',
        'draw',
        'raw_musique',
        'odds_ref',
        'gains_race'
    ];

    /**
     * Get the horse
     */
    public function horse(): BelongsTo
    {
        return $this->belongsTo(Horse::class, 'horse_id', 'id_cheval_pmu');
    }

    /**
     * Get the race
     */
    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * Get the jockey
     */
    public function jockey(): BelongsTo
    {
        return $this->belongsTo(Jockey::class);
    }

    /**
     * Get the trainer
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
