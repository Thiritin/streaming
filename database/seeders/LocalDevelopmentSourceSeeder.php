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
        if (! app()->isLocal()) {
            $this->command->info('Skipping local development source seeder (not in local environment)');

            return;
        }

        $this->command->info('Creating local development source...');

        // The primary channel, same name as production so local screenshots match
        $source = Source::updateOrCreate(
            [
                'slug' => 'prime',
            ],
            [
                'name' => 'Prime',
                'description' => 'The main channel: ceremonies, the parade and the big stage shows.',
                'priority' => 100,
                'stream_key' => 'dev_prime_'.Str::random(16),
                'status' => SourceStatusEnum::OFFLINE,
            ]
        );

        $this->command->info("✓ Created Test Source: {$source->name}");
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════════════════════════════════╗');
        $this->command->info('║                        OBS CONFIGURATION SETTINGS                        ║');
        $this->command->info('╠══════════════════════════════════════════════════════════════════════════╣');
        $this->command->info('║ Server URL:  rtmp://localhost:1935/live                                 ║');
        $this->command->info('║ Stream Key:  '.str_pad($source->getObsStreamKey(), 60).' ║');
        $this->command->info('╚══════════════════════════════════════════════════════════════════════════╝');
        $this->command->info('');
        $this->command->info('HLS Playback URLs:');
        $this->command->info('  Master Playlist: http://localhost:8085/live/prime/index.m3u8');
        $this->command->info('  FHD Quality:     http://localhost:8085/live/prime_fhd/index.m3u8');
        $this->command->info('  HD Quality:      http://localhost:8085/live/prime_hd/index.m3u8');
        $this->command->info('  SD Quality:      http://localhost:8085/live/prime_sd/index.m3u8');
        $this->command->info('');
        $this->command->info('Testing with VLC:');
        $this->command->info('  vlc http://localhost:8085/live/prime_fhd/index.m3u8');
        $this->command->info('');
        $this->command->info('Testing with ffplay:');
        $this->command->info('  ffplay http://localhost:8085/live/prime_fhd/index.m3u8');
        $this->command->info('');
    }
}
