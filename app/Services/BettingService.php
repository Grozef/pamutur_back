<?php

namespace App\Services;

use App\Models\DailyBet;
use App\Models\ValueBet;
use App\Models\BetCombination;
use App\Models\RaceResult;
use Carbon\Carbon;

class BettingService
{
    protected $pmuResultsService;

    public function __construct(PMUResultsService $pmuResultsService)
    {
        $this->pmuResultsService = $pmuResultsService;
    }
    /**
     * Process daily predictions and store bets
     */
    public function processDailyPredictions(string $date, array $predictions): array
    {
        $stats = [
            'date' => $date,
            'daily_bets_stored' => 0,
            'value_bets_stored' => 0,
            'combinations_created' => 0
        ];

        // Store bets with probability > 40%
        $stats['daily_bets_stored'] = DailyBet::storeDailyBets($date, $predictions);

        // Store top 20 value bets
        $stats['value_bets_stored'] = ValueBet::storeValueBets($date, $predictions);

        // Generate combinations
        $stats['combinations_created'] = BetCombination::generateCombinations($date);

        return $stats;
    }

    /**
     * Fetch race results for previous day
     */
    public function fetchPreviousDayResults(): array
    {
        $yesterday = Carbon::yesterday()->format('Y-m-d');
        
        // Fetch results from PMU API
        $pmuResults = $this->pmuResultsService->fetchRaceResults($yesterday);
        
        $results = [];
        foreach ($pmuResults as $result) {
            // Find corresponding race in our database
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
     * Generate daily report
     */
    public function generateDailyReport(string $date): array
    {
        $report = [
            'date' => $date,
            'summary' => [],
            'daily_bets' => [],
            'value_bets' => [],
            'combinations' => [],
            'results' => [],
            'performance' => []
        ];

        // Get race results
        $results = RaceResult::getResultsForDate($date);
        
        // Get all bets for the date
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

        // Calculate performance
        $performance = $this->calculatePerformance($dailyBets, $valueBets, $combinations, $results);

        // Build report
        $report['summary'] = [
            'total_daily_bets' => $dailyBets->count(),
            'total_value_bets' => $valueBets->count(),
            'total_combinations' => $combinations->count(),
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
     * Calculate performance metrics
     */
    private function calculatePerformance($dailyBets, $valueBets, $combinations, $results): array
    {
        $winningBets = 0;
        $totalInvested = 0;
        $totalReturns = 0;

        $resultsMap = $results->keyBy('race_id');

        // Check daily bets
        foreach ($dailyBets as $bet) {
            $totalInvested += 1; // Assuming 1 unit per bet
            
            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                
                if ($result->didHorseWin($bet->horse_id)) {
                    $winningBets++;
                    $payout = $result->getPayout('simple_gagnant');
                    if ($payout) {
                        $totalReturns += $payout;
                    }
                }
            }
        }

        // Check value bets
        foreach ($valueBets as $bet) {
            $totalInvested += 1;
            
            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                
                if ($result->didHorseWin($bet->horse_id)) {
                    $winningBets++;
                    $payout = $result->getPayout('simple_gagnant');
                    if ($payout) {
                        $totalReturns += $payout;
                    }
                }
            }
        }

        // Check combinations
        foreach ($combinations as $combination) {
            $totalInvested += 1;
            
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
                            $totalReturns += $payout;
                        }
                    }
                } elseif ($combination->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    
                    if (empty(array_diff($betHorses, $top3))) {
                        $winningBets++;
                        $payout = $result->getPayout('trio');
                        if ($payout) {
                            $totalReturns += $payout;
                        }
                    }
                }
            }
        }

        $netProfit = $totalReturns - $totalInvested;
        $roi = $totalInvested > 0 ? ($netProfit / $totalInvested) * 100 : 0;

        return [
            'winning_bets' => $winningBets,
            'total_invested' => $totalInvested,
            'total_returns' => $totalReturns,
            'net_profit' => $netProfit,
            'roi' => round($roi, 2)
        ];
    }

    /**
     * Format bets report
     */
    private function formatBetsReport($bets, $results)
    {
        $resultsMap = $results->keyBy('race_id');
        
        return $bets->map(function($bet) use ($resultsMap) {
            $won = false;
            $payout = null;
            
            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                $won = $result->didHorseWin($bet->horse_id);
                $payout = $won ? $result->getPayout('simple_gagnant') : null;
            }
            
            return [
                'id' => $bet->id,
                'race_id' => $bet->race_id,
                'horse_id' => $bet->horse_id,
                'horse_name' => $bet->horse_name,
                'probability' => $bet->probability,
                'odds' => $bet->odds,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    /**
     * Format value bets report
     */
    private function formatValueBetsReport($bets, $results)
    {
        $resultsMap = $results->keyBy('race_id');
        
        return $bets->map(function($bet) use ($resultsMap) {
            $won = false;
            $payout = null;
            
            if ($resultsMap->has($bet->race_id)) {
                $result = $resultsMap->get($bet->race_id);
                $won = $result->didHorseWin($bet->horse_id);
                $payout = $won ? $result->getPayout('simple_gagnant') : null;
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
                'won' => $won,
                'payout' => $payout
            ];
        });
    }

    /**
     * Format combinations report
     */
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
                    $payout = $won ? $result->getPayout('couple') : null;
                } elseif ($combo->combination_type === 'TRIO') {
                    $top3 = collect($result->getTop3())->pluck('horse_id')->all();
                    $betHorses = collect($horses)->pluck('horse_id')->all();
                    $won = empty(array_diff($betHorses, $top3));
                    $payout = $won ? $result->getPayout('trio') : null;
                }
            }
            
            return [
                'id' => $combo->id,
                'race_id' => $combo->race_id,
                'combination_type' => $combo->combination_type,
                'horses' => $combo->horses,
                'combined_probability' => $combo->combined_probability,
                'won' => $won,
                'payout' => $payout
            ];
        });
    }
}
