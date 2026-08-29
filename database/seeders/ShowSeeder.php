<?php

namespace Database\Seeders;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use App\Models\Source;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A running order worth planning against.
 *
 * Two rules the old seed broke, and the planner made obvious:
 *
 *  1. No two shows on the same source may overlap. A 24/7 "Prime" block spanning eight
 *     days sat under every other show on the same lane, so every block was a clash and
 *     dragging was meaningless.
 *  2. More than one lane. Multi-track planning needs multiple sources to plan across.
 *
 * Times are laid out as a real convention day: a morning slot, an afternoon block, evening
 * shows, and a dance that crosses midnight on the party channel.
 */
class ShowSeeder extends Seeder
{
    /**
     * Extra dev channels, so the planner has tracks to lay shows across. The primary
     * channel comes from LocalDevelopmentSourceSeeder.
     */
    private const EXTRA_SOURCES = [
        ['slug' => 'stage-b', 'name' => 'Stage B', 'priority' => 50, 'description' => 'Panels, workshops and the smaller rooms.'],
        ['slug' => 'dance', 'name' => 'Dance', 'priority' => 20, 'description' => 'The dance stage. Runs late.'],
    ];

    public function run(): void
    {
        if (Show::count() > 0) {
            $this->command->info('Shows already exist, skipping seeding.');

            return;
        }

        $prime = Source::where('slug', 'prime')->first();

        if (! $prime) {
            $this->command->error('No source found. Please run LocalDevelopmentSourceSeeder first.');

            return;
        }

        $stageB = $this->source(self::EXTRA_SOURCES[0]);
        $dance = $this->source(self::EXTRA_SOURCES[1]);

        $today = Carbon::today();

        // Lane, title, day offset, start clock, length in minutes, status.
        $plan = [
            // --- Primary channel: the big room, one show at a time.
            [$prime, 'Opening Ceremony', 0, '10:00', 90, 'ended'],
            [$prime, 'Artist Alley Showcase', 0, '12:00', 60, 'ended'],
            [$prime, 'Fursuit Parade', 0, '14:00', 120, 'live'],
            [$prime, 'Game Show Hour', 0, '17:00', 60, 'scheduled'],
            [$prime, 'Evening Feature', 0, '20:00', 150, 'scheduled'],
            [$prime, 'Morning Warm-up', 1, '09:30', 45, 'scheduled'],
            [$prime, 'Charity Auction', 1, '13:00', 120, 'scheduled'],
            [$prime, 'Closing Ceremony', 2, '15:00', 90, 'scheduled'],

            // --- Second stage: runs alongside, never against itself.
            [$stageB, 'Voice Acting Workshop', 0, '11:00', 75, 'ended'],
            [$stageB, 'Dealers Den Tour', 0, '13:00', 45, 'ended'],
            [$stageB, 'Panel: Art of Fursuiting', 0, '15:30', 60, 'scheduled'],
            [$stageB, 'Writers Round Table', 0, '18:00', 90, 'scheduled'],
            [$stageB, 'Fandom History Talk', 1, '11:00', 60, 'scheduled'],
            [$stageB, 'Photography Workshop', 1, '16:00', 90, 'scheduled'],

            // --- Dance: the late one, crossing midnight. This is the hard-stop case.
            [$dance, 'Warm-up Set', 0, '21:00', 60, 'scheduled'],
            [$dance, 'Headline Dance', 0, '22:30', 210, 'scheduled'],
            [$dance, 'Afterhours', 1, '22:00', 180, 'scheduled'],
        ];

        foreach ($plan as [$source, $title, $dayOffset, $clock, $minutes, $status]) {
            $start = $today->clone()->addDays($dayOffset)->setTimeFromTimeString($clock);
            $end = $start->clone()->addMinutes($minutes);

            Show::create([
                'title' => $title,
                'slug' => Str::slug($title).'-'.$start->format('Y-m-d'),
                'description' => $title.' on '.$source->name.'.',
                'source_id' => $source->id,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                // Only what actually ran has real timestamps; a scheduled show has none.
                'actual_start' => in_array($status, ['ended', 'live'], true) ? $start : null,
                'actual_end' => $status === 'ended' ? $end : null,
                'status' => $status,
                // The dance is the case auto mode exists for: nobody is awake to end it.
                'auto_mode' => $source->is($dance),
                'auto_stop_at' => $source->is($dance) ? $end : null,
                'publish_plan' => 'yes',
                'required_roles' => [],
                'thumbnail_path' => null,
            ]);
        }

        $this->assertNoOverlaps();

        $this->command->info('Seeded '.count($plan).' shows across 3 channels, no overlaps.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function source(array $attributes): Source
    {
        return Source::updateOrCreate(
            ['slug' => $attributes['slug']],
            [
                'name' => $attributes['name'],
                'description' => $attributes['description'],
                'priority' => $attributes['priority'],
                'stream_key' => 'dev_'.$attributes['slug'].'_'.Str::random(16),
                'status' => SourceStatusEnum::OFFLINE,
            ],
        );
    }

    /**
     * The seed's own guard: a clash here would show up as a red block in the planner and
     * send someone hunting for a bug that is really just bad test data.
     */
    private function assertNoOverlaps(): void
    {
        Show::with('source')->get()->groupBy('source_id')->each(function ($shows) {
            $ordered = $shows->sortBy('scheduled_start')->values();

            $ordered->each(function (Show $show, int $index) use ($ordered) {
                $next = $ordered->get($index + 1);

                if ($next && $show->scheduled_end->gt($next->scheduled_start)) {
                    $this->command->warn(
                        "Overlap on {$show->source?->name}: '{$show->title}' ends after '{$next->title}' starts."
                    );
                }
            });
        });
    }
}
