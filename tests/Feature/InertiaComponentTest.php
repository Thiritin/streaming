<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Show;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia;

class InertiaComponentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
    }

    /**
     * Test that the ShowsGrid component receives correct props
     */
    public function test_shows_grid_component_props()
    {
        // Create some test data
        $source = Source::create([
            'name' => 'Main Stage',
            'type' => 'hls',
            'location' => 'Main',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        $liveShow = Show::create([
            'title' => 'Live Show',
            'slug' => 'live-show',
            'description' => 'Currently live',
            'source_id' => $source->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
            'viewer_count' => 42,
        ]);

        $upcomingShow = Show::create([
            'title' => 'Upcoming Show',
            'slug' => 'upcoming-show',
            'description' => 'Coming soon',
            'source_id' => $source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addDays(2), // Beyond 24 hours
            'scheduled_end' => now()->addDays(2)->addHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->has('liveShows', 1)
            ->has('upcomingShows', 0) // Only shows in next 24 hours
            ->where('liveShows.0.title', 'Live Show')
            ->where('liveShows.0.status', 'live')
            ->where('liveShows.0.viewer_count', 42)
            ->has('liveShows.0.source')
        );
    }

    /**
     * Test ShowPlayer component with all required props
     */
    public function test_show_player_component_props()
    {
        $source = Source::create([
            'name' => 'Test Source',
            'type' => 'hls',
            'location' => 'Main',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        $show = Show::create([
            'title' => 'Test Show',
            'slug' => 'test-show',
            'description' => 'Test description',
            'source_id' => $source->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $show));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowPlayer')
            ->where('currentShow.id', $show->id)
            ->where('currentShow.title', $show->title)
            ->has('currentShow.source')
            ->where('initialStatus', 'online')
            ->has('initialListeners')
            ->has('chatMessages')
            ->has('rateLimit.maxTries')
            ->has('rateLimit.rateDecay')
            ->has('rateLimit.slowMode')
            ->has('rateLimit.secondsLeft')
        );
    }

    /**
     * Test ExternalStream component receives show data
     */
    public function test_external_stream_component_props()
    {
        $source = Source::create([
            'name' => 'HLS Source',
            'type' => 'hls',
            'location' => 'External',
            'priority' => 1,
            'hls_url' => 'https://example.com/master.m3u8',
            'hls_url_fhd' => 'https://example.com/1080p.m3u8',
            'hls_url_hd' => 'https://example.com/720p.m3u8',
            'hls_url_sd' => 'https://example.com/480p.m3u8',
            'hls_url_ld' => 'https://example.com/320p.m3u8',
        ]);

        $show = Show::create([
            'title' => 'External Show',
            'slug' => 'external-show',
            'description' => 'Show for external viewing',
            'source_id' => $source->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.external', $show));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ExternalStream')
            ->where('show.id', $show->id)
            ->where('show.title', 'External Show')
            ->has('show.source')
            ->has('show.hls_urls')
            ->where('show.can_watch', true)
        );
    }

    /**
     * Test global props are available on all pages
     */
    public function test_global_props_available()
    {
        $pages = [
            route('shows.grid'),
        ];

        foreach ($pages as $page) {
            $response = $this->actingAs($this->user)
                ->get($page);

            $response->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->has('auth')
                ->has('auth.user')
                ->has('auth.user.id')
                ->has('auth.user.name')
                ->has('chat')
                ->has('chat.commands')
                ->has('chat.config')
            );
        }
    }

    /**
     * Test that live shows appear in correct order
     */
    public function test_live_shows_ordered_by_viewer_count()
    {
        $source = Source::create([
            'name' => 'Source',
            'type' => 'hls',
            'location' => 'Main',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        $show1 = Show::create([
            'title' => 'Popular Show',
            'slug' => 'popular',
            'source_id' => $source->id,
            'status' => 'live',
            'viewer_count' => 100,
            'priority' => 1,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $show2 = Show::create([
            'title' => 'Less Popular',
            'slug' => 'less-popular',
            'source_id' => $source->id,
            'status' => 'live',
            'viewer_count' => 50,
            'priority' => 1,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->has('liveShows', 2)
            ->where('liveShows.0.viewer_count', 100)
            ->where('liveShows.1.viewer_count', 50)
        );
    }

    /**
     * Test upcoming shows only include next 24 hours
     */
    public function test_upcoming_shows_filter_24_hours()
    {
        $source = Source::create([
            'name' => 'Source',
            'type' => 'hls',
            'location' => 'Main',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        // Within 24 hours
        $show1 = Show::create([
            'title' => 'Soon Show',
            'slug' => 'soon',
            'source_id' => $source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(12),
            'scheduled_end' => now()->addHours(13),
        ]);

        // Beyond 24 hours
        $show2 = Show::create([
            'title' => 'Later Show',
            'slug' => 'later',
            'source_id' => $source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(48),
            'scheduled_end' => now()->addHours(49),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->has('upcomingShows', 1)
            ->where('upcomingShows.0.title', 'Soon Show')
        );
    }

    /**
     * Test that ended shows redirect properly
     */
    public function test_ended_show_redirects()
    {
        $source = Source::create([
            'name' => 'Source',
            'type' => 'hls',
            'location' => 'Main',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        $show = Show::create([
            'title' => 'Ended Show',
            'slug' => 'ended',
            'source_id' => $source->id,
            'status' => 'ended',
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $show));

        $response->assertRedirect(route('shows.grid'));
    }
}