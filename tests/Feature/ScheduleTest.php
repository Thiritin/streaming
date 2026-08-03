<?php

namespace Tests\Feature;

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

        $this->user = User::factory()->create();

        $this->primary = Source::create([
            'name' => 'Prime',
            'slug' => 'prime',
            'priority' => 100,
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

    public function test_primary_channel_owns_the_featured_slot_even_with_fewer_viewers(): void
    {
        $secondary = Source::create([
            'name' => 'Dance Stage',
            'slug' => 'dance-stage',
            'priority' => 10,
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
