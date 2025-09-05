<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\RecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessRecordingJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The recording to process.
     */
    public Recording $recording;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60; // Reduced since we're more efficient now

    /**
     * Create a new job instance.
     */
    public function __construct(Recording $recording)
    {
        $this->recording = $recording;
    }

    /**
     * Execute the job.
     */
    public function handle(RecordingService $recordingService): void
    {
        $startTime = microtime(true);
        
        // Reload the recording to get the latest data
        $this->recording->refresh();
        
        Log::info("Processing recording {$this->recording->id}: {$this->recording->title}", [
            'recording_id' => $this->recording->id,
            'm3u8_url' => $this->recording->m3u8_url,
            'current_duration' => $this->recording->duration,
            'has_duration' => !is_null($this->recording->duration),
            'has_thumbnail' => !is_null($this->recording->thumbnail_path),
        ]);

        // Skip if already processed (unless force reprocess is set)
        if ($this->recording->duration && $this->recording->thumbnail_path && !$this->recording->force_reprocess) {
            Log::info("Recording {$this->recording->id} already fully processed, skipping", [
                'recording_id' => $this->recording->id,
                'duration' => $this->recording->duration,
                'thumbnail_path' => $this->recording->thumbnail_path,
            ]);
            return;
        }

        try {
            $recordingService->processRecording($this->recording);
            
            $processingTime = round(microtime(true) - $startTime, 2);
            $freshRecording = $this->recording->fresh();
            Log::info("Successfully processed recording {$this->recording->id} in {$processingTime} seconds", [
                'recording_id' => $this->recording->id,
                'processing_time' => $processingTime,
                'duration' => $freshRecording->duration,
                'thumbnail_path' => $freshRecording->thumbnail_path,
            ]);
        } catch (\Exception $e) {
            $processingTime = round(microtime(true) - $startTime, 2);
            Log::error("Failed to process recording {$this->recording->id} after {$processingTime} seconds: ".$e->getMessage(), [
                'recording_id' => $this->recording->id,
                'processing_time' => $processingTime,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw to trigger retry logic
        }
    }

    /**
     * Get the unique ID for the job.
     * This prevents the same recording from being processed multiple times simultaneously.
     */
    public function uniqueId(): string
    {
        return 'process-recording-' . $this->recording->id;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Job failed for recording {$this->recording->id}: ".$exception->getMessage());

        // Update the recording with error status
        $this->recording->update([
            'thumbnail_capture_error' => 'Processing failed: '.$exception->getMessage(),
            'thumbnail_updated_at' => now(),
        ]);
    }
}
