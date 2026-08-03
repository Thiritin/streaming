<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two deployment switches: AUTH_REQUIRED and CHAT_ENABLED.
 *
 * @see config/auth.php
 * @see config/chat.php
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
        config(['chat.enabled' => false]);

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

    public function test_the_player_still_loads_with_chat_disabled(): void
    {
        config(['chat.enabled' => false]);

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
