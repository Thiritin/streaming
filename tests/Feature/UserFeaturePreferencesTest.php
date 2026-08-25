<?php

namespace Tests\Feature;

use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A viewer switching features off for themselves, on top of the installation's
 * own switches. The rule under test throughout: a viewer subtracts, never adds.
 *
 * @see Features::forUser()
 */
class UserFeaturePreferencesTest extends TestCase
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

    public function test_the_settings_page_lists_every_feature_the_installation_has_on(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings')
                // chat, emotes, boops, comments: everything a viewer may switch off.
                ->count('featureSettings', 4)
                ->where('featureSettings.0.key', 'chat')
                ->where('featureSettings.0.enabled', true)
            );
    }

    public function test_a_feature_the_installation_switched_off_is_not_offered(): void
    {
        config(['features.boops' => false]);
        Features::flush();

        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->count('featureSettings', 3)
                ->where('featureSettings.0.key', 'chat')
                ->where('featureSettings.1.key', 'emotes')
            );
    }

    public function test_a_viewer_cannot_switch_a_globally_disabled_feature_back_on(): void
    {
        config(['features.boops' => false]);
        Features::flush();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings', ['features' => ['boops' => true]])
            ->assertRedirect();

        $this->assertNull($user->fresh()->feature_preferences);
        $this->assertFalse(Features::enabledFor('boops', $user->fresh()));
    }

    public function test_switching_chat_off_closes_the_chat_endpoints_for_that_viewer_only(): void
    {
        $quiet = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($quiet)
            ->patch('/settings', ['features' => ['chat' => false, 'emotes' => true, 'boops' => true]])
            ->assertRedirect();

        $this->assertSame(['chat' => false], $quiet->fresh()->feature_preferences);

        $this->actingAs($quiet)->get('/show/open-show/chat')->assertNotFound();
        $this->actingAs($quiet)->post('/message/send', [
            'message' => 'hello',
            'source_id' => $this->source->id,
        ])->assertNotFound();

        $this->actingAs($other)->get('/show/open-show/chat')->assertOk();
    }

    public function test_switching_chat_off_takes_that_viewers_emotes_with_it(): void
    {
        $user = User::factory()->create();
        $user->feature_preferences = ['chat' => false];
        $user->save();

        $this->actingAs($user)->get('/emotes')->assertNotFound();

        $this->actingAs($user)
            ->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('features.chat', false)
                ->where('features.emotes', false)
                ->where('chat.enabled', false)
            );
    }

    public function test_switching_emotes_off_leaves_chat_up(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings', ['features' => ['chat' => true, 'emotes' => false, 'boops' => true]])
            ->assertRedirect();

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

    public function test_switching_boops_off_closes_the_boop_endpoint_for_that_viewer(): void
    {
        $quiet = User::factory()->create();
        $quiet->feature_preferences = ['boops' => false];
        $quiet->save();

        $this->actingAs($quiet)
            ->post('/show/open-show/boop', ['count' => 1])
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->post('/show/open-show/boop', ['count' => 1])
            ->assertOk();
    }

    public function test_switching_a_feature_back_on_clears_the_stored_preference(): void
    {
        $user = User::factory()->create();
        $user->feature_preferences = ['chat' => false, 'boops' => false];
        $user->save();

        $this->actingAs($user)
            ->patch('/settings', ['features' => ['chat' => true, 'emotes' => true, 'boops' => false]])
            ->assertRedirect();

        $this->assertSame(['boops' => false], $user->fresh()->feature_preferences);
    }

    public function test_a_guest_sees_the_installations_switches_and_has_no_settings_page(): void
    {
        config(['auth.required' => false]);

        $this->get('/settings')->assertRedirect(route('login'));

        $this->get('/show/open-show')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('features.boops', true));
    }
}
