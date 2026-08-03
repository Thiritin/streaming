<?php

namespace Tests\Feature;

use App\Events\Chat\Broadcasts\ChatMessageEvent;
use App\Events\Chat\Broadcasts\ChatMessagesDeletedEvent;
use App\Events\Chat\Broadcasts\ChatNoticeEvent;
use App\Models\ChatBan;
use App\Models\Emote;
use App\Models\Message;
use App\Models\Role;
use App\Models\Source;
use App\Models\Timeout;
use App\Models\User;
use App\Services\Chat\ChatModerationService;
use App\Services\Chat\ChatSettingsService;
use App\Services\ChatMessageSanitizer;
use App\Services\EmoteService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $moderator;

    protected User $admin;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = Source::factory()->create();

        $this->user = User::factory()->create(['name' => 'Regular User']);
        $this->moderator = $this->userWithRole('Moderator', 'moderator', ['chat.moderate'], 50);
        $this->admin = $this->userWithRole('Admin', 'admin', ['admin.access', 'chat.moderate', 'chat.ban'], 100);
    }

    protected function userWithRole(string $name, string $slug, array $permissions, int $priority): User
    {
        $role = Role::create([
            'name' => $name,
            'slug' => $slug,
            'chat_color' => '#ff0000',
            'permissions' => $permissions,
            'priority' => $priority,
        ]);

        $user = User::factory()->create(['name' => $name.' User']);
        $user->roles()->attach($role);

        return $user->fresh();
    }

    protected function sendAs(User $user, string $message, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('message.send'), array_merge([
            'message' => $message,
            'source_id' => $this->source->id,
        ], $extra));
    }

    public function test_sanitizer_removes_unwanted_urls(): void
    {
        config(['chat.allowed_domains' => ['example.org']]);

        $sanitized = (new ChatMessageSanitizer)->sanitize('Check out https://google.com and https://example.org');

        $this->assertStringContainsString('[url removed]', $sanitized);
        $this->assertStringContainsString('example.org', $sanitized);
        $this->assertStringNotContainsString('google.com', $sanitized);
    }

    public function test_sanitizer_keeps_message_as_plain_text(): void
    {
        // Escaping is the client's job now, so the stored text is untouched.
        $sanitized = (new ChatMessageSanitizer)->sanitize('<b>bold</b> & "quoted"');

        $this->assertSame('<b>bold</b> & "quoted"', $sanitized);
    }

    public function test_sanitizer_strips_zero_width_characters(): void
    {
        $sanitized = (new ChatMessageSanitizer)->sanitize("he\u{200B}llo\u{202E}");

        $this->assertSame('hello', $sanitized);
    }

    public function test_sanitizer_limits_length(): void
    {
        $sanitized = (new ChatMessageSanitizer)->sanitize(str_repeat('a', 900));

        $this->assertSame(config('chat.default.maxMessageLength'), mb_strlen($sanitized));
    }

    public function test_user_can_send_a_message(): void
    {
        Event::fake([ChatMessageEvent::class]);

        $response = $this->sendAs($this->user, 'Hello world!');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('message.body', 'Hello world!');

        $this->assertDatabaseHas('messages', [
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'message' => 'Hello world!',
            'is_command' => false,
        ]);

        Event::assertDispatched(ChatMessageEvent::class);
    }

    public function test_message_payload_carries_author_metadata(): void
    {
        $response = $this->sendAs($this->moderator, 'hi');

        $response->assertOk()
            ->assertJsonPath('message.user.id', $this->moderator->id)
            ->assertJsonPath('message.badges.0.slug', 'moderator');
    }

    public function test_reply_is_linked_to_the_parent_message(): void
    {
        $parent = Message::create([
            'message' => 'first',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        $response = $this->sendAs($this->moderator, 'answer', ['reply_to_id' => $parent->id]);

        $response->assertOk()->assertJsonPath('message.reply_to.id', $parent->id);
    }

    public function test_timed_out_user_cannot_send(): void
    {
        Timeout::create([
            'user_id' => $this->user->id,
            'issued_by_user_id' => $this->moderator->id,
            'expires_at' => now()->addMinutes(5),
            'reason' => 'spam',
        ]);

        $this->sendAs($this->user, 'let me in')
            ->assertForbidden()
            ->assertJsonPath('error', 'user_timed_out');
    }

    public function test_banned_user_cannot_send(): void
    {
        ChatBan::create([
            'user_id' => $this->user->id,
            'banned_by_user_id' => $this->admin->id,
            'reason' => 'raiding',
        ]);

        $this->sendAs($this->user, 'hello')
            ->assertForbidden()
            ->assertJsonPath('error', 'user_banned');
    }

    public function test_slow_mode_allows_one_message_per_interval(): void
    {
        app(ChatSettingsService::class)->update(['slow_mode_seconds' => 30], $this->source->id);

        $this->sendAs($this->user, 'first')->assertOk();
        $this->sendAs($this->user, 'second')
            ->assertStatus(429)
            ->assertJsonPath('error', 'rate_limit_hit');
    }

    public function test_moderators_bypass_slow_mode(): void
    {
        app(ChatSettingsService::class)->update(['slow_mode_seconds' => 30], $this->source->id);

        $this->sendAs($this->moderator, 'first')->assertOk();
        $this->sendAs($this->moderator, 'second')->assertOk();
    }

    public function test_emote_only_mode_rejects_anything_but_real_emotes(): void
    {
        Emote::create([
            'name' => 'wave',
            'url' => 'https://cdn.test/wave.png',
            'uploaded_by_user_id' => $this->admin->id,
            'is_approved' => true,
            'is_global' => true,
        ]);
        app(EmoteService::class)->clearCache();

        app(ChatSettingsService::class)->update(['emote_only' => true], $this->source->id);

        $this->sendAs($this->user, 'plain words')
            ->assertForbidden()
            ->assertJsonPath('error', 'emote_only');

        // An unknown code is still text, not an emote.
        $this->sendAs($this->user, ':not_a_real_emote:')
            ->assertForbidden()
            ->assertJsonPath('error', 'emote_only');

        $this->sendAs($this->user, ':wave: :wave:')->assertOk();
    }

    public function test_author_can_delete_own_message_but_strangers_cannot(): void
    {
        $message = Message::create([
            'message' => 'oops',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        $other = User::factory()->create();

        $this->actingAs($other)->deleteJson("/messages/{$message->id}")->assertForbidden();

        $this->actingAs($this->user)->deleteJson("/messages/{$message->id}")->assertOk();
        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    public function test_moderator_can_delete_any_message(): void
    {
        Event::fake([ChatMessagesDeletedEvent::class]);

        $message = Message::create([
            'message' => 'spam',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        $this->actingAs($this->moderator)->deleteJson("/messages/{$message->id}")->assertOk();

        $this->assertSoftDeleted('messages', ['id' => $message->id, 'deleted_by_user_id' => $this->moderator->id]);
        Event::assertDispatched(ChatMessagesDeletedEvent::class);
    }

    public function test_moderator_can_timeout_a_user_through_the_mod_endpoint(): void
    {
        $this->actingAs($this->moderator)
            ->postJson(route('chat.moderation.timeout'), [
                'user_id' => $this->user->id,
                'seconds' => 600,
                'reason' => 'spam',
                'source_id' => $this->source->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('timeouts', ['user_id' => $this->user->id, 'reason' => 'spam']);
        $this->assertDatabaseHas('chat_moderation_logs', ['action' => 'timeout', 'target_user_id' => $this->user->id]);
    }

    public function test_moderator_cannot_moderate_an_admin(): void
    {
        $this->expectException(AuthorizationException::class);

        app(ChatModerationService::class)->timeout($this->moderator, $this->admin, 60);
    }

    public function test_regular_user_cannot_use_mod_endpoints(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('chat.moderation.timeout'), [
                'user_id' => $this->moderator->id,
                'seconds' => 60,
            ])
            ->assertForbidden();
    }

    public function test_only_ban_permission_holders_can_ban(): void
    {
        $this->actingAs($this->moderator)
            ->postJson(route('chat.moderation.ban'), ['user_id' => $this->user->id])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson(route('chat.moderation.ban'), ['user_id' => $this->user->id, 'reason' => 'raiding'])
            ->assertOk();

        $this->assertDatabaseHas('chat_bans', ['user_id' => $this->user->id, 'reason' => 'raiding']);
    }

    public function test_purge_removes_a_users_recent_messages(): void
    {
        Event::fake([ChatMessagesDeletedEvent::class]);

        foreach (range(1, 3) as $index) {
            Message::create([
                'message' => "spam {$index}",
                'user_id' => $this->user->id,
                'source_id' => $this->source->id,
                'type' => 'user',
            ]);
        }

        $count = app(ChatModerationService::class)->purgeUser($this->moderator, $this->user, $this->source->id, 600);

        $this->assertSame(3, $count);
        $this->assertSame(0, Message::where('user_id', $this->user->id)->count());
        Event::assertDispatched(ChatMessagesDeletedEvent::class);
    }

    public function test_settings_are_scoped_per_source(): void
    {
        $otherSource = Source::factory()->create();
        $settings = app(ChatSettingsService::class);

        $settings->update(['slow_mode_seconds' => 15], $this->source->id);

        $this->assertSame(15, $settings->slowModeSeconds($this->source->id));
        $this->assertSame(0, $settings->slowModeSeconds($otherSource->id));
    }

    public function test_legacy_stored_markup_is_normalised_for_the_client(): void
    {
        $message = Message::create([
            'message' => 'hey &amp; <emote data-name="wave" data-url="https://cdn/wave.png" data-size="normal"></emote>',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        $this->assertSame('hey & :wave:', $message->fresh()->body);
    }

    public function test_history_endpoint_returns_presented_messages(): void
    {
        Message::create([
            'message' => 'older',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        $this->actingAs($this->user)
            ->getJson(route('messages.older', ['source_id' => $this->source->id]))
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'older')
            ->assertJsonPath('hasMore', false);
    }

    public function test_empty_message_is_rejected(): void
    {
        $this->sendAs($this->user, '')->assertStatus(422)->assertJsonValidationErrors(['message']);
    }

    public function test_punishment_notices_stay_on_the_moderator_channel(): void
    {
        Message::create([
            'message' => 'something to clear',
            'user_id' => $this->user->id,
            'source_id' => $this->source->id,
            'type' => 'user',
        ]);

        Event::fake();

        $moderation = app(ChatModerationService::class);

        $moderation->timeout($this->moderator, $this->user, 600, null, $this->source->id);
        $moderation->removeTimeout($this->moderator, $this->user, $this->source->id);
        $moderation->clearChat($this->admin, $this->source->id);

        $notices = collect(Event::dispatched(ChatNoticeEvent::class))
            ->map(fn ($dispatched) => $dispatched[0]);

        foreach ($notices->where('modsOnly', true) as $notice) {
            $this->assertSame(
                'private-chat.source.'.$this->source->id.'.mods',
                $notice->broadcastOn()[0]->name,
            );
        }

        $this->assertTrue($notices->firstWhere('modsOnly', true) !== null);
        $this->assertStringContainsString('timed out for 10 minutes', $notices->first()->text);

        // Clearing the log is not a punishment: the room has to be told why it emptied.
        $public = $notices->firstWhere('modsOnly', false);
        $this->assertNotNull($public);
        $this->assertSame('chat.source.'.$this->source->id, $public->broadcastOn()[0]->name);
    }

    public function test_regular_users_cannot_join_the_moderator_channel(): void
    {
        // The null broadcaster authorises everything, so the channel callback only
        // gets a say once a real driver is in play. Channels are registered against
        // whichever driver was current at boot, so they have to be re-declared.
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');

        $channel = 'private-chat.source.'.$this->source->id.'.mods';

        $this->actingAs($this->user)
            ->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
            ->assertForbidden();

        $this->actingAs($this->moderator)
            ->postJson('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'])
            ->assertOk();
    }

    public function test_user_card_hides_moderation_data_from_regular_users(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('chat.users.show', $this->moderator->id))
            ->assertOk()
            ->assertJsonMissingPath('recent_messages')
            ->assertJsonPath('can_moderate', false);

        $this->actingAs($this->moderator)
            ->getJson(route('chat.users.show', [$this->user->id, 'source_id' => $this->source->id]))
            ->assertOk()
            ->assertJsonPath('can_moderate', true)
            ->assertJsonStructure(['recent_messages', 'message_count']);
    }
}
