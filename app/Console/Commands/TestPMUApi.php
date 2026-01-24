<?php

namespace App\Console\Commands;

use App\Services\PMUFetcherService;
use Illuminate\Console\Command;

class TestPMUApi extends Command
{
    protected $signature = 'pmu:test {--date= : Date in dmY format}';
    protected $description = 'Test PMU API connectivity and show working endpoints';

    public function handle(PMUFetcherService $fetcher): int
    {
        $this->info('Testing PMU API connectivity...');
        $this->newLine();

        // Test all URLs
        $results = $fetcher->testConnectivity();

        $this->table(
            ['URL', 'Status', 'Success', 'Has Data'],
            collect($results)->map(function ($result, $url) {
                return [
                    $url,
                    $result['status'] ?? 'N/A',
                    $result['success'] ? '✓' : '✗',
                    ($result['has_data'] ?? false) ? '✓' : '✗'
                ];
            })->toArray()
        );

        $this->newLine();

        // Find working URL
        $workingUrl = collect($results)->filter(fn($r) => $r['success'] ?? false)->keys()->first();

        if ($workingUrl) {
            $this->info("✓ Working URL found: {$workingUrl}");
            
            // Try to fetch programme
            $date = $this->option('date') ?? $fetcher->getTodayDate();
            $this->info("Fetching programme for date: {$date}");

            $programme = $fetcher->fetchProgramme($date);

            if ($programme) {
                $reunions = $programme['programme']['reunions'] ?? [];
                $this->info("✓ Programme loaded: " . count($reunions) . " reunions");

                foreach (array_slice($reunions, 0, 3) as $reunion) {
                    $numOfficiel = $reunion['numOfficiel'] ?? '?';
                    $hippodrome = $reunion['hippodrome']['libelleCourt'] ?? 'Unknown';
                    $courses = count($reunion['courses'] ?? []);
                    $this->line("  R{$numOfficiel}: {$hippodrome} - {$courses} courses");
                }

                if (count($reunions) > 3) {
                    $this->line("  ... and " . (count($reunions) - 3) . " more reunions");
                }
            } else {
                $this->error("✗ Failed to fetch programme");
            }
        } else {
            $this->error("✗ No working PMU API URL found!");
            $this->error("Check your network connection or firewall settings.");
            return Command::FAILURE;
        }

        $this->newLine();
        $this->info("Current working URL: " . $fetcher->getCurrentBaseUrl());

        return Command::SUCCESS;
    }
}
