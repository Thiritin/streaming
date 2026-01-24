<?php

namespace Database\Seeders;

use App\Models\Recording;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class RecordingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $recordings = [
            [
                'title' => 'Opening Ceremony 2024',
                'slug' => 'opening-ceremony-2024',
                'description' => 'The grand opening ceremony of Eurofurence 2024, featuring special guests, announcements, and a spectacular light show.',
                'date' => Carbon::now()->subDays(7)->setHour(19)->setMinute(0),
                'duration' => 5400, // 1.5 hours
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 15234,
                'is_published' => true,
            ],
            [
                'title' => 'Fursuit Parade',
                'slug' => 'fursuit-parade-2024',
                'description' => 'The annual fursuit parade featuring hundreds of amazing fursuits from around the world.',
                'date' => Carbon::now()->subDays(5)->setHour(14)->setMinute(30),
                'duration' => 7200, // 2 hours
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 28456,
                'is_published' => true,
            ],
            [
                'title' => 'Art Auction Live',
                'slug' => 'art-auction-2024',
                'description' => 'Live coverage of the charity art auction with amazing artworks from talented artists.',
                'date' => Carbon::now()->subDays(4)->setHour(20)->setMinute(0),
                'duration' => 10800, // 3 hours
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 8923,
                'is_published' => true,
            ],
            [
                'title' => 'Dance Competition Finals',
                'slug' => 'dance-competition-finals-2024',
                'description' => 'The thrilling finals of the dance competition featuring the best performers.',
                'date' => Carbon::now()->subDays(3)->setHour(21)->setMinute(0),
                'duration' => 5400, // 1.5 hours
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 12567,
                'is_published' => true,
            ],
            [
                'title' => 'Guest of Honor Interview',
                'slug' => 'goh-interview-2024',
                'description' => 'An exclusive interview with our Guest of Honor discussing their work and experiences in the fandom.',
                'date' => Carbon::now()->subDays(2)->setHour(15)->setMinute(0),
                'duration' => 3600, // 1 hour
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 6789,
                'is_published' => true,
            ],
            [
                'title' => 'Closing Ceremony 2024',
                'slug' => 'closing-ceremony-2024',
                'description' => 'The emotional closing ceremony of Eurofurence 2024. See you next year!',
                'date' => Carbon::now()->subDays(1)->setHour(18)->setMinute(0),
                'duration' => 3600, // 1 hour
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 19876,
                'is_published' => true,
            ],
            [
                'title' => 'Behind the Scenes Documentary',
                'slug' => 'behind-the-scenes-2024',
                'description' => 'A special documentary showing the preparation and hard work that goes into making Eurofurence happen.',
                'date' => Carbon::now()->subHours(12),
                'duration' => 2700, // 45 minutes
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 3456,
                'is_published' => true,
            ],
            [
                'title' => 'Panel: Animation in the Fandom',
                'slug' => 'animation-panel-2024',
                'description' => 'Industry professionals discuss animation techniques and opportunities in the furry fandom.',
                'date' => Carbon::now()->subDays(6)->setHour(11)->setMinute(0),
                'duration' => 5400, // 1.5 hours
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 4521,
                'is_published' => true,
            ],
            [
                'title' => 'Staff Meeting Recording',
                'slug' => 'staff-meeting-2024',
                'description' => 'Internal staff meeting recording - staff only access.',
                'date' => Carbon::now()->subDays(8)->setHour(10)->setMinute(0),
                'duration' => 3600, // 1 hour
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 156,
                'is_published' => true,
                'required_roles' => ['staff', 'admin'],
            ],
            [
                'title' => 'Tech Team Debrief',
                'slug' => 'tech-team-debrief-2024',
                'description' => 'Technical team post-event debrief and lessons learned - admin only.',
                'date' => Carbon::now()->subHours(6),
                'duration' => 2400, // 40 minutes
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 23,
                'is_published' => true,
                'required_roles' => ['admin'],
            ],
            [
                'title' => 'Unpublished Test Stream',
                'slug' => 'unpublished-test',
                'description' => 'This is a test recording that should not be visible to regular users.',
                'date' => Carbon::now()->subDays(10),
                'duration' => 300,
                'm3u8_url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
                'views' => 0,
                'is_published' => false,
            ],
        ];

        foreach ($recordings as $recording) {
            Recording::updateOrCreate(
                ['slug' => $recording['slug']],
                $recording
            );
        }
    }
}
