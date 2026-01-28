<?php

namespace App\Console\Commands;

use App\Services\BettingService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class FetchPreviousDayResults extends Command
{
    protected $signature = 'betting:fetch-results {date?}';
    protected $description = 'Fetch race results for previous day';

    public function handle()
    {
        $date = $this->argument('date') ?? Carbon::yesterday()->format('Y-m-d');
        
        $this->info("Fetching race results for {$date}...");
        
        $bettingService = new BettingService();
        $results = $bettingService->fetchPreviousDayResults();
        
        $this->info("Fetched " . count($results) . " race results");
        
        // Mark bets as processed
        \App\Models\DailyBet::where('bet_date', $date)->update(['is_processed' => true]);
        \App\Models\ValueBet::where('bet_date', $date)->update(['is_processed' => true]);
        \App\Models\BetCombination::where('bet_date', $date)->update(['is_processed' => true]);
        
        $this->info("Results fetched and bets marked as processed");
        
        return Command::SUCCESS;
    }
}
