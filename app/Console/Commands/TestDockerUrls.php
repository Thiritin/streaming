<?php

namespace App\Console\Commands;

use App\Models\Source;
use Illuminate\Console\Command;

class TestDockerUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:docker-urls {source? : Source ID or slug to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Docker URL generation for thumbnail capture';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🐳 Testing Docker URL Configuration');
        $this->newLine();
        
        // Display environment info
        $this->table(
            ['Setting', 'Value'],
            [
                ['Environment', app()->environment()],
                ['Running in Console', app()->runningInConsole() ? 'Yes' : 'No'],
                ['Laravel Sail', env('LARAVEL_SAIL') ? 'Yes' : 'No'],
                ['Docker HLS Host', config('stream.docker.hls_host')],
                ['Docker HLS Port', config('stream.docker.hls_port')],
                ['Regular HLS Host', env('HLS_EDGE_HOST')],
                ['Regular HLS Port', env('HLS_EDGE_PORT')],
            ]
        );
        
        $this->newLine();
        
        // Get a source to test with
        $sourceId = $this->argument('source');
        if ($sourceId) {
            $source = Source::where('id', $sourceId)
                ->orWhere('slug', $sourceId)
                ->first();
        } else {
            $source = Source::first();
        }
        
        if (!$source) {
            $this->error('No source found. Please create a source first.');
            return 1;
        }
        
        $this->info("Testing with source: {$source->name} (slug: {$source->slug})");
        $this->newLine();
        
        // Test regular URLs
        $this->info('📡 Regular HLS URLs (for browser access):');
        $regularUrls = $source->getHlsUrls();
        foreach ($regularUrls as $quality => $url) {
            $this->line("  {$quality}: {$url}");
        }
        
        $this->newLine();
        
        // Test internal URLs
        $this->info('🔧 Internal HLS URLs (for Docker container access):');
        $internalUrls = $source->getInternalHlsUrls();
        foreach ($internalUrls as $quality => $url) {
            $this->line("  {$quality}: {$url}");
        }
        
        $this->newLine();
        
        // Check Docker detection
        $isDocker = $source->isRunningInDocker();
        $this->info('🔍 Docker Detection:');
        $this->line('  Running in Docker: ' . ($isDocker ? 'Yes' : 'No'));
        $this->line('  /.dockerenv exists: ' . (file_exists('/.dockerenv') ? 'Yes' : 'No'));
        $this->line('  /proc/1/cgroup check: ' . 
            ((file_exists('/proc/1/cgroup') && 
              str_contains(file_get_contents('/proc/1/cgroup'), 'docker')) ? 'Yes' : 'No'));
        
        $this->newLine();
        
        // Show what ThumbnailService would use
        $this->info('📸 ThumbnailService would use:');
        $urlsForThumbnail = app()->runningInConsole() 
            ? $source->getInternalHlsUrls() 
            : $source->getHlsUrls();
        $streamUrl = $urlsForThumbnail['sd'] ?? $urlsForThumbnail['master'] ?? $urlsForThumbnail['stream'];
        $this->line("  URL: {$streamUrl}");
        
        $this->newLine();
        $this->info('✅ Test complete!');
        
        return 0;
    }
}