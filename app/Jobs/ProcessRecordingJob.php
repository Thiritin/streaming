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

class ProcessRecordingJob implements ShouldQueue
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
        Log::info("Processing recording {$this->recording->id}: {$this->recording->title}", [
            'recording_id' => $this->recording->id,
            'm3u8_url' => $this->recording->m3u8_url,
            'has_duration' => !is_null($this->recording->duration),
            'has_thumbnail' => !is_null($this->recording->thumbnail_path),
        ]);

        try {
            $recordingService->processRecording($this->recording);
            
            $processingTime = round(microtime(true) - $startTime, 2);
            Log::info("Successfully processed recording {$this->recording->id} in {$processingTime} seconds", [
                'recording_id' => $this->recording->id,
                'processing_time' => $processingTime,
                'duration' => $this->recording->fresh()->duration,
                'thumbnail_path' => $this->recording->fresh()->thumbnail_path,
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
