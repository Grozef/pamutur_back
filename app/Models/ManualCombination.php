<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualCombination extends Model
{
    protected $fillable = [
        'bet_date',
        'race_id',
        'reunion_number',
        'course_number',
        'combination_type',
        'horses',
        'amount',
        'metadata',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'horses' => 'array',
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'is_processed' => 'boolean'
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(Race::class);
    }

    /**
     * Add a manual combination (COUPLE or TRIO)
     *
     * @param array $data {
     *   bet_date: string,
     *   race_id: int,
     *   reunion_number: int,
     *   course_number: int,
     *   combination_type: 'COUPLE'|'TRIO',
     *   horses: [
     *     {horse_id: string, horse_name: string},
     *     {horse_id: string, horse_name: string},
     *     ...
     *   ],
     *   amount: float (default 10)
     * }
     */
    public static function addCombination(array $data): self
    {
        // Validate combination type
        $type = strtoupper($data['combination_type']);
        if (!in_array($type, ['COUPLE', 'TRIO'])) {
            throw new \InvalidArgumentException("Invalid combination type: {$type}");
        }

        // Validate horses count
        $horsesCount = count($data['horses']);
        if ($type === 'COUPLE' && $horsesCount !== 2) {
            throw new \InvalidArgumentException("COUPLE requires exactly 2 horses, got {$horsesCount}");
        }
        if ($type === 'TRIO' && $horsesCount !== 3) {
            throw new \InvalidArgumentException("TRIO requires exactly 3 horses, got {$horsesCount}");
        }

        return self::create([
            'bet_date' => $data['bet_date'],
            'race_id' => $data['race_id'],
            'reunion_number' => $data['reunion_number'],
            'course_number' => $data['course_number'],
            'combination_type' => $type,
            'horses' => $data['horses'],
            'amount' => $data['amount'] ?? 10,
            'metadata' => $data['metadata'] ?? null
        ]);
    }

    /**
     * Get manual combinations for a date
     */
    public static function getManualCombinations(string $date)
    {
        return self::where('bet_date', $date)
            ->with('race')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get manual combinations for a date and type
     */
    public static function getByType(string $date, string $type)
    {
        return self::where('bet_date', $date)
            ->where('combination_type', strtoupper($type))
            ->with('race')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get total amount for a date
     */
    public static function getTotalAmount(string $date): float
    {
        return self::where('bet_date', $date)
            ->sum('amount');
    }

    /**
     * Delete a manual combination
     */
    public static function deleteCombination(int $id): bool
    {
        return self::destroy($id) > 0;
    }

    /**
     * Get horses names as comma-separated string
     */
    public function getHorsesNamesAttribute(): string
    {
        if (!$this->horses) {
            return '';
        }

        return collect($this->horses)
            ->pluck('horse_name')
            ->join(', ');
    }

    /**
     * Get formatted combination info
     */
    public function getFormattedInfoAttribute(): string
    {
        $type = $this->combination_type === 'COUPLE' ? 'Couplé' : 'Trio';
        $race = "R{$this->reunion_number}C{$this->course_number}";
        return "{$type} - {$race} - {$this->horses_names} - {$this->amount}€";
    }
}