<?php

namespace App\Services;

use App\Models\Recording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecordingService
{
    /**
     * Protocols ffmpeg may follow out of a playlist we handed it as a local file.
     *
     * Everything the archive puts in a playlist is either a presigned S3 URL or an app
     * route, so http/https/tcp/tls are all that is added to ffmpeg's file defaults.
     */
    protected const PROTOCOL_WHITELIST = 'file,crypto,data,http,https,tcp,tls';

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

        $playlist = $this->stagePlaylist($recording);

        try {
            $target = $playlist ?? $recording->m3u8_url;

            // Extract duration if not set or if explicitly reprocessing
            if (! $recording->duration || $recording->force_reprocess) {
                $duration = $this->extractDuration($target);
                if ($duration) {
                    // Always set the duration to the extracted value (don't accumulate)
                    $recording->duration = $duration;
                    $recording->save();
                    Log::info("Set duration for recording {$recording->id}: {$duration} seconds");
                }
            } else {
                Log::info("Recording {$recording->id} already has duration: {$recording->duration} seconds, skipping extraction");
            }

            // Generate thumbnail if not set
            if (! $recording->thumbnail_path) {
                $thumbnailPath = $this->generateThumbnail($recording, $target);
                if ($thumbnailPath) {
                    Log::info("Generated thumbnail for recording {$recording->id}: {$thumbnailPath}");
                }
            }
        } finally {
            if ($playlist) {
                @unlink($playlist);
            }
        }
    }

    /**
     * A local playlist file for a cut, or null for a recording registered from outside.
     *
     * A cut's `m3u8_url` is an app route, not an object: playlists are rendered per
     * request because the segment URLs inside them are presigned and expire. That
     * route requires a session, and a queue worker has none, so ffmpeg fetching it
     * got 404 on an unpublished draft and 403 on a role-restricted one - which is
     * every cut at the point the observer first dispatches this job. Rendering the
     * playlist here and handing ffmpeg a file skips the round trip entirely; the
     * segment URLs inside are absolute and signed, so it still fetches the media.
     *
     * The caller owns the file and must unlink it.
     */
    protected function stagePlaylist(Recording $recording): ?string
    {
        if (! $recording->hasCut() || ! $recording->archiveSourceSlug()) {
            return null;
        }

        try {
            $body = app(ArchivePlaylistService::class)->renderMedia($recording, 'hd');
        } catch (\Throwable $e) {
            Log::warning("Could not render playlist for recording {$recording->id}: ".$e->getMessage());

            return null;
        }

        $path = storage_path('app/temp/recording-'.$recording->id.'-'.Str::random(8).'.m3u8');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $body);

        return $path;
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
                // Same reason as captureFrameAtTime: a staged playlist is a local file
                // pointing at https segments.
                '-protocol_whitelist', self::PROTOCOL_WHITELIST,
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
            // A staged playlist (see stagePlaylist) is a local file, not a URL.
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $content = @file_get_contents($url);

                if ($content === false) {
                    Log::error('Failed to read m3u8 playlist from disk: '.$url);

                    return null;
                }
            } else {
                // Download the m3u8 playlist
                $response = Http::timeout(10)->get($url);

                if (! $response->successful()) {
                    Log::error('Failed to fetch m3u8 playlist: '.$response->status());

                    return null;
                }

                $content = $response->body();
            }

            $lines = explode("\n", $content);
            $totalDuration = 0.0;
            $segmentCount = 0;

            // Check if it's a master playlist
            if (str_contains($content, '#EXT-X-STREAM-INF')) {
                // This is a master playlist, we need to fetch a variant
                Log::info('Detected master playlist, fetching first variant from: '.$url);

                // Find first variant URL
                $variantUrl = null;
                $variantCount = 0;
                foreach ($lines as $i => $line) {
                    if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                        $variantCount++;
                        // Only process the first variant
                        if ($variantCount === 1) {
                            // Next non-empty, non-comment line should be the variant URL
                            for ($j = $i + 1; $j < count($lines); $j++) {
                                $nextLine = trim($lines[$j]);
                                if ($nextLine && ! str_starts_with($nextLine, '#')) {
                                    $variantUrl = $nextLine;
                                    break 2; // Break out of both loops
                                }
                            }
                        }
                    }
                }

                Log::info("Master playlist has {$variantCount} variants");

                if (! $variantUrl) {
                    Log::error('No variant URL found in master playlist');

                    return null;
                }

                // Make variant URL absolute if it's relative
                if (! filter_var($variantUrl, FILTER_VALIDATE_URL)) {
                    $baseUrl = dirname($url);
                    $variantUrl = $baseUrl.'/'.$variantUrl;
                }

                Log::info('Fetching first variant playlist: '.$variantUrl);

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
                        $segmentDuration = (float) $matches[1];
                        $totalDuration += $segmentDuration;
                        $segmentCount++;
                    }
                }
            }

            if ($totalDuration > 0) {
                Log::info("Extracted duration from m3u8: {$totalDuration} seconds from {$segmentCount} segments");

                return (int) round($totalDuration);
            }

            Log::warning('No duration extracted from m3u8 (no EXTINF tags found)');

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to parse m3u8 for duration: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generate thumbnail from video
     */
    public function generateThumbnail(Recording $recording, ?string $source = null): ?string
    {
        // Falls back to staging its own playlist so the public entry point works on a
        // cut too; see stagePlaylist for why the stored URL is not usable here.
        $staged = null;

        if ($source === null) {
            $staged = $this->stagePlaylist($recording);
            $source = $staged ?? $recording->m3u8_url;
        }

        if (! $source) {
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
            // Capture thumbnail from middle of video or at 30 seconds
            $captureTime = 30; // Default to 30 seconds
            if ($recording->duration && $recording->duration > 60) {
                // For longer videos, capture from the middle
                $captureTime = min($recording->duration / 2, 300); // Max 5 minutes in
            }

            $result = $this->captureFrameAtTime($source, $tempPath, $captureTime);

            if (! $result || ! file_exists($tempPath)) {
                throw new \Exception('Failed to capture thumbnail');
            }

            // Store to S3
            // Private, like every other recording thumbnail: the bucket is read through
            // temporary URLs (see Recording::getThumbnailUrlAttribute and the
            // recording_thumbnail entry in config/manage.php), and asking for a public
            // ACL on a bucket that has them disabled throws rather than degrading.
            $uploaded = Storage::disk('s3')->putFileAs(
                $this->thumbnailStoragePath,
                $tempPath,
                $filename,
                ['visibility' => 'private']
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
        } finally {
            if ($staged) {
                @unlink($staged);
            }
        }
    }

    /**
     * Capture a frame at a specific time from a video
     */
    protected function captureFrameAtTime(string $videoUrl, string $outputPath, float $timeInSeconds): bool
    {
        // Use the URL directly
        $inputUrl = $videoUrl;

        // Build ffmpeg command
        // -ss: Seek to specific time
        // -i: input stream
        // -frames:v: capture 1 frame
        // -vf: scale to desired size
        // -q:v: quality (lower is better, 2-5 is good)
        $command = [
            'ffmpeg',
            '-y', // Overwrite output
            // A staged playlist is a local file whose segment URLs are absolute and
            // presigned, and ffmpeg derives the demuxer's protocol whitelist from the
            // input: for a file input that is file,crypto,data, so every segment is
            // refused with "Protocol 'https' not on whitelist" before it is fetched.
            // Duration comes from parsing the playlist text, which is why a cut ended up
            // with a duration and never a thumbnail.
            '-protocol_whitelist', self::PROTOCOL_WHITELIST,
            '-ss', (string) $timeInSeconds, // Seek to specific time
            '-i', $inputUrl,
            '-frames:v', '1', // Capture 1 frame
            '-vf', "scale={$this->thumbnailWidth}:{$this->thumbnailHeight}:force_original_aspect_ratio=decrease,pad={$this->thumbnailWidth}:{$this->thumbnailHeight}:(ow-iw)/2:(oh-ih)/2",
            '-q:v', '2', // High quality
            $outputPath,
        ];

        Log::info("Capturing thumbnail at {$timeInSeconds} seconds from: {$inputUrl}");

        $result = Process::timeout($this->captureTimeout)->run($command);

        if (! $result->successful()) {
            Log::error('FFmpeg error capturing thumbnail: '.$result->errorOutput());

            return false;
        }

        return true;
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
        $files = Storage::disk('s3')->files($this->thumbnailStoragePath);

        $recordingThumbnails = array_filter($files, function ($file) use ($recording) {
            return str_contains($file, "recording_{$recording->id}_");
        });

        // Sort by timestamp (newest first)
        usort($recordingThumbnails, function ($a, $b) {
            return Storage::disk('s3')->lastModified($b) - Storage::disk('s3')->lastModified($a);
        });

        // Keep only the 3 most recent
        $toDelete = array_slice($recordingThumbnails, 3);
        foreach ($toDelete as $file) {
            Storage::disk('s3')->delete($file);
        }
    }

    /**
     * Delete all thumbnails for a recording
     */
    public function deleteRecordingThumbnails(Recording $recording): void
    {
        $files = Storage::disk('s3')->files($this->thumbnailStoragePath);

        $recordingThumbnails = array_filter($files, function ($file) use ($recording) {
            return str_contains($file, "recording_{$recording->id}_");
        });

        foreach ($recordingThumbnails as $file) {
            Storage::disk('s3')->delete($file);
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
