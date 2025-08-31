<?php

namespace App\Console\Commands;

use App\Models\Show;
use App\Services\ShowStatisticsService;
use Illuminate\Console\Command;

class RecordShowStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'statistics:record';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Record show statistics for all live shows';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = new ShowStatisticsService();
        
        $liveShows = Show::live()->get();
        
        if ($liveShows->isEmpty()) {
            $this->info('No live shows to record statistics for.');
            return Command::SUCCESS;
        }
        
        foreach ($liveShows as $show) {
            try {
                // First refresh the viewer count from source_users table
                $show->updateViewerCount();
                
                // Then record statistics
                $service->recordStatistics($show);
                
                $this->info("Recorded statistics for show: {$show->title} (Viewers: {$show->viewer_count})");
            } catch (\Exception $e) {
                $this->error("Failed to record statistics for show {$show->title}: {$e->getMessage()}");
            }
        }
        
        return Command::SUCCESS;
    }
}
