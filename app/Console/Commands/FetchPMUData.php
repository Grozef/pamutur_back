<?php

namespace App\Console\Commands;

use App\Services\PMUFetcherService;
use App\Services\PMUStorageService;
use Illuminate\Console\Command;

class FetchPMUData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pmu:fetch {date?} {--reunion= : Specific reunion number} {--course= : Specific course number}';

    /**
     * The console command description.
     */
    protected $description = 'Fetch PMU race data and store in database';

    private PMUFetcherService $fetcher;
    private PMUStorageService $storage;

    public function __construct(PMUFetcherService $fetcher, PMUStorageService $storage)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
        $this->storage = $storage;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->argument('date') ?? $this->fetcher->getTodayDate();

        $this->info("Fetching PMU data for date: {$date}");

        // If specific reunion/course specified
        if ($this->option('reunion') && $this->option('course')) {
            return $this->fetchSpecificCourse(
                $date,
                (int)$this->option('reunion'),
                (int)$this->option('course')
            );
        }

        // Fetch entire day program
        $programme = $this->fetcher->fetchProgramme($date);

        if (!$programme) {
            $this->error("Failed to fetch programme for {$date}");
            return Command::FAILURE;
        }

        $this->info("Programme fetched successfully");

        // Parse reunions
        if (!isset($programme['programme']['reunions'])) {
            $this->error("No reunions found in programme");
            return Command::FAILURE;
        }

        $reunions = $programme['programme']['reunions'];
        // dd($reunions);
        $progressBar = $this->output->createProgressBar(count($reunions));
        $progressBar->start();

        foreach ($reunions as $reunion) {
            $reunionNum = $reunion['numOfficiel'];

            if (isset($reunion['courses'])) {
                foreach ($reunion['courses'] as $course) {
                    $courseNum = $course['numOrdre'];
                    $this->fetchAndStoreCourse($date, $reunionNum, $courseNum);
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->info("✓ Data fetch completed successfully");

        return Command::SUCCESS;
    }

    /**
     * Fetch specific course
     */
    private function fetchSpecificCourse(string $date, int $reunionNum, int $courseNum): int
    {
        $this->info("Fetching R{$reunionNum}C{$courseNum}");

        if ($this->fetchAndStoreCourse($date, $reunionNum, $courseNum)) {
            $this->info("✓ Course stored successfully");
            return Command::SUCCESS;
        }

        $this->error("Failed to fetch course");
        return Command::FAILURE;
    }

    /**
     * Fetch and store a single course
     */
    private function fetchAndStoreCourse(string $date, int $reunionNum, int $courseNum): bool
    {
        $courseData = $this->fetcher->fetchCourse($date, $reunionNum, $courseNum);

        if (!$courseData) {
            return false;
        }

        $race = $this->storage->storeRaceData($courseData, $date, $reunionNum, $courseNum);

        return $race !== null;
    }
}
