<?php

namespace App\Console\Commands;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAutoModeShows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shows:check-auto-mode';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and start/end auto mode shows based on schedule (ends at scheduled time regardless of source status)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking auto mode shows...');

        // Check for shows that should start
        $this->checkShowsToStart();

        // Check for shows that should end
        $this->checkShowsToEnd();

        $this->info('Auto mode check completed.');
    }

    /**
     * Check for scheduled shows that should start automatically.
     */
    private function checkShowsToStart()
    {
        // Find scheduled shows in auto mode where:
        // 1. The scheduled start time has passed
        // 2. The source is online
        // 3. The show is still in scheduled status
        $showsToStart = Show::where('auto_mode', true)
            ->where('status', 'scheduled')
            ->where('scheduled_start', '<=', now())
            ->whereHas('source', function ($query) {
                $query->where('status', SourceStatusEnum::ONLINE);
            })
            ->get();

        foreach ($showsToStart as $show) {
            $this->info("Starting auto mode show: {$show->title}");
            
            Log::info('CheckAutoModeShows: Auto-starting show at scheduled time', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'scheduled_start' => $show->scheduled_start,
                'source_status' => $show->source->status->value,
            ]);

            $show->goLive();

            $this->info("✓ Show '{$show->title}' started successfully");
        }

        if ($showsToStart->isEmpty()) {
            $this->info('No shows to auto-start at this time.');
        }
    }

    /**
     * Check for live shows that should end automatically.
     * Shows end when their scheduled end time is reached, regardless of source status.
     * This ensures shows don't run indefinitely even if the source stays online.
     */
    private function checkShowsToEnd()
    {
        // Find all live auto mode shows where the scheduled end time has passed
        // These shows will be ended regardless of whether the source is online, offline, or in error
        $showsToEnd = Show::where('auto_mode', true)
            ->where('status', 'live')
            ->where('scheduled_end', '<=', now())
            ->get();

        foreach ($showsToEnd as $show) {
            $sourceStatus = $show->source ? $show->source->status->value : 'unknown';
            
            $this->info("Ending auto mode show: {$show->title}");
            
            Log::info('CheckAutoModeShows: Auto-ending show at scheduled end time', [
                'show_id' => $show->id,
                'show_title' => $show->title,
                'scheduled_end' => $show->scheduled_end,
                'current_time' => now(),
                'source_status' => $sourceStatus,
                'reason' => 'Scheduled end time reached',
            ]);

            $show->endLivestream();

            $this->info("✓ Show '{$show->title}' ended successfully (scheduled end reached)");
        }

        if ($showsToEnd->isEmpty()) {
            $this->info('No shows to auto-end at this time.');
        }
    }
}