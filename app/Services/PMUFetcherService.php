<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PMUFetcherService
{
    private const BASE_URL = 'online.turfinfo.api.pmu.fr/rest/client/1';

    public function fetchProgramme(string $date): ?array
    {
        return $this->doFetch("/programme/{$date}");
    }

    public function fetchReunion(string $date, int $reunionNum): ?array
    {
        return $this->doFetch("/programme/{$date}/R{$reunionNum}");
    }

    public function fetchCourse(string $date, int $reunionNum, int $courseNum): ?array
    {
        return $this->doFetch("/programme/{$date}/R{$reunionNum}/C{$courseNum}/participants");
    }

    private function doFetch(string $endpoint): ?array
    {
        $url = self::BASE_URL . $endpoint;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("PMU API Error", [
                'url' => $url,
                'status' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("PMU API Exception", [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function formatDate(\DateTime $date): string
    {
        return $date->format('dmY');
    }

    public function getTodayDate(): string
    {
        return $this->formatDate(new \DateTime());
    }
}