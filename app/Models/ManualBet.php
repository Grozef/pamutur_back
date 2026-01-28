<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualBet extends Model
{
    protected $fillable = [
        'bet_date',
        'horse_id',
        'horse_name',
        'amount',
        'bet_type',
        'probability',
        'odds',
        'metadata',
        'is_processed'
    ];

    protected $casts = [
        'bet_date' => 'date',
        'amount' => 'decimal:2',
        'probability' => 'float',
        'odds' => 'float',
        'metadata' => 'array',
        'is_processed' => 'boolean'
    ];

    /**
     * Add a manual bet - SIMPLE
     */
    public static function addBet(array $data): self
    {
        return self::create([
            'bet_date' => $data['bet_date'],
            'horse_id' => $data['horse_id'],
            'horse_name' => $data['horse_name'],
            'amount' => $data['amount'],
            'bet_type' => $data['bet_type'] ?? 'SIMPLE',
            'probability' => $data['probability'] ?? null,
            'odds' => $data['odds'] ?? null,
            'metadata' => $data['metadata'] ?? null
        ]);
    }

    /**
     * Get manual bets for a date
     */
    public static function getManualBets(string $date)
    {
        return self::where('bet_date', $date)
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
     * Delete a manual bet
     */
    public static function deleteBet(int $id): bool
    {
        return self::destroy($id) > 0;
    }
}