<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Fetch daily PMU data at 6:00 AM
        $schedule->command('pmu:fetch')
            ->dailyAt('06:00')
            ->dailyAt('13:00')
            ->dailyAt('18:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pmu-fetch.log'));

        // Refresh data at 2:00 PM (for afternoon races)
        $schedule->command('pmu:fetch')
            ->dailyAt('14:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pmu-fetch.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
