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
        // $schedule->command('inspire')->hourly();
        $schedule->job(new \App\Jobs\UpdateListenerCountJob())->everyMinute();
        $schedule->job(new \App\Jobs\SaveViewCountJob())->everyMinute();
        $schedule->job(new \App\Jobs\Server\ScalingJob())->everyMinute();
        $schedule->job(new \App\Jobs\ServerAssignmentJob())->everyFifteenSeconds();
        $schedule->job(new \App\Jobs\CheckClientActivityJob())->everyFifteenMinutes();
        $schedule->job(new \App\Jobs\CleanUpUnusedClientsJob())->everyMinute();
        $schedule->job(new \App\Jobs\CleanUpInactiveServerAssignmentsJob())->everyFiveMinutes();
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
