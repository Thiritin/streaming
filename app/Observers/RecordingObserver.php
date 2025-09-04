<?php

namespace App\Observers;

use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RecordingObserver
{
    /**
     * Handle the Recording "created" event.
     */
    public function created(Recording $recording): void
    {
        // Dispatch job to process recording if m3u8_url is set
        // and we don't have duration or thumbnail yet
        if ($recording->m3u8_url && (!$recording->duration || !$recording->thumbnail_path)) {
            Log::info("Dispatching ProcessRecordingJob for newly created recording {$recording->id}");
            ProcessRecordingJob::dispatch($recording)->onQueue('recordings');
        }
    }

    /**
     * Handle the Recording "updated" event.
     */
    public function updated(Recording $recording): void
    {
        // If m3u8_url changed and we don't have duration or thumbnail, process it
        if ($recording->isDirty('m3u8_url') && $recording->m3u8_url) {
            if (!$recording->duration || !$recording->thumbnail_path) {
                Log::info("Dispatching ProcessRecordingJob for updated recording {$recording->id} (m3u8_url changed)");
                ProcessRecordingJob::dispatch($recording)->onQueue('recordings');
            }
        }
        
        // Also dispatch if explicitly requested (e.g., force regenerate thumbnail)
        if ($recording->isDirty('force_reprocess') && $recording->force_reprocess) {
            Log::info("Force reprocessing recording {$recording->id}");
            ProcessRecordingJob::dispatch($recording)->onQueue('recordings');
            
            // Reset the flag
            $recording->force_reprocess = false;
            $recording->saveQuietly(); // Save without triggering events
        }
    }

    /**
     * Handle the Recording "deleting" event.
     */
    public function deleting(Recording $recording): void
    {
        // Delete all thumbnails from default storage (S3) when recording is deleted
        if ($recording->thumbnail_path) {
            try {
                // Delete the main thumbnail
                if (Storage::exists($recording->thumbnail_path)) {
                    Storage::delete($recording->thumbnail_path);
                    Log::info("Deleted thumbnail for recording {$recording->id}: {$recording->thumbnail_path}");
                }
                
                // Clean up any other thumbnails for this recording
                $thumbnailDir = 'recordings/thumbnails';
                
                $files = Storage::files($thumbnailDir);
                foreach ($files as $file) {
                    if (str_contains($file, "recording_{$recording->id}_")) {
                        Storage::delete($file);
                        Log::info("Deleted additional thumbnail: {$file}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to delete thumbnails for recording {$recording->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle the Recording "restored" event.
     */
    public function restored(Recording $recording): void
    {
        // If a soft-deleted recording is restored and needs processing, dispatch the job
        if ($recording->m3u8_url && (!$recording->duration || !$recording->thumbnail_path)) {
            Log::info("Dispatching ProcessRecordingJob for restored recording {$recording->id}");
            ProcessRecordingJob::dispatch($recording)->onQueue('recordings');
        }
    }

    /**
     * Handle the Recording "force deleted" event.
     */
    public function forceDeleted(Recording $recording): void
    {
        // Same as deleting, ensure thumbnails are removed
        $this->deleting($recording);
    }
}