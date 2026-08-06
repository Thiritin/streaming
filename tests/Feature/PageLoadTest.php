<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PageLoadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a basic user for authenticated tests
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'sub' => 'test-sub-123',
        ]);

        // Create a source
        $source = Source::create([
            'name' => 'Test Source',
            'type' => 'hls',
            'location' => 'Main Stage',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        // Create a show
        $this->show = Show::create([
            'title' => 'Test Show',
            'slug' => 'test-show',
            'description' => 'Test show description',
            'source_id' => $source->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);
    }

    /**
     * Test that the shows grid page loads successfully
     */
    public function test_shows_grid_page_loads()
    {
        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowsGrid')
            ->has('liveShows')
            ->has('upcomingShows')
            ->has('currentTime')
        );
    }

    /**
     * Test that the show player page loads successfully
     */
    public function test_show_player_page_loads()
    {
        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowPlayer')
            ->has('currentShow')
            ->has('availableShows')
            ->has('initialHlsUrl')
            ->has('initialStatus')
            ->has('initialListeners')
            ->has('chatMessages')
            ->has('chatSettings')
            ->has('chatState')
        );
    }

    /**
     * Test that the external stream page loads successfully
     */
    public function test_external_stream_page_loads()
    {
        $response = $this->actingAs($this->user)
            ->get(route('show.external', $this->show));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ExternalStream')
            ->has('show')
            ->where('show.id', $this->show->id)
            ->where('show.title', $this->show->title)
        );
    }

    /**
     * Test that unauthenticated users are redirected to login
     */
    public function test_unauthenticated_users_redirected_to_login()
    {
        $response = $this->get(route('shows.grid'));
        $response->assertRedirect(route('login'));

        $response = $this->get(route('show.view', $this->show));
        $response->assertRedirect(route('login'));

        $response = $this->get(route('show.external', $this->show));
        $response->assertRedirect(route('login'));
    }

    /**
     * Test that global chat data is available in props
     */
    public function test_global_chat_data_available()
    {
        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('chat')
            ->has('chat.commands')
            ->has('chat.config')
            ->has('chat.config.maxMessageLength')
            ->has('chat.config.allowedDomains')
        );
    }

    /**
     * Test that auth data is properly structured
     */
    public function test_auth_data_structure()
    {
        $response = $this->actingAs($this->user)
            ->get(route('shows.grid'));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->has('auth')
            ->has('auth.user')
            ->where('auth.user.id', $this->user->id)
            ->where('auth.user.name', $this->user->name)
            ->has('auth.can_access_filament')
        );
    }

    /**
     * An ended show keeps its own page rather than bouncing to the grid.
     *
     * It used to redirect. ShowPlayer now renders ShowEndedStatusPage in place of the
     * video, which keeps the title, description and any recording link on a URL people
     * have already shared.
     */
    public function test_ended_show_page_loads()
    {
        $this->show->update(['status' => 'ended']);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ShowPlayer')
            ->where('currentShow.status', 'ended')
        );
    }

    /**
     * Same for a show that has not started: the page explains when it will.
     */
    public function test_scheduled_show_page_loads_with_scheduled_status()
    {
        $this->show->update([
            'status' => 'scheduled',
            'scheduled_start' => now()->addDays(2), // Far in the future
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ShowPlayer')
            ->where('currentShow.status', 'scheduled')
        );
    }

    /**
     * Test that multiple shows appear in available shows
     */
    public function test_multiple_shows_in_available_list()
    {
        // Create additional shows
        $source2 = Source::create([
            'name' => 'Second Source',
            'type' => 'hls',
            'location' => 'Side Stage',
            'priority' => 2,
            'hls_url' => 'https://example.com/stream2.m3u8',
        ]);

        $show2 = Show::create([
            'title' => 'Second Show',
            'slug' => 'second-show',
            'description' => 'Second show description',
            'source_id' => $source2->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('ShowPlayer')
            ->has('availableShows', 2)
        );
    }

    /**
     * Test that message sending endpoint exists and requires auth
     */
    public function test_message_send_endpoint_requires_auth()
    {
        $response = $this->post(route('message.send'), [
            'message' => 'Test message',
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * Test that authenticated user can access message endpoint
     */
    public function test_authenticated_user_can_send_message()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('message.send'), [
                'message' => 'Test message',
                'source_id' => $this->show->source_id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message' => [
                'id',
                'body',
                'user',
                'badges',
            ],
            'limits' => [
                'slow_mode_seconds',
                'max_tries',
                'rate_decay',
                'seconds_left',
            ],
        ]);
    }
}
