<?php

namespace App\Services;

use App\Models\DailyBet;
use App\Models\ValueBet;
use App\Models\BetCombination;
use App\Models\ManualBet;
use App\Models\ManualCombination;
use App\Models\RaceResult;
use Carbon\Carbon;

class BettingService
{
    protected $pmuResultsService;

    public function __construct(PMUResultsService $pmuResultsService)
    {
        $this->pmuResultsService = $pmuResultsService;
    }

    public function processDailyPredictions(string $date, array $predictions): array
    {
        $stats = [
            'date' => $date,
            'daily_bets_stored' => 0,
            'value_bets_stored' => 0,
            'combinations_created' => 0
        ];

        $stats['daily_bets_stored'] = DailyBet::storeDailyBets($date, $predictions);
        $stats['value_bets_stored'] = ValueBet::storeValueBets($date, $predictions);
        $stats['combinations_created'] = BetCombination::generateCombinations($date);

        return $stats;
    }

    public function fetchPreviousDayResults(): array
    {
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        $pmuResults = $this->pmuResultsService->fetchRaceResults($yesterday);

        $results = [];
        foreach ($pmuResults as $result) {
            $raceId = $this->pmuResultsService->findRaceId(
                $result['race_date'],
                $result['hippodrome'],
                $result['race_number']
            );

            if ($raceId) {
                $result['race_id'] = $raceId;
                $raceResult = RaceResult::storeRaceResult($result);
                if ($raceResult) {
                    $results[] = $raceResult;
                }
            }
        }

        return $results;
    }

    /**
     * Generate daily report - COMPLET avec tous les types de paris
     */
    public function generateDailyReport(string $date): array
    {
        $report = [
            'date' => $date,
            'summary' => [],
            'daily_bets' => [],
            'value_bets' => [],
            'combinations' => [],
            'manual_bets' => [],
            'manual_combinations' => [],
            'results' => [],
            'performance' => []
        ];

        $results = RaceResult::getResultsForDate($date);

        // Tous les types de paris
        $dailyBets = DailyBet::where('bet_date', $date)
            ->with(['race', 'horse'])
            ->get();

        $valueBets = ValueBet::where('bet_date', $date)
            ->orderBy('ranking')
            ->with(['race', 'horse'])
            ->get();

        $combinations = BetCombination::where('bet_date', $date)
            ->orderByDesc('combined_probability')
            ->with('race')
            ->get();

        $manualBets = ManualBet::where('bet_date', $date)
            ->with('horse')
            ->get();

        $manualCombinations = ManualCombination::where('bet_date', $date)
            ->with('race')
            ->get();

        // Calculate performance avec TOUS les paris
        $performance = $this->calculatePerformance(
            $dailyBets,
            $valueBets,
            $combinations,
            $manualBets,
            $manualCombinations,
            $results
        );

        $report['summary'] = [
            'total_daily_bets' => $dailyBets->count(),
            'total_value_bets' => $valueBets->count(),
            'total_combinations' => $combinations->count(),
            'total_manual_bets' => $manualBets->count(),
            'total_manual_combinations' => $manualCombinations->count(),
            'total_all_bets' => $dailyBets->count() + $valueBets->count() + $combinations->count() + $manualBets->count() + $manualCombinations->count(),
            'total_races_with_results' => $results->count(),
            'winning_bets' => $performance['winning_bets'],
            'total_invested' => $performance['total_invested'],
            'total_returns' => $performance['total_returns'],
            'net_profit' => $performance['net_profit'],
            'roi' => $performance['roi']
        ];

        $report['daily_bets'] = $this->formatBetsReport($dailyBets, $results);
        $report['value_bets'] = $this->formatValueBetsReport($valueBets, $results);
        $report['combinations'] = $this->formatCombinationsReport($combinations, $results);
        $report['manual_bets'] = $this->formatManualBetsReport($manualBets, $results);
        $report['manual_combinations'] = $this->formatManualCombinationsReport($manualCombinations, $results);

        $report['results'] = $results->map(function($result) {
            return [
                'race_id' => $result->race_id,
                'hippodrome' => $result->hippodrome,
                'race_number' => $result->race_number,
                'winner' => $result->getWinner(),
                'top_3' => $result->getTop3(),
                'rapports' => $result->rapports
            ];
        });

        $report['performance'] = $performance;

        return $report;
    }

