<?php

namespace App\Console\Commands;

use App\Services\DvrExtractorService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ExtractDvrSegments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dvr:extract
                            {--stream= : The stream name (e.g., main, outdoor-stage)}
                            {--start= : Start datetime (format: Y-m-d H:i:s)}
                            {--end= : End datetime (format: Y-m-d H:i:s)}
                            {--output= : Output filename (optional)}
                            {--storage=public : Target storage disk (default: public)}
                            {--dry-run : Preview segments without downloading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract and combine DVR segments from a specific time range';

    protected DvrExtractorService $extractor;

    public function __construct(DvrExtractorService $extractor)
    {
        parent::__construct();
        $this->extractor = $extractor;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Validate required options
        if (!$this->option('stream')) {
            $this->error('The --stream option is required.');
            return 1;
        }

        if (!$this->option('start')) {
            $this->error('The --start option is required.');
            return 1;
        }

        if (!$this->option('end')) {
            $this->error('The --end option is required.');
            return 1;
        }

        $stream = $this->option('stream');
        $dryRun = $this->option('dry-run');

        // Parse dates - always interpret as Europe/Berlin timezone
        try {
            $startTime = Carbon::parse($this->option('start'), 'Europe/Berlin');
            $endTime = Carbon::parse($this->option('end'), 'Europe/Berlin');
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use format: Y-m-d H:i:s');
            return 1;
        }

        // Validate time range
        if ($endTime->lessThanOrEqualTo($startTime)) {
            $this->error('End time must be after start time.');
            return 1;
        }

        // Calculate duration
        $duration = $startTime->diffInSeconds($endTime);
        $this->info("Extracting DVR segments for stream: {$stream}");
        $this->info("Time range: {$startTime->format('Y-m-d H:i:s')} to {$endTime->format('Y-m-d H:i:s')} (Europe/Berlin)");
        $this->info("UTC range: {$startTime->utc()->format('Y-m-d H:i:s')} to {$endTime->utc()->format('Y-m-d H:i:s')}");
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        $seconds = $duration % 60;
        $this->info(sprintf("Duration: %02d:%02d:%02d", $hours, $minutes, $seconds));
        $this->newLine();

        // Generate default output filename if not provided
        $outputFilename = $this->option('output') ?: sprintf(
            '%s_%s_%s.mp4',
            $stream,
            $startTime->format('Ymd_His'),
            $endTime->format('His')
        );

        $targetStorage = $this->option('storage');

        try {
            if ($dryRun) {
                $this->info('🔍 DRY RUN MODE - Previewing segments...');
                $this->info('Looking for segments between ' . ($startTime->timestamp * 1000) . ' and ' . ($endTime->timestamp * 1000));
                $segments = $this->extractor->findSegments($stream, $startTime, $endTime);
                
                if (empty($segments)) {
                    $this->warn('No segments found in the specified time range.');
                    return 0;
                }

                $this->info("Found " . count($segments) . " segments:");
                $this->table(
                    ['Segment', 'Size', 'Timestamp'],
                    array_map(function ($segment) {
                        return [
                            basename($segment['path']),
                            $this->formatBytes($segment['size']),
                            Carbon::createFromTimestampMs($segment['timestamp'])->format('Y-m-d H:i:s')
                        ];
                    }, $segments)
                );

                $totalSize = array_sum(array_column($segments, 'size'));
                $this->info("Total size to download: " . $this->formatBytes($totalSize));
            } else {
                // Perform actual extraction
                $this->info('Starting extraction process...');
                
                $outputPath = $this->extractor->extract(
                    $stream,
                    $startTime,
                    $endTime,
                    $outputFilename,
                    $targetStorage,
                    function ($message, $type = 'info') {
                        match($type) {
                            'error' => $this->error($message),
                            'warn' => $this->warn($message),
                            'success' => $this->info("✅ " . $message),
                            default => $this->info($message),
                        };
                    }
                );

                $this->newLine();
                $this->info("✅ Extraction complete!");
                $this->info("Output file: {$outputPath}");
                
                // Show file size
                if (file_exists($outputPath)) {
                    $this->info("File size: " . $this->formatBytes(filesize($outputPath)));
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Extraction failed: ' . $e->getMessage());
            
            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            }
            
            return 1;
        }
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}