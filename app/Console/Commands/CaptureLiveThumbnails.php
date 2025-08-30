<?php

namespace App\Console\Commands;

use App\Jobs\CaptureThumbnailJob;
use App\Models\Show;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class CaptureLiveThumbnails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'thumbnails:capture {--show= : Capture thumbnail for specific show ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Capture thumbnails for all live shows';

    /**
     * Execute the console command.
     */
    public function handle(ThumbnailService $thumbnailService): int
    {
        // Check if ffmpeg is available
        if (! $thumbnailService->isFFmpegAvailable()) {
            $this->error('FFmpeg is not installed or not in PATH');

            return Command::FAILURE;
        }

        // If specific show ID provided
        if ($showId = $this->option('show')) {
            $show = Show::find($showId);
            if (! $show) {
                $this->error("Show with ID {$showId} not found");

                return Command::FAILURE;
            }

            if (! $show->isLive()) {
                $this->warn("Show {$showId} is not live");

                return Command::SUCCESS;
            }

            $this->info("Capturing thumbnail for show: {$show->title}");
            CaptureThumbnailJob::dispatch($show);

            return Command::SUCCESS;
        }

        // Get all live shows
        $liveShows = Show::live()
            ->with('source')
            ->get();

        if ($liveShows->isEmpty()) {
            $this->info('No live shows found');

            return Command::SUCCESS;
        }

        $this->info("Found {$liveShows->count()} live show(s)");

        foreach ($liveShows as $show) {
            // Skip if thumbnail was captured recently (within last 45 seconds)
            if ($show->thumbnail_updated_at && $show->thumbnail_updated_at->gt(now()->subSeconds(45))) {
                $this->line("Skipping {$show->title} - thumbnail recently captured");

                continue;
            }

            $this->line("Dispatching thumbnail capture for: {$show->title}");
            CaptureThumbnailJob::dispatch($show);
        }

        $this->info('Thumbnail capture jobs dispatched');

        return Command::SUCCESS;
    }
}
