<?php

namespace Database\Seeders;

use App\Enum\SourceStatusEnum;
use App\Models\Source;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocalDevelopmentSourceSeeder extends Seeder
{
    /**
     * Run the database seeds for local development environment.
     * Creates a test source for streaming.
     */
    public function run(): void
    {
        // Only run in local environment
        if (!app()->isLocal()) {
            $this->command->info('Skipping local development source seeder (not in local environment)');
            return;
        }

        $this->command->info('Creating local development test source...');

        // Create a test source
        $source = Source::updateOrCreate(
            [
                'slug' => 'test-stream',
            ],
            [
                'name' => 'Test Stream',
                'description' => 'Local development test stream for testing RTMP ingress and HLS distribution',
                'stream_key' => 'test_secret_key_' . Str::random(16),
                'status' => SourceStatusEnum::OFFLINE,
            ]
        );

        $this->command->info("✓ Created Test Source: {$source->name}");
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->command->info('║                        OBS CONFIGURATION SETTINGS                        ║');
        $this->command->info('╠══════════════════════════════════════════════════════════════════════════╣');
        $this->command->info('║ Server URL:  rtmp://localhost:1935/live                                 ║');
        $this->command->info('║ Stream Key:  ' . str_pad($source->getObsStreamKey(), 60) . ' ║');
        $this->command->info('╚══════════════════════════════════════════════════════════════════════════╝');
        $this->command->info('');
        $this->command->info('HLS Playback URLs:');
        $this->command->info('  Master Playlist: http://localhost:8085/live/test-stream/index.m3u8');
        $this->command->info('  FHD Quality:     http://localhost:8085/live/test-stream_fhd/index.m3u8');
        $this->command->info('  HD Quality:      http://localhost:8085/live/test-stream_hd/index.m3u8');
        $this->command->info('  SD Quality:      http://localhost:8085/live/test-stream_sd/index.m3u8');
        $this->command->info('');
        $this->command->info('Testing with VLC:');
        $this->command->info('  vlc http://localhost:8085/live/test-stream_fhd/index.m3u8');
        $this->command->info('');
        $this->command->info('Testing with ffplay:');
        $this->command->info('  ffplay http://localhost:8085/live/test-stream_fhd/index.m3u8');
        $this->command->info('');
    }
}