    /**
     * Calculate performance - COMPLET avec tous les paris
     */
    private function calculatePerformance($dailyBets, $valueBets, $combinations, $manualBets, $manualCombinations, $results): array
    {
        $winningBets = 0;
        $totalInvested = 0;
        $totalReturns = 0;

        $resultsMap = $results->keyBy('race_id');

        // Daily bets - 10€ fixe
        foreach ($dailyBets as $bet) {
            $totalInvested += 10;

            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);

                if ($result->didHorseWin($bet->horse_id)) {
                    $winningBets++;
                    $payout = $result->getPayout('simple_gagnant');
                    if ($payout) {
                        $totalReturns += ($payout * 10);
                    }
                }
            }
        }

        // Value bets - 10€ fixe
        foreach ($valueBets as $bet) {
            $totalInvested += 10;

            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);

                if ($result->didHorseWin($bet->horse_id)) {
                    $winningBets++;
                    $payout = $result->getPayout('simple_gagnant');
                    if ($payout) {
                        $totalReturns += ($payout * 10);
                    }
                }
            }
        }

        // Combinations auto - 10€ fixe
        foreach ($combinations as $combination) {
            $totalInvested += 10;

            if ($resultsMap->has($combination->race_id)) {
                $result = $resultsMap->get($combination->race_id);
                $horses = $combination->horses;

                if ($combination->combination_type === 'COUPLE') {
                    $top2 = collect($result->getTop3())->take(2)->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();

                    if (empty(array_diff($betHorses, $top2))) {
                        $winningBets++;
                        $payout = $result->getPayout('couple');
                        if ($payout) {
                            $totalReturns += ($payout * 10);
                        }
                    }
                } elseif ($combination->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();

                    if (empty(array_diff($betHorses, $top3))) {
                        $winningBets++;
                        $payout = $result->getPayout('trio');
                        if ($payout) {
                            $totalReturns += ($payout * 10);
                        }
                    }
                }
            }
        }

        // Manual bets - montant VARIABLE
        foreach ($manualBets as $bet) {
            $amount = floatval($bet->amount);
            $totalInvested += $amount;

            if (isset($bet->metadata['race_id']) && $resultsMap->has($bet->metadata['race_id'])) {
                $result = $resultsMap->get($bet->metadata['race_id']);

                if ($result->didHorseWin($bet->horse_id)) {
                    $winningBets++;
                    $payout = $result->getPayout('simple_gagnant');
                    if ($payout) {
                        $totalReturns += ($payout * $amount);
                    }
                }
            }
        }

        // Manual combinations - montant VARIABLE
        foreach ($manualCombinations as $combo) {
            $amount = floatval($combo->amount);
            $totalInvested += $amount;

            if ($resultsMap->has($combo->race_id)) {
                $result = $resultsMap->get($combo->race_id);
                $horses = $combo->horses;

                if ($combo->combination_type === 'COUPLE') {
                    $top2 = collect($result->getTop3())->take(2)->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();

                    if (empty(array_diff($betHorses, $top2))) {
                        $winningBets++;
                        $payout = $result->getPayout('couple');
                        if ($payout) {
                            $totalReturns += ($payout * $amount);
                        }
                    }
                } elseif ($combo->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();

                    if (empty(array_diff($betHorses, $top3))) {
                        $winningBets++;
                        $payout = $result->getPayout('trio');
                        if ($payout) {
                            $totalReturns += ($payout * $amount);
                        }
                    }
                }
            }
        }

        $netProfit = $totalReturns - $totalInvested;
        $roi = $totalInvested > 0 ? round(($netProfit / $totalInvested) * 100, 2) : 0;

        return [
            'winning_bets' => $winningBets,
            'total_invested' => $totalInvested,
            'total_returns' => $totalReturns,
            'net_profit' => $netProfit,
            'roi' => $roi
        ];
    }

    private function formatBetsReport($bets, $results)
    {
        $resultsMap = $results->keyBy('race_id');

        return $bets->map(function($bet) use ($resultsMap) {
            $won = false;
            $payout = null;

            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                $won = $result->didHorseWin($bet->horse_id);
                if ($won) {
                    $basePayout = $result->getPayout('simple_gagnant');
                    $payout = $basePayout ? ($basePayout * 10) : null;
                }
            }

            return [
                'id' => $bet->id,
                'race_id' => $bet->race_id,
                'horse_id' => $bet->horse_id,
                'horse_name' => $bet->horse_name,
                'probability' => $bet->probability,
                'odds' => $bet->odds,
                'amount' => 10,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    private function formatValueBetsReport($bets, $results)
    {
        $resultsMap = $results->keyBy('race_id');

        return $bets->map(function($bet) use ($resultsMap) {
            $won = false;
            $payout = null;

            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                $won = $result->didHorseWin($bet->horse_id);
                if ($won) {
                    $basePayout = $result->getPayout('simple_gagnant');
                    $payout = $basePayout ? ($basePayout * 10) : null;
                }
            }

            return [
                'id' => $bet->id,
                'ranking' => $bet->ranking,
                'race_id' => $bet->race_id,
                'horse_id' => $bet->horse_id,
                'horse_name' => $bet->horse_name,
                'estimated_probability' => $bet->estimated_probability,
                'offered_odds' => $bet->offered_odds,
                'value_score' => $bet->value_score,
                'amount' => 10,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    private function formatCombinationsReport($combinations, $results)
    {
        $resultsMap = $results->keyBy('race_id');

        return $combinations->map(function($combo) use ($resultsMap) {
            $won = false;
            $payout = null;

            if ($resultsMap->has($combo->race_id)) {
                $result = $resultsMap->get($combo->race_id);
                $horses = $combo->horses;

                if ($combo->combination_type === 'COUPLE') {
                    $top2 = collect($result->getTop3())->take(2)->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    $won = empty(array_diff($betHorses, $top2));
                    if ($won) {
                        $basePayout = $result->getPayout('couple');
                        $payout = $basePayout ? ($basePayout * 10) : null;
                    }
                } elseif ($combo->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    $won = empty(array_diff($betHorses, $top3));
                    if ($won) {
                        $basePayout = $result->getPayout('trio');
                        $payout = $basePayout ? ($basePayout * 10) : null;
                    }
                }
            }

            return [
                'id' => $combo->id,
                'race_id' => $combo->race_id,
                'combination_type' => $combo->combination_type,
                'horses' => $combo->horses,
                'combined_probability' => $combo->combined_probability,
                'amount' => 10,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    private function formatManualBetsReport($bets, $results)
    {
        $resultsMap = $results->keyBy(function($result) {
            return $result->race_id;
        });

        return $bets->map(function($bet) use ($resultsMap) {
            $won = false;
            $payout = null;
            $raceId = $bet->metadata['race_id'] ?? null;

            if ($raceId && $resultsMap->has($raceId)) {
                $result = $resultsMap->get($raceId);
                $won = $result->didHorseWin($bet->horse_id);
                if ($won) {
                    $basePayout = $result->getPayout('simple_gagnant');
                    $payout = $basePayout ? ($basePayout * floatval($bet->amount)) : null;
                }
            }

            return [
                'id' => $bet->id,
                'race_id' => $raceId,
                'horse_id' => $bet->horse_id,
                'horse_name' => $bet->horse_name,
                'bet_type' => $bet->bet_type,
                'probability' => $bet->probability,
                'odds' => $bet->odds,
                'amount' => floatval($bet->amount),
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    private function formatManualCombinationsReport($combinations, $results)
    {
        $resultsMap = $results->keyBy('race_id');

        return $combinations->map(function($combo) use ($resultsMap) {
            $won = false;
            $payout = null;
            $amount = floatval($combo->amount);

            if ($resultsMap->has($combo->race_id)) {
                $result = $resultsMap->get($combo->race_id);
                $horses = $combo->horses;

                if ($combo->combination_type === 'COUPLE') {
                    $top2 = collect($result->getTop3())->take(2)->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    $won = empty(array_diff($betHorses, $top2));
                    if ($won) {
                        $basePayout = $result->getPayout('couple');
                        $payout = $basePayout ? ($basePayout * $amount) : null;
                    }
                } elseif ($combo->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    $won = empty(array_diff($betHorses, $top3));
                    if ($won) {
                        $basePayout = $result->getPayout('trio');
                        $payout = $basePayout ? ($basePayout * $amount) : null;
                    }
                }
            }
            
            return [
                'id' => $combo->id,
                'race_id' => $combo->race_id,
                'reunion_number' => $combo->reunion_number,
                'course_number' => $combo->course_number,
                'combination_type' => $combo->combination_type,
                'horses' => $combo->horses,
                'amount' => $amount,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }
}