<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PMUFetcherService
{
    private const BASE_URL = 'https://online.turfinfo.api.pmu.fr/rest/client/1';
    
    /**
     * Fetch daily program for a specific date
     */
    public function fetchProgramme(string $date): ?array
    {
        try {
            $response = Http::timeout(30)->get(self::BASE_URL . "/programme/{$date}");
            
            if ($response->successful()) {
                return $response->json();
            }
            
            Log::error("PMU API Error: {$response->status()}", [
                'date' => $date,
                'body' => $response->body()
            ]);
            
            return null;
        } catch (\Exception $e) {
            Log::error("PMU Fetch Exception: {$e->getMessage()}", [
                'date' => $date
            ]);
            return null;
        }
    }

    /**
     * Fetch reunion data
     */
    public function fetchReunion(string $date, int $reunionNum): ?array
    {
        try {
            $response = Http::timeout(30)->get(
                self::BASE_URL . "/programme/{$date}/R{$reunionNum}"
            );
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("PMU Reunion Fetch Exception: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch course data with participants
     */
    public function fetchCourse(string $date, int $reunionNum, int $courseNum): ?array
    {
        try {
            $response = Http::timeout(30)->get(
                self::BASE_URL . "/programme/{$date}/R{$reunionNum}/C{$courseNum}/participants"
            );
            
            if ($response->successful()) {
                return $response->json();
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error("PMU Course Fetch Exception: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Format date to DDMMYYYY
     */
    public function formatDate(\DateTime $date): string
    {
        return $date->format('dmY');
    }

    /**
     * Get today's date in DDMMYYYY format
     */
    public function getTodayDate(): string
    {
        return $this->formatDate(new \DateTime());
    }
}
