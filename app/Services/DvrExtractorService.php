<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DvrExtractorService
{
    protected string $tempPath;
    protected $progressCallback;

    public function __construct()
    {
        $this->tempPath = storage_path('app/temp/dvr');
    }

    /**
     * Find all segments within a time range
     */
    public function findSegments(string $stream, Carbon $startTime, Carbon $endTime): array
    {
        $segments = [];
        $disk = Storage::disk('dvr');
        
        // Convert times to milliseconds
        // The timestamps in filenames are milliseconds since epoch
        $startMs = $startTime->timestamp * 1000;
        $endMs = $endTime->timestamp * 1000;

        // Iterate through each day in the range
        $currentDate = $startTime->copy()->startOfDay();
        $endDate = $endTime->copy()->startOfDay();

        while ($currentDate <= $endDate) {
            // Date folders are in local time (Europe/Berlin)
            // But we need to also check dvr/ingress path
            $datePath = sprintf('dvr/ingress/%s/%s', $stream, $currentDate->format('Y-m-d'));
            
            try {
                // List all files for this date using Storage facade
                if (!$disk->exists($datePath)) {
                    $currentDate->addDay();
                    continue;
                }

                $files = $disk->files($datePath);
                
                foreach ($files as $file) {
                    $filename = basename($file);
                    
                    // Parse timestamp from filename (format: HH-MM-SS_timestampMs.mp4)
                    if (preg_match('/\d{2}-\d{2}-\d{2}_(\d+)\.mp4$/', $filename, $matches)) {
                        $segmentTimestamp = (int) $matches[1];
                        
                        // Check if segment falls within our time range
                        // Add a small buffer (30 seconds) since segments can be up to ~20 seconds
                        if ($segmentTimestamp >= ($startMs - 30000) && $segmentTimestamp <= ($endMs + 30000)) {
                            $segments[] = [
                                'path' => $file,
                                'filename' => $filename,
                                'timestamp' => $segmentTimestamp,
                                'size' => $disk->size($file),
                                'date' => $currentDate->format('Y-m-d'),
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log error but continue with other dates
                \Log::warning("Failed to list DVR segments for {$datePath}: " . $e->getMessage());
            }

            $currentDate->addDay();
        }

        // Sort segments by timestamp
        usort($segments, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $segments;
    }

    /**
     * Extract and combine DVR segments
     */
    public function extract(
        string $stream, 
        Carbon $startTime, 
        Carbon $endTime, 
        string $outputFilename,
        string $targetStorage = 'public',
        callable $progressCallback = null
    ): string {
        $this->progressCallback = $progressCallback;
        
        // Find segments
        $this->log('Finding segments...');
        $segments = $this->findSegments($stream, $startTime, $endTime);
        
        if (empty($segments)) {
            throw new \Exception('No segments found in the specified time range');
        }

        $this->log("Found " . count($segments) . " segments to process");

        // Create temp directory
        $sessionId = Str::uuid()->toString();
        $sessionPath = $this->tempPath . '/' . $sessionId;
        File::ensureDirectoryExists($sessionPath);

        try {
            // Download segments
            $this->log('Downloading segments...');
            $localFiles = $this->downloadSegments($segments, $sessionPath);
            
            // Create concat file for ffmpeg
            $this->log('Creating concatenation list...');
            $concatFile = $this->createConcatFile($localFiles, $sessionPath);
            
            // Combine with ffmpeg
            $this->log('Combining segments with FFmpeg...');
            $tempOutput = $sessionPath . '/combined.mp4';
            $this->combineSegments($concatFile, $tempOutput);
            
            // Trim to exact time range if needed
            $this->log('Trimming to exact time range...');
            $trimmedOutput = $sessionPath . '/output.mp4';
            $this->trimToExactRange($tempOutput, $trimmedOutput, $segments, $startTime, $endTime);
            
            // Move to target storage
            $this->log('Moving to target storage...');
            $finalPath = $this->moveToStorage($trimmedOutput, $outputFilename, $targetStorage);
            
            $this->log('Extraction complete!', 'success');
            
            return $finalPath;
        } finally {
            // Cleanup temp files
            $this->cleanup($sessionPath);
        }
    }

    /**
     * Download segments from S3 to local temp storage
     */
    protected function downloadSegments(array $segments, string $localPath): array
    {
        $disk = Storage::disk('dvr');
        $localFiles = [];
        $totalSegments = count($segments);
        
        foreach ($segments as $index => $segment) {
            $localFile = $localPath . '/' . $segment['filename'];
            $this->log(sprintf(
                'Downloading segment %d/%d: %s', 
                $index + 1, 
                $totalSegments, 
                $segment['filename']
            ));
            
            // Download from S3 to local using Storage facade
            $contents = $disk->get($segment['path']);
            File::put($localFile, $contents);
            
            $localFiles[] = [
                'path' => $localFile,
                'filename' => $segment['filename'],
                'timestamp' => $segment['timestamp'],
            ];
        }
        
        return $localFiles;
    }

    /**
     * Create concat file for ffmpeg
     */
    protected function createConcatFile(array $files, string $sessionPath): string
    {
        $concatFile = $sessionPath . '/concat.txt';
        $content = '';
        
        foreach ($files as $file) {
            // FFmpeg concat format: file 'path'
            $content .= sprintf("file '%s'\n", $file['path']);
        }
        
        File::put($concatFile, $content);
        
        return $concatFile;
    }

    /**
     * Combine segments using ffmpeg
     */
    protected function combineSegments(string $concatFile, string $outputFile): void
    {
        // Build ffmpeg command
        // -f concat: use concat demuxer
        // -safe 0: allow absolute paths
        // -i: input file (concat list)
        // -c copy: copy codecs without re-encoding
        $command = [
            'ffmpeg',
            '-f', 'concat',
            '-safe', '0',
            '-i', $concatFile,
            '-c', 'copy',
            '-movflags', '+faststart', // Optimize for streaming
            '-y', // Overwrite output file
            $outputFile
        ];

        $this->log('Running FFmpeg: ' . implode(' ', $command));
        
        $result = Process::run($command);
        
        if (!$result->successful()) {
            throw new \Exception('FFmpeg failed: ' . $result->errorOutput());
        }
        
        if (!file_exists($outputFile)) {
            throw new \Exception('FFmpeg did not create output file');
        }
    }

    /**
     * Trim video to exact time range
     */
    protected function trimToExactRange(
        string $inputFile, 
        string $outputFile, 
        array $segments,
        Carbon $startTime,
        Carbon $endTime
    ): void {
        // Get the first segment's timestamp to calculate offset
        $firstSegmentTimestamp = $segments[0]['timestamp'];
        $lastSegmentTimestamp = end($segments)['timestamp'];
        
        // Calculate start offset in seconds from the beginning of the first segment
        $startMs = $startTime->timestamp * 1000;
        $endMs = $endTime->timestamp * 1000;
        
        // If we only have one segment or segments are close together, we need to trim
        $startOffset = max(0, ($startMs - $firstSegmentTimestamp) / 1000);
        $duration = ($endMs - $startMs) / 1000;
        
        // Build ffmpeg trim command
        $command = [
            'ffmpeg',
            '-i', $inputFile,
            '-ss', sprintf('%.3f', $startOffset), // Start time in seconds
            '-t', sprintf('%.3f', $duration),      // Duration in seconds
            '-c', 'copy',                          // Copy codec (no re-encoding)
            '-avoid_negative_ts', 'make_zero',     // Fix timestamp issues
            '-movflags', '+faststart',             // Optimize for streaming
            '-y',                                   // Overwrite output
            $outputFile
        ];
        
        $this->log('Trimming video with FFmpeg: ' . implode(' ', $command));
        
        $result = Process::run($command);
        
        if (!$result->successful()) {
            // If copy codec fails (due to keyframe issues), retry with re-encoding
            $this->log('Copy codec failed, retrying with re-encoding...');
            
            $command = [
                'ffmpeg',
                '-i', $inputFile,
                '-ss', sprintf('%.3f', $startOffset),
                '-t', sprintf('%.3f', $duration),
                '-c:v', 'libx264',           // Re-encode video
                '-preset', 'fast',           // Fast encoding
                '-c:a', 'copy',              // Copy audio
                '-movflags', '+faststart',
                '-y',
                $outputFile
            ];
            
            $result = Process::run($command);
            
            if (!$result->successful()) {
                throw new \Exception('FFmpeg trim failed: ' . $result->errorOutput());
            }
        }
        
        if (!file_exists($outputFile)) {
            throw new \Exception('FFmpeg did not create trimmed output file');
        }
    }

    /**
     * Move final file to target storage
     */
    protected function moveToStorage(string $tempFile, string $filename, string $storageDisk): string
    {
        $disk = Storage::disk($storageDisk);
        $targetPath = 'dvr-exports/' . $filename;
        
        // Ensure directory exists
        $disk->makeDirectory('dvr-exports');
        
        // Read file and store in target disk
        $contents = File::get($tempFile);
        $disk->put($targetPath, $contents);
        
        // Return full path based on disk type
        if ($storageDisk === 'public') {
            return storage_path('app/public/' . $targetPath);
        } elseif ($storageDisk === 'local') {
            return storage_path('app/' . $targetPath);
        } else {
            return $targetPath;
        }
    }

    /**
     * Cleanup temporary files
     */
    protected function cleanup(string $path): void
    {
        try {
            if (File::exists($path)) {
                File::deleteDirectory($path);
                $this->log('Cleaned up temporary files');
            }
        } catch (\Exception $e) {
            $this->log('Warning: Failed to cleanup temp files: ' . $e->getMessage(), 'warn');
        }
    }

    /**
     * Log progress message
     */
    protected function log(string $message, string $type = 'info'): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message, $type);
        }
    }
}