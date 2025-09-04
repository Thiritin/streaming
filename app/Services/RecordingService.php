<?php

namespace App\Services;

use App\Models\Recording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class RecordingService
{
    protected string $thumbnailStoragePath = 'recordings/thumbnails';

    protected int $thumbnailWidth = 1280;

    protected int $thumbnailHeight = 720;

    protected int $quality = 85;

    protected int $captureTimeout = 30;

    /**
     * Process a recording to extract duration and thumbnail
     */
    public function processRecording(Recording $recording): void
    {
        if (! $recording->m3u8_url) {
            Log::warning("Recording {$recording->id} has no m3u8_url");

            return;
        }

        // Extract duration if not set
        if (! $recording->duration) {
            $duration = $this->extractDuration($recording->m3u8_url);
            if ($duration) {
                $recording->duration = $duration;
                $recording->save();
                Log::info("Extracted duration for recording {$recording->id}: {$duration} seconds");
            }
        }

        // Generate thumbnail if not set
        if (! $recording->thumbnail_path) {
            $thumbnailPath = $this->generateThumbnail($recording);
            if ($thumbnailPath) {
                Log::info("Generated thumbnail for recording {$recording->id}: {$thumbnailPath}");
            }
        }
    }

    /**
     * Extract duration from m3u8 playlist or video file
     */
    public function extractDuration(string $url): ?int
    {
        // First try to parse m3u8 playlist for duration (much faster)
        if (str_ends_with(strtolower($url), '.m3u8')) {
            $duration = $this->extractDurationFromM3u8($url);
            if ($duration !== null) {
                return $duration;
            }
            Log::warning('Failed to extract duration from m3u8, falling back to ffprobe');
        }
        
        // Fallback to ffprobe for non-m3u8 files or if m3u8 parsing failed
        try {
            $command = [
                'ffprobe',
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $url,
            ];

            $result = Process::timeout(30)->run($command);

            if (! $result->successful()) {
                Log::error('FFprobe error extracting duration: '.$result->errorOutput());
                return null;
            }

            $duration = trim($result->output());
            if (is_numeric($duration)) {
                return (int) round((float) $duration);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to extract duration with ffprobe: '.$e->getMessage());
            return null;
        }
    }
    
    /**
     * Extract duration by parsing m3u8 playlist
     */
    protected function extractDurationFromM3u8(string $url): ?int
    {
        try {
            // Download the m3u8 playlist
            $response = Http::timeout(10)->get($url);
            
            if (!$response->successful()) {
                Log::error('Failed to fetch m3u8 playlist: ' . $response->status());
                return null;
            }
            
            $content = $response->body();
            $lines = explode("\n", $content);
            $totalDuration = 0.0;
            
            // Check if it's a master playlist
            if (str_contains($content, '#EXT-X-STREAM-INF')) {
                // This is a master playlist, we need to fetch a variant
                Log::info('Detected master playlist, fetching first variant');
                
                // Find first variant URL
                $variantUrl = null;
                foreach ($lines as $i => $line) {
                    if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                        // Next non-empty, non-comment line should be the variant URL
                        for ($j = $i + 1; $j < count($lines); $j++) {
                            $nextLine = trim($lines[$j]);
                            if ($nextLine && !str_starts_with($nextLine, '#')) {
                                $variantUrl = $nextLine;
                                break;
                            }
                        }
                        break;
                    }
                }
                
                if (!$variantUrl) {
                    Log::error('No variant URL found in master playlist');
                    return null;
                }
                
                // Make variant URL absolute if it's relative
                if (!filter_var($variantUrl, FILTER_VALIDATE_URL)) {
                    $baseUrl = dirname($url);
                    $variantUrl = $baseUrl . '/' . $variantUrl;
                }
                
                // Recursively fetch the variant playlist
                return $this->extractDurationFromM3u8($variantUrl);
            }
            
            // Parse segment durations from media playlist
            foreach ($lines as $line) {
                // Look for EXTINF tags which contain segment duration
                if (str_starts_with($line, '#EXTINF:')) {
                    // Extract duration from #EXTINF:duration,
                    $matches = [];
                    if (preg_match('/#EXTINF:([0-9.]+)/', $line, $matches)) {
                        $totalDuration += (float) $matches[1];
                    }
                }
                // Also check for EXT-X-TARGETDURATION as a fallback
                else if (str_starts_with($line, '#EXT-X-TARGETDURATION:')) {
                    // This gives us the maximum segment duration, not total
                    // We'll use segment counting as fallback if needed
                }
            }
            
            if ($totalDuration > 0) {
                Log::info("Extracted duration from m3u8: {$totalDuration} seconds");
                return (int) round($totalDuration);
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to parse m3u8 for duration: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate thumbnail from video
     */
    public function generateThumbnail(Recording $recording): ?string
    {
        if (! $recording->m3u8_url) {
            return null;
        }

        $filename = $this->generateFilename($recording);
        $tempPath = storage_path('app/temp/'.$filename);
        $s3Path = $this->thumbnailStoragePath.'/'.$filename;

        // Ensure temp directory exists
        if (! file_exists(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        try {
            // Capture thumbnail using ffmpeg (first frame)
            $result = $this->captureFirstFrame($recording->m3u8_url, $tempPath);

            if (! $result || ! file_exists($tempPath)) {
                throw new \Exception('Failed to capture thumbnail');
            }

            // Store to default storage (S3)
            $uploaded = Storage::putFileAs(
                $this->thumbnailStoragePath,
                $tempPath,
                $filename,
                'public'
            );

            if (! $uploaded) {
                throw new \Exception('Failed to upload thumbnail to storage');
            }

            // Clean up temp file
            @unlink($tempPath);

            // Clean up old thumbnails for this recording
            $this->cleanupOldThumbnails($recording);

            // Update recording model with the path (not URL)
            $recording->update([
                'thumbnail_path' => $s3Path,
                'thumbnail_updated_at' => now(),
                'thumbnail_capture_error' => null,
            ]);

            return $s3Path;

        } catch (\Exception $e) {
            Log::error("Failed to generate thumbnail for recording {$recording->id}: ".$e->getMessage());

            // Update error status
            $recording->update([
                'thumbnail_capture_error' => $e->getMessage(),
                'thumbnail_updated_at' => now(),
            ]);

            // Clean up temp file if exists
            @unlink($tempPath);

            return null;
        }
    }

    /**
     * Capture the first frame from a video
     */
    protected function captureFirstFrame(string $videoUrl, string $outputPath): bool
    {
        // For m3u8 files, try to use just the first segment for efficiency
        $inputUrl = $videoUrl;
        if (str_ends_with(strtolower($videoUrl), '.m3u8')) {
            $firstSegment = $this->getFirstSegmentUrl($videoUrl);
            if ($firstSegment) {
                Log::info('Using first segment for thumbnail: ' . $firstSegment);
                $inputUrl = $firstSegment;
            }
        }
        
        // Build ffmpeg command
        // -ss 1: Start at 1 second (skip potential black frames)
        // -t 1: Limit input reading to 1 second for efficiency
        // -i: input stream
        // -vframes: capture 1 frame
        // -vf: scale to desired size
        // -q:v: quality (lower is better, 2-5 is good)
        $command = [
            'ffmpeg',
            '-y', // Overwrite output
            '-ss', '1', // Start at 1 second to avoid black frames
            '-t', '1', // Only read 1 second of input
            '-i', $inputUrl,
            '-vframes', '1', // Capture 1 frame
            '-vf', "scale={$this->thumbnailWidth}:{$this->thumbnailHeight}:force_original_aspect_ratio=decrease,pad={$this->thumbnailWidth}:{$this->thumbnailHeight}:(ow-iw)/2:(oh-ih)/2",
            '-q:v', '2', // High quality
            $outputPath,
        ];

        $result = Process::timeout($this->captureTimeout)->run($command);

        if (! $result->successful()) {
            Log::error('FFmpeg error capturing thumbnail: '.$result->errorOutput());
            return false;
        }

        return true;
    }
    
    /**
     * Get the URL of the first video segment from an m3u8 playlist
     */
    protected function getFirstSegmentUrl(string $m3u8Url): ?string
    {
        try {
            $response = Http::timeout(10)->get($m3u8Url);
            
            if (!$response->successful()) {
                return null;
            }
            
            $content = $response->body();
            $lines = explode("\n", $content);
            
            // Check if it's a master playlist
            if (str_contains($content, '#EXT-X-STREAM-INF')) {
                // Get the first variant playlist
                foreach ($lines as $i => $line) {
                    if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                        for ($j = $i + 1; $j < count($lines); $j++) {
                            $nextLine = trim($lines[$j]);
                            if ($nextLine && !str_starts_with($nextLine, '#')) {
                                // Make URL absolute if relative
                                if (!filter_var($nextLine, FILTER_VALIDATE_URL)) {
                                    $baseUrl = dirname($m3u8Url);
                                    $nextLine = $baseUrl . '/' . $nextLine;
                                }
                                // Recursively get first segment from variant playlist
                                return $this->getFirstSegmentUrl($nextLine);
                            }
                        }
                    }
                }
            }
            
            // Find the first .ts segment
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && !str_starts_with($line, '#')) {
                    // This should be a segment URL
                    if (str_contains($line, '.ts') || str_contains($line, '.m4s')) {
                        // Make URL absolute if relative
                        if (!filter_var($line, FILTER_VALIDATE_URL)) {
                            $baseUrl = dirname($m3u8Url);
                            $line = $baseUrl . '/' . $line;
                        }
                        return $line;
                    }
                }
            }
            
            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get first segment from m3u8: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate a unique filename for the thumbnail
     */
    protected function generateFilename(Recording $recording): string
    {
        return sprintf(
            'recording_%d_%s.jpg',
            $recording->id,
            now()->format('YmdHis')
        );
    }

    /**
     * Clean up old thumbnails for a recording (keep only last 3)
     */
    protected function cleanupOldThumbnails(Recording $recording): void
    {
        $files = Storage::files($this->thumbnailStoragePath);

        $recordingThumbnails = array_filter($files, function ($file) use ($recording) {
            return str_contains($file, "recording_{$recording->id}_");
        });

        // Sort by timestamp (newest first)
        usort($recordingThumbnails, function ($a, $b) {
            return Storage::lastModified($b) - Storage::lastModified($a);
        });

        // Keep only the 3 most recent
        $toDelete = array_slice($recordingThumbnails, 3);
        foreach ($toDelete as $file) {
            Storage::delete($file);
        }
    }

    /**
     * Delete all thumbnails for a recording
     */
    public function deleteRecordingThumbnails(Recording $recording): void
    {
        $files = Storage::files($this->thumbnailStoragePath);

        $recordingThumbnails = array_filter($files, function ($file) use ($recording) {
            return str_contains($file, "recording_{$recording->id}_");
        });

        foreach ($recordingThumbnails as $file) {
            Storage::delete($file);
        }

        Log::info("Deleted thumbnails for recording {$recording->id}");
    }

    /**
     * Validate if ffmpeg and ffprobe are available
     */
    public function isFFmpegAvailable(): bool
    {
        $ffmpegResult = Process::run(['which', 'ffmpeg']);
        $ffprobeResult = Process::run(['which', 'ffprobe']);

        return $ffmpegResult->successful() && $ffprobeResult->successful();
    }

    /**
     * Process all recordings without duration or thumbnail
     */
    public function processUnprocessedRecordings(): void
    {
        $recordings = Recording::where(function ($query) {
            $query->whereNull('duration')
                ->orWhereNull('thumbnail_path');
        })->get();

        foreach ($recordings as $recording) {
            $this->processRecording($recording);
        }

        Log::info("Processed {$recordings->count()} recordings");
    }
}

