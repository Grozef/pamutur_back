<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Race extends Model
{
    protected $fillable = [
        'race_date',
        'hippodrome',
        'distance',
        'discipline',
        'track_condition',
        'race_code'
    ];

    protected $casts = [
        'race_date' => 'datetime'
    ];

    /**
     * Get performances for this race
     */
    public function performances(): HasMany
    {
        return $this->hasMany(Performance::class);
    }

    /**
     * Get participants count
     */
    public function getParticipantsCount(): int
    {
        return $this->performances()->count();
    }
}
