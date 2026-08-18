<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deployment switches: AUTH_REQUIRED, plus the feature flags edited in
 * /manage > Settings > Features.
 *
 * @see config/auth.php
 * @see config/features.php
 */
class OptionalLoginAndChatToggleTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

    protected Show $show;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Source::create([
            'name' => 'Main',
            'type' => 'hls',
            'location' => 'Main Stage',
            'priority' => 1,
            'hls_url' => 'https://example.com/stream.m3u8',
        ]);

        $this->show = Show::create([
            'title' => 'Open Show',
            'slug' => 'open-show',
            'source_id' => $this->source->id,
            'status' => 'live',
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now()->addHour(),
        ]);
    }

    public function test_mandatory_login_sends_a_guest_to_the_login_screen(): void
    {
        config(['auth.required' => true]);

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/show/open-show')->assertRedirect(route('login'));
    }

    public function test_optional_login_lets_a_guest_browse_and_watch(): void
    {
        config(['auth.required' => false]);

        $this->get('/')->assertOk();
        $this->get('/schedule')->assertOk();
        $this->get('/archive')->assertOk();
        $this->get('/show/open-show')->assertOk();
    }

    public function test_a_restricted_show_stays_hidden_from_a_guest_when_login_is_optional(): void
    {
        config(['auth.required' => false]);

        $this->show->update(['required_roles' => ['sponsor']]);

        $this->get('/show/open-show')->assertRedirect(route('shows.grid'));
    }

    public function test_chat_needs_a_sign_in_even_when_login_is_optional(): void
    {
        config(['auth.required' => false]);

        $this->post('/message/send', [
            'message' => 'hello',
            'source_id' => $this->source->id,
        ])->assertRedirect(route('login'));
    }

    public function test_disabling_chat_takes_the_chat_endpoints_away(): void
    {
        $this->disable('chat');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/message/send', [
                'message' => 'hello',
                'source_id' => $this->source->id,
            ])
            ->assertNotFound();

        $this->actingAs($user)->get('/emotes')->assertNotFound();
        $this->actingAs($user)->get('/show/open-show/chat')->assertNotFound();
    }

    public function test_disabling_emotes_leaves_chat_up(): void
    {
        $this->disable('emotes');

        $user = User::factory()->create();

        $this->actingAs($user)->get('/emotes')->assertNotFound();
        $this->actingAs($user)->get('/show/open-show/chat')->assertOk();

        $this->actingAs($user)
            ->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('features.chat', true)
                ->where('features.emotes', false)
                ->where('chat.emotes.list', [])
            );
    }

    public function test_disabling_chat_takes_emotes_with_it(): void
    {
        $this->disable('chat');

        $this->actingAs(User::factory()->create())
            ->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('features.emotes', false));
    }

    public function test_disabling_boops_closes_the_boop_endpoint(): void
    {
        $this->disable('boops');

        $this->actingAs(User::factory()->create())
            ->post('/show/open-show/boop', ['count' => 1])
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('features.boops', false));
    }

    /**
     * Turn a feature off the way the settings page does, and drop the resolved
     * set so the request sees it.
     */
    private function disable(string $feature): void
    {
        config(["features.{$feature}" => false]);

        Features::flush();
    }

    public function test_the_player_still_loads_with_chat_disabled(): void
    {
        $this->disable('chat');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ShowPlayer')
                ->where('chatMessages', [])
                ->where('chat.enabled', false)
            );
    }
}
