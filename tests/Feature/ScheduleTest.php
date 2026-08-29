<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Source $primary;

    protected function setUp(): void
    {
        parent::setUp();

        // Shows are placed hours from now and grouped into days, so a run late in the
        // evening pushes them over midnight and onto the next day's list.
        $this->travelTo(today()->addHours(10));

        $this->user = User::factory()->create();

        $this->primary = Source::create([
            'name' => 'Prime',
            'slug' => 'prime',
            'priority' => 100,
            'status' => SourceStatusEnum::ONLINE,
        ]);
    }

    public function test_schedule_page_groups_shows_by_day_and_channel(): void
    {
        Show::create([
            'title' => 'Opening Ceremony',
            'slug' => 'opening-ceremony',
            'source_id' => $this->primary->id,
            'status' => 'live',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(3),
        ]);

        $response = $this->actingAs($this->user)->get(route('schedule.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Schedule')
            ->has('days', 1)
            ->where('days.0.channels.0.name', 'Prime')
            ->where('days.0.channels.0.shows.0.title', 'Opening Ceremony')
            ->where('primaryChannel', 'Prime')
        );
    }

    /**
     * The publishing decision and the promise to the audience are one column. There used
     * to be a separate announce flag beside it and nothing kept the two in step, so a
     * show could be planned for publication and never announced.
     */
    public function test_the_available_later_badge_reads_the_publish_plan(): void
    {
        Show::create([
            'title' => 'Promised',
            'slug' => 'promised',
            'source_id' => $this->primary->id,
            'status' => 'scheduled',
            'publish_plan' => 'yes',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        Show::create([
            'title' => 'Not promised',
            'slug' => 'not-promised',
            'source_id' => $this->primary->id,
            'status' => 'scheduled',
            'publish_plan' => 'undecided',
            'scheduled_start' => now()->addHours(3),
            'scheduled_end' => now()->addHours(4),
        ]);

        $this->actingAs($this->user)
            ->get(route('schedule.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.channels.0.shows.0.will_be_available', true)
                ->where('days.0.channels.0.shows.1.will_be_available', false));
    }

    public function test_cancelled_shows_stay_on_the_guide_with_their_reason(): void
    {
        Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->primary->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'No stream, technical issue.',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($this->user)
            ->get(route('schedule.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.channels.0.shows.0.title', 'Dance Comp')
                ->where('days.0.channels.0.shows.0.status', 'cancelled')
                ->where('days.0.channels.0.shows.0.cancellation_reason', 'No stream, technical issue.')
            );
    }

    public function test_primary_channel_owns_the_featured_slot_even_with_fewer_viewers(): void
    {
        $secondary = Source::create([
            'name' => 'Dance Stage',
            'slug' => 'dance-stage',
            'priority' => 10,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $secondary->id,
            'status' => 'live',
            'viewer_count' => 5000,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        Show::create([
            'title' => 'Main Stage Show',
            'slug' => 'main-stage-show',
            'source_id' => $this->primary->id,
            'status' => 'live',
            'viewer_count' => 12,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->user)->get(route('shows.grid'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->where('featured.title', 'Main Stage Show')
            ->where('featured.is_primary_channel', true)
            ->where('primaryChannel', 'Prime')
        );
    }

    /**
     * A show can be left marked live on a channel that has stopped sending. The hero
     * autoplays whatever it promotes, so promoting that one puts a dead player on the
     * front page while another channel is actually on air.
     */
    public function test_featured_skips_the_primary_channel_while_it_is_off_air(): void
    {
        $this->primary->update(['status' => SourceStatusEnum::OFFLINE]);

        $secondary = Source::create([
            'name' => 'Dance Stage',
            'slug' => 'dance-stage',
            'priority' => 10,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::create([
            'title' => 'Main Stage Show',
            'slug' => 'main-stage-show',
            'source_id' => $this->primary->id,
            'status' => 'live',
            'viewer_count' => 900,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $secondary->id,
            'status' => 'live',
            'viewer_count' => 5,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get(route('shows.grid'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('featured.title', 'Dance Comp')
                ->where('featured.is_primary_channel', false)
            );
    }

    /**
     * A live show anywhere beats the primary channel's placeholder: the hero is where
     * the site sends someone who wants to watch something now.
     */
    public function test_featured_prefers_a_live_channel_over_the_primarys_next_slot(): void
    {
        $secondary = Source::create([
            'name' => 'Dance Stage',
            'slug' => 'dance-stage',
            'priority' => 10,
            'status' => SourceStatusEnum::ONLINE,
        ]);

        Show::create([
            'title' => 'Later On Prime',
            'slug' => 'later-on-prime',
            'source_id' => $this->primary->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $secondary->id,
            'status' => 'live',
            'viewer_count' => 5,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'actual_start' => now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get(route('shows.grid'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('featured.title', 'Dance Comp')
                ->where('featured.status', 'live')
            );
    }

    public function test_featured_falls_back_to_next_scheduled_show_on_primary_channel(): void
    {
        Show::create([
            'title' => 'Fursuit Parade',
            'slug' => 'fursuit-parade',
            'source_id' => $this->primary->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(2),
            'scheduled_end' => now()->addHours(3),
        ]);

        $response = $this->actingAs($this->user)->get(route('shows.grid'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->where('featured.title', 'Fursuit Parade')
            ->where('featured.status', 'scheduled')
            ->where('featured.hls_url', null)
        );
    }
}
