<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRecordingJob;
use App\Models\Recording;
use App\Services\RecordingService;
use Illuminate\Console\Command;

class ProcessRecordings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'recordings:process 
                            {--id= : Process a specific recording by ID}
                            {--all : Process all recordings without duration or thumbnail}
                            {--queue : Use queue for processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recordings to extract duration and generate thumbnails';

    /**
     * Execute the console command.
     */
    public function handle(RecordingService $recordingService)
    {
        // Check if ffmpeg is available
        if (! $recordingService->isFFmpegAvailable()) {
            $this->error('FFmpeg and FFprobe are required but not found in system PATH.');

            return 1;
        }

        // Process specific recording by ID
        if ($recordingId = $this->option('id')) {
            $recording = Recording::find($recordingId);
            if (! $recording) {
                $this->error("Recording with ID {$recordingId} not found.");

                return 1;
            }

            $this->info("Processing recording: {$recording->title}");

            if ($this->option('queue')) {
                ProcessRecordingJob::dispatch($recording);
                $this->info("Job dispatched for recording {$recordingId}");
            } else {
                $recordingService->processRecording($recording);
                $this->info("Recording {$recordingId} processed successfully.");
            }

            return 0;
        }

        // Process all recordings without duration or thumbnail
        if ($this->option('all')) {
            $recordings = Recording::where(function ($query) {
                $query->whereNull('duration')
                    ->orWhereNull('thumbnail_path');
            })->get();

            if ($recordings->isEmpty()) {
                $this->info('No recordings need processing.');

                return 0;
            }

            $this->info("Found {$recordings->count()} recordings to process.");
            $bar = $this->output->createProgressBar($recordings->count());
            $bar->start();

            foreach ($recordings as $recording) {
                if ($this->option('queue')) {
                    ProcessRecordingJob::dispatch($recording);
                } else {
                    $recordingService->processRecording($recording);
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            if ($this->option('queue')) {
                $this->info("Jobs dispatched for {$recordings->count()} recordings.");
            } else {
                $this->info("{$recordings->count()} recordings processed successfully.");
            }

            return 0;
        }

        // Show usage if no options provided
        $this->info('Usage:');
        $this->line('  Process specific recording: php artisan recordings:process --id=1');
        $this->line('  Process all unprocessed: php artisan recordings:process --all');
        $this->line('  Process using queue: php artisan recordings:process --all --queue');

        return 0;
    }
}
