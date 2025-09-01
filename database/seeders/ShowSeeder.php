<?php

namespace Database\Seeders;

use App\Models\Show;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ShowSeeder extends Seeder
{
    public function run(): void
    {
        $source = Source::first();

        if (!$source) {
            $this->command->error('No source found. Please run LocalDevelopmentSourceSeeder first.');
            return;
        }

        // if shows already exist, skip seeding
        if (Show::count() > 0) {
            $this->command->info('Shows already exist, skipping seeding.');
            return;
        }

        $now = Carbon::now();

        // Main show - started yesterday, ends in 7 days (LIVE)
        Show::create([
            'title' => 'Main Stage',
            'slug' => 'main-stage',
            'description' => 'Live coverage of the main convention stage featuring panels, performances, and special events throughout the day.',
            'scheduled_start' => $now->copy()->subDay(),
            'scheduled_end' => $now->copy()->addDays(7),
            'actual_start' => $now->copy()->subDay(),
            'source_id' => $source->id,
            'thumbnail_path' => null,
            'status' => 'live',
        ]);

        // Main show subtitles stream (LIVE)
        Show::create([
            'title' => 'Main Stage (Subtitled)',
            'slug' => 'main-stage-subtitled',
            'description' => 'Main convention stage with live subtitles for accessibility. Same content as the main stream with real-time captions.',
            'scheduled_start' => $now->copy()->subDay(),
            'scheduled_end' => $now->copy()->addDays(7),
            'actual_start' => $now->copy()->subDay(),
            'source_id' => $source->id,
            'thumbnail_path' => null,
            'status' => 'live',
        ]);

        // Pop quiz - currently live, ends in an hour (LIVE)
        Show::create([
            'title' => 'Pop Quiz Hour',
            'slug' => 'pop-quiz-hour',
            'description' => 'Join us for the interactive pop quiz! Test your knowledge about the convention, fandom, and special guests.',
            'scheduled_start' => $now->copy()->subHours(2),
            'scheduled_end' => $now->copy()->addHour(),
            'actual_start' => $now->copy()->subHours(2),
            'source_id' => $source->id,
            'thumbnail_path' => null,
            'status' => 'live',
        ]);

        // Schedule 5 sequential events, each running an hour
        $eventStartTime = $now->copy()->addHours(2);

        $events = [
            [
                'title' => 'Artist Alley Showcase',
                'slug' => 'artist-alley-showcase',
                'description' => 'Meet the talented artists and see live demonstrations of various art techniques and styles.',
            ],
            [
                'title' => 'Fursuit Parade',
                'slug' => 'fursuit-parade',
                'description' => 'The annual fursuit parade featuring hundreds of amazing costumes from attendees around the world.',
            ],
            [
                'title' => 'Voice Acting Workshop',
                'slug' => 'voice-acting-workshop',
                'description' => 'Learn the basics of voice acting from industry professionals in this interactive workshop.',
            ],
            [
                'title' => 'Game Show Hour',
                'slug' => 'game-show-hour',
                'description' => 'Contestants compete in various challenges and trivia games with prizes from convention sponsors.',
            ],
            [
                'title' => 'Closing Ceremony',
                'slug' => 'closing-ceremony',
                'description' => 'Join us for the official closing ceremony, awards presentation, and announcement of next year\'s convention.',
            ],
        ];

        foreach ($events as $event) {
            Show::create([
                'title' => $event['title'],
                'slug' => $event['slug'],
                'description' => $event['description'],
                'scheduled_start' => $eventStartTime->copy(),
                'scheduled_end' => $eventStartTime->copy()->addHour(),
                'source_id' => $source->id,
                'thumbnail_path' => null,
                    'status' => 'scheduled',
            ]);

            $eventStartTime->addHour();
        }

        $this->command->info('Show seeder completed successfully!');
    }
}
