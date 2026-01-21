<?php

namespace App\Jobs;

use App\Services\PMUFetcherService;
use App\Services\PMUStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchPMUDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300; // 5 minutes
    public $backoff = [60, 180, 300]; // Retry after 1min, 3min, 5min

    private string $date;
    private ?int $reunionNum;
    private ?int $courseNum;

    /**
     * Create a new job instance.
     */
    public function __construct(string $date, ?int $reunionNum = null, ?int $courseNum = null)
    {
        $this->date = $date;
        $this->reunionNum = $reunionNum;
        $this->courseNum = $courseNum;
    }

    /**
     * Execute the job.
     */
    public function handle(PMUFetcherService $fetcher, PMUStorageService $storage): void
    {
        $startTime = microtime(true);

        Log::info('FetchPMUDataJob started', [
            'date' => $this->date,
            'reunion' => $this->reunionNum,
            'course' => $this->courseNum,
            'attempt' => $this->attempts()
        ]);

        try {
            // Specific course fetch
            if ($this->reunionNum !== null && $this->courseNum !== null) {
                $this->fetchSpecificCourse($fetcher, $storage);
            }
            // Specific reunion fetch
            elseif ($this->reunionNum !== null) {
                $this->fetchReunion($fetcher, $storage);
            }
            // Full day programme
            else {
                $this->fetchFullProgramme($fetcher, $storage);
            }

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('FetchPMUDataJob completed', [
                'date' => $this->date,
                'duration_ms' => round($duration, 2)
            ]);

        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::error('FetchPMUDataJob failed', [
                'date' => $this->date,
                'reunion' => $this->reunionNum,
                'course' => $this->courseNum,
                'error' => $e->getMessage(),
                'duration_ms' => round($duration, 2),
                'attempt' => $this->attempts()
            ]);

            // Retry if not last attempt
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Fetch full programme for a date
     */
    private function fetchFullProgramme(PMUFetcherService $fetcher, PMUStorageService $storage): void
    {
        $programme = $fetcher->fetchProgramme($this->date);

        if (!$programme) {
            throw new \Exception("Failed to fetch programme for {$this->date}");
        }

        if (!isset($programme['programme']['reunions'])) {
            throw new \Exception("No reunions found in programme");
        }

        $reunions = $programme['programme']['reunions'];
        $coursesProcessed = 0;

        foreach ($reunions as $reunion) {
            $reunionNum = $reunion['numOfficiel'];

            if (isset($reunion['courses'])) {
                foreach ($reunion['courses'] as $course) {
                    $courseNum = $course['numOrdre'];

                    if ($this->fetchAndStoreCourse($fetcher, $storage, $reunionNum, $courseNum)) {
                        $coursesProcessed++;
                    }
                }
            }
        }

        Log::info("Processed {$coursesProcessed} courses for date {$this->date}");
    }

    /**
     * Fetch all courses in a reunion
     */
    private function fetchReunion(PMUFetcherService $fetcher, PMUStorageService $storage): void
    {
        $reunionData = $fetcher->fetchReunion($this->date, $this->reunionNum);

        if (!$reunionData) {
            throw new \Exception("Failed to fetch reunion R{$this->reunionNum}");
        }

        if (isset($reunionData['courses'])) {
            foreach ($reunionData['courses'] as $course) {
                $courseNum = $course['numOrdre'];
                $this->fetchAndStoreCourse($fetcher, $storage, $this->reunionNum, $courseNum);
            }
        }
    }

    /**
     * Fetch specific course
     */
    private function fetchSpecificCourse(PMUFetcherService $fetcher, PMUStorageService $storage): void
    {
        if (!$this->fetchAndStoreCourse($fetcher, $storage, $this->reunionNum, $this->courseNum)) {
            throw new \Exception("Failed to fetch course R{$this->reunionNum}C{$this->courseNum}");
        }
    }

    /**
     * Fetch and store a single course
     */
    private function fetchAndStoreCourse(
        PMUFetcherService $fetcher,
        PMUStorageService $storage,
        int $reunionNum,
        int $courseNum
    ): bool {
        try {
            $courseData = $fetcher->fetchCourse($this->date, $reunionNum, $courseNum);

            if (!$courseData) {
                Log::warning("No data for R{$reunionNum}C{$courseNum}");
                return false;
            }

            $race = $storage->storeRaceData($courseData, $this->date, $reunionNum, $courseNum);

            if (!$race) {
                Log::warning("Failed to store R{$reunionNum}C{$courseNum}");
                return false;
            }

            return true;

        } catch (\Exception $e) {
            Log::error("Error processing R{$reunionNum}C{$courseNum}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('FetchPMUDataJob permanently failed', [
            'date' => $this->date,
            'reunion' => $this->reunionNum,
            'course' => $this->courseNum,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}