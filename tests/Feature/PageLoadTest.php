<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Show;
use App\Models\Source;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Inertia\Testing\AssertableInertia;

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
            ->has('initialHlsUrls')
            ->has('initialStatus')
            ->has('initialListeners')
            ->has('chatMessages')
            ->has('rateLimit')
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
     * Test that show with ended status can still be viewed
     */
    public function test_ended_show_page_loads()
    {
        $this->show->update(['status' => 'ended']);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        // Should redirect because show is ended and user can't watch
        $response->assertRedirect(route('shows.grid'));
    }

    /**
     * Test that show with scheduled status redirects if not viewable
     */
    public function test_scheduled_show_redirects_if_not_viewable()
    {
        $this->show->update([
            'status' => 'scheduled',
            'scheduled_start' => now()->addDays(2), // Far in the future
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('show.view', $this->show));

        // Should redirect because scheduled show far in future can't be watched
        $response->assertRedirect(route('shows.grid'));
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
            'message' => 'Test message'
        ]);

        $response->assertRedirect(route('login'));
    }

    /**
     * Test that authenticated user can access message endpoint
     */
    public function test_authenticated_user_can_send_message()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => 'Test message'
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'rateLimit' => [
                'maxTries',
                'secondsLeft',
                'rateDecay',
                'slowMode',
            ]
        ]);
    }
}