<?php

namespace App\Services;

use App\Models\Show;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ThumbnailService
{
    protected string $storagePath = 'shows/thumbnails';

    protected int $thumbnailWidth = 640;

    protected int $thumbnailHeight = 360;

    protected int $quality = 85;

    protected int $captureTimeout = 30; // seconds - increased for HLS streams

    /**
     * Capture a thumbnail from an HLS stream
     */
    public function captureFromHls(Show $show): ?string
    {
        if (! $show->isLive() || ! $show->source) {
            return null;
        }

        $streamUrl = $show->source->getHlsUrl();

        if (! $streamUrl) {
            Log::warning("No HLS URL available for show {$show->id}");

            return null;
        }

        // Add system streamkey for authentication
        $systemStreamkey = config('stream.system_streamkey') ?: env('STREAM_KEY');
        if ($systemStreamkey) {
            $separator = str_contains($streamUrl, '?') ? '&' : '?';
            $streamUrl .= $separator . 'streamkey=' . $systemStreamkey;
        }

        Log::info("Capturing thumbnail for show {$show->id} from URL: {$streamUrl}");

        // Generate unique filename
        $filename = $this->generateFilename($show);
        $tempPath = storage_path('app/temp/'.$filename);
        $s3Path = $this->storagePath.'/'.$filename;

        // Ensure temp directory exists
        if (! file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        try {
            // Capture thumbnail using ffmpeg
            $result = $this->captureFrame($streamUrl, $tempPath);

            if (! $result || ! file_exists($tempPath)) {
                throw new \Exception('Failed to capture thumbnail');
            }

            // Upload to S3 (private)
            $uploaded = Storage::disk('s3')->putFileAs(
                $this->storagePath,
                $tempPath,
                $filename,
                ['visibility' => 'private']
            );

            if (!$uploaded) {
                throw new \Exception('Failed to upload thumbnail to S3');
            }

            // Clean up temp file
            @unlink($tempPath);

            // Clean up old thumbnails for this show
            $this->cleanupOldThumbnails($show);

            // Update show model with the path (not URL)
            $show->update([
                'thumbnail_path' => $s3Path,
                'thumbnail_updated_at' => now(),
                'thumbnail_capture_error' => null,
            ]);

            Log::info("Thumbnail captured for show {$show->id}: {$s3Path}");

            return $s3Path;

        } catch (\Exception $e) {
            Log::error("Failed to capture thumbnail for show {$show->id}: ".$e->getMessage());

            // Update error status
            $show->update([
                'thumbnail_capture_error' => $e->getMessage(),
                'thumbnail_updated_at' => now(),
            ]);

            // Clean up temp file if exists
            @unlink($tempPath);

            return null;
        }
    }

    /**
     * Capture a frame using ffmpeg
     */
    protected function captureFrame(string $streamUrl, string $outputPath): bool
    {
        // Build ffmpeg command
        // -i: input stream
        // -ss: seek to 1 second (skip potential black frames at start)
        // -vframes: capture 1 frame
        // -vf: scale to desired size
        // -q:v: quality (lower is better, 2-5 is good)
        $command = [
            'ffmpeg',
            '-y', // Overwrite output
            '-i', $streamUrl,
            '-ss', '1', // Seek to 1 second
            '-vframes', '1', // Capture 1 frame
            '-vf', "scale={$this->thumbnailWidth}:{$this->thumbnailHeight}:force_original_aspect_ratio=decrease,pad={$this->thumbnailWidth}:{$this->thumbnailHeight}:(ow-iw)/2:(oh-ih)/2",
            '-q:v', '2', // High quality
            $outputPath,
        ];

        $result = Process::timeout($this->captureTimeout)->run($command);

        if (! $result->successful()) {
            Log::error('FFmpeg error: '.$result->errorOutput());

            return false;
        }

        return true;
    }

    /**
     * Generate a unique filename for the thumbnail
     */
    protected function generateFilename(Show $show): string
    {
        return sprintf(
            'show_%d_%s.jpg',
            $show->id,
            now()->format('YmdHis')
        );
    }

    /**
     * Clean up old thumbnails for a show (keep only last 5)
     */
    protected function cleanupOldThumbnails(Show $show): void
    {
        $pattern = "show_{$show->id}_*.jpg";
        $files = Storage::disk('s3')->files($this->storagePath);

        $showThumbnails = array_filter($files, function ($file) use ($show) {
            return fnmatch($this->storagePath.'/'."show_{$show->id}_*.jpg", $file);
        });

        // Sort by timestamp (newest first)
        usort($showThumbnails, function ($a, $b) {
            return Storage::disk('s3')->lastModified($b) - Storage::disk('s3')->lastModified($a);
        });

        // Keep only the 5 most recent
        $toDelete = array_slice($showThumbnails, 5);
        foreach ($toDelete as $file) {
            Storage::disk('s3')->delete($file);
        }
    }

    /**
     * Delete all thumbnails for a show
     */
    public function deleteShowThumbnails(Show $show): void
    {
        $pattern = "show_{$show->id}_*.jpg";
        $files = Storage::disk('s3')->files($this->storagePath);

        $showThumbnails = array_filter($files, function ($file) use ($show) {
            return fnmatch($this->storagePath.'/'."show_{$show->id}_*.jpg", $file);
        });

        foreach ($showThumbnails as $file) {
            Storage::disk('s3')->delete($file);
        }

        Log::info("Deleted thumbnails for show {$show->id}");
    }

    /**
     * Upload an existing image file to S3 as thumbnail
     */
    public function uploadThumbnail(Show $show, string $localPath): ?string
    {
        try {
            if (!file_exists($localPath)) {
                throw new \Exception('File does not exist: ' . $localPath);
            }

            // Generate filename
            $filename = $this->generateFilename($show);
            $s3Path = $this->storagePath.'/'.$filename;

            // Upload to S3
            $uploaded = Storage::disk('s3')->putFileAs(
                $this->storagePath,
                $localPath,
                $filename,
                ['visibility' => 'private']
            );

            if (!$uploaded) {
                throw new \Exception('Failed to upload to S3');
            }

            // Clean up old thumbnails
            $this->cleanupOldThumbnails($show);

            // Update show model with path
            $show->update([
                'thumbnail_path' => $s3Path,
                'thumbnail_updated_at' => now(),
                'thumbnail_capture_error' => null,
            ]);

            Log::info("Thumbnail uploaded for show {$show->id}: {$s3Path}");

            return $s3Path;

        } catch (\Exception $e) {
            Log::error("Failed to upload thumbnail for show {$show->id}: " . $e->getMessage());

            $show->update([
                'thumbnail_capture_error' => $e->getMessage(),
                'thumbnail_updated_at' => now(),
            ]);

            return null;
        }
    }

    /**
     * Get a placeholder thumbnail URL
     */
    public function getPlaceholderUrl(): string
    {
        return '/images/stream-placeholder.jpg';
    }

    /**
     * Validate if ffmpeg is available
     */
    public function isFFmpegAvailable(): bool
    {
        $result = Process::run(['which', 'ffmpeg']);

        return $result->successful();
    }
}
