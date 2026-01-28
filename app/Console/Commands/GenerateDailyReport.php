<?php

namespace App\Console\Commands;

use App\Services\BettingService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class GenerateDailyReport extends Command
{
    protected $signature = 'betting:generate-report {date?}';
    protected $description = 'Generate daily betting report';

    public function handle()
    {
        $date = $this->argument('date') ?? Carbon::yesterday()->format('Y-m-d');
        
        $this->info("Generating report for {$date}...");
        
        $bettingService = new BettingService();
        $report = $bettingService->generateDailyReport($date);
        
        // Display summary
        $this->info("\n=== BETTING REPORT FOR {$date} ===\n");
        $this->info("Total Daily Bets: {$report['summary']['total_daily_bets']}");
        $this->info("Total Value Bets: {$report['summary']['total_value_bets']}");
        $this->info("Total Combinations: {$report['summary']['total_combinations']}");
        $this->info("Races with Results: {$report['summary']['total_races_with_results']}");
        $this->info("\n=== PERFORMANCE ===\n");
        $this->info("Winning Bets: {$report['summary']['winning_bets']}");
        $this->info("Total Invested: {$report['summary']['total_invested']} units");
        $this->info("Total Returns: {$report['summary']['total_returns']} units");
        $this->info("Net Profit: {$report['summary']['net_profit']} units");
        $this->info("ROI: {$report['summary']['roi']}%");
        
        // Save report to file
        $reportPath = storage_path("app/reports/betting_report_{$date}.json");
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->info("\nReport saved to: {$reportPath}");
        
        return Command::SUCCESS;
    }
}
