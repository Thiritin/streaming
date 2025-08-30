<?php

namespace App\Jobs;

use App\Events\ShowThumbnailUpdated;
use App\Models\Show;
use App\Services\ThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CaptureThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 30;

    public $backoff = [10, 30, 60]; // Retry after 10s, 30s, 60s

    protected Show $show;

    /**
     * Create a new job instance.
     */
    public function __construct(Show $show)
    {
        $this->show = $show;
    }

    /**
     * Execute the job.
     */
    public function handle(ThumbnailService $thumbnailService): void
    {
        // Skip if show is no longer live
        if (! $this->show->isLive()) {
            Log::info("Skipping thumbnail capture for show {$this->show->id} - no longer live");

            return;
        }

        try {
            $thumbnailUrl = $thumbnailService->captureFromHls($this->show);

            if ($thumbnailUrl) {
                // Broadcast the update
                broadcast(new ShowThumbnailUpdated($this->show, $thumbnailUrl));

                Log::info("Thumbnail captured and broadcast for show {$this->show->id}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to capture thumbnail for show {$this->show->id}: ".$e->getMessage());

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Thumbnail capture job failed for show {$this->show->id} after retries: ".$exception->getMessage());

        // Update show with error status
        $this->show->update([
            'thumbnail_capture_error' => 'Failed after retries: '.$exception->getMessage(),
            'thumbnail_updated_at' => now(),
        ]);
    }
}
