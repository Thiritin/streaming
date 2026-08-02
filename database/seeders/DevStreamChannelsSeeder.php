<?php

namespace Database\Seeders;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Four channels that match the slugs in scripts/dev-streams.sh, so the browse
 * page, the hero and the programme guide all have real multi-channel data to
 * work with. Safe to re-run: sources and shows are matched on slug.
 */
class DevStreamChannelsSeeder extends Seeder
{
    private const CHANNELS = [
        ['slug' => 'prime', 'name' => 'EF Prime', 'priority' => 100, 'description' => 'The main channel: ceremonies, the parade and the big stage shows.'],
        ['slug' => 'dance-stage', 'name' => 'Dance Stage', 'priority' => 60, 'description' => 'Dance competition, DJ sets and everything after dark.'],
        ['slug' => 'panel-room', 'name' => 'Panel Room', 'priority' => 40, 'description' => 'Talks, workshops and Q&A sessions.'],
        ['slug' => 'art-track', 'name' => 'Art Track', 'priority' => 20, 'description' => 'Art show walkthroughs, live drawing and the auction.'],
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command->warn('DevStreamChannelsSeeder only runs in local.');

            return;
        }

        $now = Carbon::now();

        foreach (self::CHANNELS as $channel) {
            $source = Source::updateOrCreate(
                ['slug' => $channel['slug']],
                [
                    'name' => $channel['name'],
                    'description' => $channel['description'],
                    'priority' => $channel['priority'],
                    'status' => SourceStatusEnum::OFFLINE,
                    'stream_key' => 'dev_'.$channel['slug'].'_'.Str::random(12),
                ]
            );

            $this->command->info("Channel: {$source->name} (priority {$source->priority})");
        }

        $prime = Source::where('slug', 'prime')->first();
        $dance = Source::where('slug', 'dance-stage')->first();
        $panels = Source::where('slug', 'panel-room')->first();
        $art = Source::where('slug', 'art-track')->first();

        // Live now: the primary channel plus two others, so the hero has a
        // featured show and the grid has company.
        $this->show($prime, 'EF Prime', 'Your 24/7 EF TV Channel showing the prime shows and fun content from other conventions.', $now->copy()->subDay(), $now->copy()->addDays(7), 'live', 2148);
        $this->show($prime, 'Opening Ceremony', 'Guest of honour introductions, the charity reveal and the first look at this year\'s theme.', $now->copy()->addMinutes(36), $now->copy()->addMinutes(96), 'scheduled');
        $this->show($panels, 'Fursuit Care Panel', 'Washing, drying, repairs, and how to survive a hot con day in full suit.', $now->copy()->subMinutes(26), $now->copy()->addMinutes(34), 'live', 312);
        $this->show($art, 'Art Show Walkthrough', 'A slow walk past every piece in the art show, with commentary from the artists.', $now->copy()->subMinutes(41), $now->copy()->addMinutes(19), 'live', 96);

        // Starting soon and later today.
        $this->show($prime, 'Fursuit Parade', 'Every suiter in the building, one lap of the hall.', $now->copy()->addMinutes(120), $now->copy()->addMinutes(180), 'scheduled');
        $this->show($dance, 'Dance Competition Prelims', 'First round of the dance competition.', $now->copy()->addMinutes(75), $now->copy()->addMinutes(255), 'scheduled');
        $this->show($prime, 'Charity Auction', 'Bid on the good stuff. All proceeds to this year\'s charity.', $now->copy()->addHours(3), $now->copy()->addHours(5), 'scheduled');
        $this->show($panels, 'Writing Workshop', 'Bring a draft, leave with edits.', $now->copy()->addHours(4), $now->copy()->addHours(5)->addMinutes(30), 'scheduled');
        $this->show($dance, 'Closing Dance', 'The last set of the con.', $now->copy()->addHours(6), $now->copy()->addHours(9), 'scheduled');

        // Tomorrow, so the guide has a second day tab.
        $tomorrow = $now->copy()->addDay()->setTime(11, 0);
        $this->show($prime, 'Closing Ceremony', 'Thank yous, numbers, and next year\'s theme.', $tomorrow->copy(), $tomorrow->copy()->addHours(2), 'scheduled');
        $this->show($art, 'Art Auction', 'The pieces that went to auction, under the hammer.', $tomorrow->copy()->addHours(3), $tomorrow->copy()->addHours(5), 'scheduled');

        $this->command->info('');
        $this->command->info('Channels seeded. Start the video with: ./scripts/dev-streams.sh');
    }

    private function show(?Source $source, string $title, string $description, Carbon $start, Carbon $end, string $status, int $viewers = 0): void
    {
        if (! $source) {
            return;
        }

        Show::updateOrCreate(
            ['slug' => Str::slug($source->slug.'-'.$title)],
            [
                'title' => $title,
                'description' => $description,
                'source_id' => $source->id,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'actual_start' => $status === 'live' ? $start : null,
                'status' => $status,
                'viewer_count' => $viewers,
                'recordable' => true,
            ]
        );
    }
}
