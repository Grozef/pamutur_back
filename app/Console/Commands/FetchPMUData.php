<?php

namespace App\Console\Commands;

use App\Jobs\FetchPMUDataJob;
use Illuminate\Console\Command;

class FetchPMUData extends Command
{
    protected $signature = 'pmu:fetch
                            {date? : Date in dmY format}
                            {--reunion= : Specific reunion number}
                            {--course= : Specific course number}
                            {--sync : Run synchronously without queue}';

    protected $description = 'Fetch PMU race data and store in database (queued by default)';

    public function handle(): int
    {
        $date = $this->argument('date') ?? date('dmY');
        $reunionNum = $this->option('reunion') ? (int)$this->option('reunion') : null;
        $courseNum = $this->option('course') ? (int)$this->option('course') : null;
        $sync = $this->option('sync');

        $this->info("Fetching PMU data for date: {$date}");

        if ($reunionNum && $courseNum) {
            $this->info("Specific course: R{$reunionNum}C{$courseNum}");
        } elseif ($reunionNum) {
            $this->info("Specific reunion: R{$reunionNum}");
        } else {
            $this->info("Full day programme");
        }

        // Dispatch job
        $job = new FetchPMUDataJob($date, $reunionNum, $courseNum);

        if ($sync) {
            $this->warn("Running synchronously (not queued)");
            $job->handle(
                app(\App\Services\PMUFetcherService::class),
                app(\App\Services\PMUStorageService::class)
            );
            $this->info("✓ Data fetch completed");
        } else {
            dispatch($job);
            $this->info("✓ Job dispatched to queue");
            $this->info("Monitor with: php artisan queue:work");
        }

        return Command::SUCCESS;
    }
}