<?php

namespace Tests\Feature;

use App\Jobs\Notifications\DispatchRecordingNotificationsJob;
use App\Jobs\Notifications\NotifyRecordingSubscribersJob;
use App\Jobs\Notifications\NotifyShowSubscribersJob;
use App\Models\BrandingSetting;
use App\Models\NotificationDelivery;
use App\Models\Recording;
use App\Models\Show;
use App\Models\Source;
use App\Models\TelegramChat;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Notifications\RecordingPublished;
use App\Notifications\ShowStarted;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Services\ViewerNotifier;
use App\Support\Features;
use App\Support\NotificationScope;
use App\Support\TelegramSettings;
use App\Support\UnsubscribeLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Viewer notifications: the delay, the once-only guarantee, and the two ways somebody
 * ends up subscribed.
 */
class ViewerNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

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
    }

    private function subscriber(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'viewer@example.org',
            'notify_recordings' => NotificationScope::ANY,
            'notify_shows_live' => NotificationScope::ANY,
        ], $attributes));
    }

    private function recording(array $attributes = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'Closing Ceremony',
            'source_id' => $this->source->id,
            'date' => now(),
            'is_published' => true,
        ], $attributes));
    }

    public function test_a_recording_is_held_back_for_the_delay_and_then_sent(): void
    {
        $this->subscriber();
        $recording = $this->recording();

        Bus::fake();

        (new DispatchRecordingNotificationsJob)->handle();
        Bus::assertNotDispatched(NotifyRecordingSubscribersJob::class);

        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        (new DispatchRecordingNotificationsJob)->handle();
        Bus::assertDispatched(NotifyRecordingSubscribersJob::class);
    }

    public function test_unpublishing_inside_the_window_cancels_the_send(): void
    {
        $this->subscriber();
        $recording = $this->recording();

        // Taken down again, then put back up: the clock starts over rather than the
        // original publish still counting down behind the operator's back.
        $recording->update(['is_published' => false]);
        $this->assertNull($recording->fresh()->published_at);

        $recording->update(['is_published' => true]);
        $this->assertTrue($recording->fresh()->published_at->isAfter(now()->subMinute()));

        Bus::fake();
        (new DispatchRecordingNotificationsJob)->handle();
        Bus::assertNotDispatched(NotifyRecordingSubscribersJob::class);
    }

    public function test_a_viewer_is_written_to_once_per_recording_however_often_it_runs(): void
    {
        $user = $this->subscriber();
        $recording = $this->recording();
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();

        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        // The stamp is cleared, which is the worst case: the scan finds it again.
        $recording->forceFill(['notified_at' => null])->saveQuietly();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        Notification::assertSentToTimes($user, RecordingPublished::class, 1);

        $this->assertSame(1, NotificationDelivery::where('user_id', $user->id)
            ->where('type', NotificationDelivery::TYPE_RECORDING_PUBLISHED)
            ->where('subject_id', $recording->id)
            ->count());
    }

    public function test_only_transports_the_account_has_are_used(): void
    {
        $user = $this->subscriber(['email' => null, 'telegram_chat_id' => '4242']);
        $recording = $this->recording();
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        $this->assertSame(
            ['telegram'],
            NotificationDelivery::where('user_id', $user->id)->pluck('channel')->all(),
        );
    }

    public function test_somebody_who_follows_a_show_is_told_when_it_starts(): void
    {
        // The shipped default: told about the shows they follow and nothing else.
        $user = $this->subscriber([
            'notify_shows_live' => NotificationScope::SUBSCRIBED,
            'notify_recordings' => NotificationScope::SUBSCRIBED,
        ]);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $user->showSubscriptions()->create(['show_id' => $show->id]);

        Notification::fake();

        $show->goLive();
        (new NotifyShowSubscribersJob($show->id))->handle(app(ViewerNotifier::class));

        Notification::assertSentTo($user, ShowStarted::class);
    }

    public function test_nobody_who_did_not_follow_the_show_is_told_it_started(): void
    {
        $bystander = $this->subscriber([
            'notify_shows_live' => NotificationScope::SUBSCRIBED,
            'notify_recordings' => NotificationScope::SUBSCRIBED,
        ]);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'live',
            'scheduled_start' => now(),
            'scheduled_end' => now()->addHour(),
        ]);

        Notification::fake();
        (new NotifyShowSubscribersJob($show->id))->handle(app(ViewerNotifier::class));

        Notification::assertNothingSentTo($bystander);
    }

    public function test_a_viewer_left_on_the_default_hears_only_about_shows_they_follow(): void
    {
        $stranger = User::factory()->create(['email' => 'stranger@example.org']);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $recording = $this->recording(['show_id' => $show->id]);
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        Notification::assertNothingSentTo($stranger);

        // Following it is the act that opts them in, with nothing else to configure.
        $stranger->showSubscriptions()->create(['show_id' => $show->id]);
        $recording->forceFill(['notified_at' => null])->saveQuietly();

        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        Notification::assertSentTo($stranger, RecordingPublished::class);
    }

    public function test_following_a_show_confirms_with_a_toast(): void
    {
        $user = User::factory()->create(['email' => 'viewer@example.org']);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($user)
            ->post(route('notifications.shows.follow', $show))
            ->assertSessionHas('toast', 'Following Dance Comp.');
    }

    public function test_a_viewer_with_nowhere_to_send_to_is_told_so_when_they_subscribe(): void
    {
        $user = User::factory()->create(['email' => null]);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($user)
            ->post(route('notifications.shows.follow', $show))
            ->assertSessionHas('toast', fn (string $toast) => str_contains($toast, 'Settings'));
    }

    public function test_the_endpoints_are_gone_when_the_installation_switches_notifications_off(): void
    {
        $user = User::factory()->create();

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        BrandingSetting::setValue('notifications', '0');
        Features::flush();

        $this->actingAs($user)
            ->post(route('notifications.shows.follow', $show))
            ->assertNotFound();
    }

    public function test_a_viewer_code_links_a_private_chat_and_never_a_group(): void
    {
        $user = User::factory()->create();
        $code = TelegramLinkCode::mintForViewer($user);

        $handler = app(TelegramUpdateHandler::class);

        // A group is refused outright: one person's notifications must not land in a room.
        $handler->handle(['message' => [
            'chat' => ['id' => '-100200', 'type' => 'supergroup', 'title' => 'Control'],
            'from' => ['username' => 'someone'],
            'text' => '/link '.$code->code,
        ]]);

        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertSame(0, TelegramChat::count());

        $handler->handle(['message' => [
            'chat' => ['id' => '9911', 'type' => 'private'],
            'from' => ['username' => 'viewer'],
            'text' => '/link '.$code->code,
        ]]);

        $this->assertSame('9911', $user->fresh()->telegram_chat_id);
        $this->assertSame('viewer', $user->fresh()->telegram_username);
    }

    public function test_the_settings_page_offers_a_one_tap_connect_link_once_the_bot_is_known(): void
    {
        BrandingSetting::setValue(TelegramSettings::TOKEN_KEY, '123:abc');
        BrandingSetting::setValue(TelegramSettings::USERNAME_KEY, 'example_stream_bot');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertInertia(function (AssertableInertia $page) {
                $code = $page->toArray()['props']['notifications']['telegram']['code'];

                $page->where(
                    'notifications.telegram.connect_url',
                    'https://t.me/example_stream_bot?start='.rawurlencode($code),
                );
            });

        // Opening the page again reuses the code rather than leaving a trail of them.
        $this->actingAs($user)->get(route('settings.edit'));

        $this->assertSame(1, TelegramLinkCode::where('created_by', $user->id)->count());
    }

    public function test_the_deep_links_start_payload_links_the_account(): void
    {
        $user = User::factory()->create();
        $code = TelegramLinkCode::mintForViewer($user);

        app(TelegramUpdateHandler::class)->handle(['message' => [
            'chat' => ['id' => '5150', 'type' => 'private'],
            'from' => ['username' => 'viewer'],
            'text' => '/start '.$code->code,
        ]]);

        $this->assertSame('5150', $user->fresh()->telegram_chat_id);
    }

    public function test_saving_a_token_records_the_bots_handle_and_clearing_it_forgets_it(): void
    {
        TelegramSettings::rememberUsername('@example_stream_bot');
        $this->assertSame('example_stream_bot', TelegramSettings::username());

        // An operator's own answer is not overwritten by what getMe says.
        TelegramSettings::rememberUsername('something_else');
        $this->assertSame('example_stream_bot', TelegramSettings::username());

        TelegramSettings::forgetUsername();
        $this->assertNull(TelegramSettings::username());
    }

    public function test_the_unsubscribe_link_changes_nothing_until_it_is_confirmed(): void
    {
        $user = $this->subscriber();

        $url = UnsubscribeLinks::everything($user);

        // A mail scanner fetching the link must not unsubscribe anybody.
        $this->get($url)->assertOk();
        $this->assertSame(NotificationScope::ANY, $user->fresh()->notify_recordings);

        $this->post($url)->assertOk();
        $this->assertSame(NotificationScope::OFF, $user->fresh()->notify_recordings);
        $this->assertSame(NotificationScope::OFF, $user->fresh()->notify_shows_live);
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        $user = $this->subscriber();

        $this->get(route('notifications.unsubscribe', ['user' => $user->id, 'category' => 'all']))
            ->assertForbidden();
    }

    public function test_a_follow_is_cleared_once_its_recording_notification_has_gone_out(): void
    {
        $user = $this->subscriber([
            'notify_shows_live' => NotificationScope::SUBSCRIBED,
            'notify_recordings' => NotificationScope::SUBSCRIBED,
        ]);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'ended',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHours(2),
        ]);

        $user->showSubscriptions()->create(['show_id' => $show->id]);

        $recording = $this->recording(['show_id' => $show->id]);
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        Notification::assertSentTo($user, RecordingPublished::class);
        $this->assertSame(0, $user->showSubscriptions()->count());
    }

    public function test_a_follow_survives_when_there_was_nowhere_to_send_it(): void
    {
        // No address, no linked chat: nothing went out, so the follow still has a job.
        $user = User::factory()->create(['email' => null]);

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'ended',
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHours(2),
        ]);

        $user->showSubscriptions()->create(['show_id' => $show->id]);

        $recording = $this->recording(['show_id' => $show->id]);
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        $this->assertSame(1, $user->showSubscriptions()->count());
    }

    public function test_the_panel_saves_both_scopes_and_the_channels(): void
    {
        $user = User::factory()->create(['email' => 'viewer@example.org']);

        $this->actingAs($user)->patch(route('notifications.update'), [
            'scopes' => ['shows_live' => 'off', 'recordings' => 'any'],
            'channels' => ['mail', 'telegram'],
        ])->assertRedirect();

        $user->refresh();

        $this->assertSame(NotificationScope::OFF, $user->notify_shows_live);
        $this->assertSame(NotificationScope::ANY, $user->notify_recordings);

        // Telegram is dropped: this account has no linked chat, so saving it would
        // read back as a transport that silently never delivers.
        $this->assertSame(['mail'], $user->notification_channels);
    }

    public function test_the_settings_page_is_one_page_per_section(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('section', 'notifications')
                // Notifications, Features, Account.
                ->has('navigation', 3));

        $this->actingAs($user)
            ->get(route('settings.edit', 'features'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('section', 'features'));

        // A shareable URL that names nothing is a 404, not a quiet fallback to
        // whatever happens to be first.
        $this->actingAs($user)->get(route('settings.edit', 'nonsense'))->assertNotFound();
    }

    public function test_a_card_saves_only_what_it_owns(): void
    {
        $user = User::factory()->create(['email' => 'viewer@example.org']);

        $this->actingAs($user)->patch(route('notifications.update'), [
            'scopes' => ['recordings' => 'any'],
        ])->assertRedirect();

        $user->refresh();

        $this->assertSame(NotificationScope::ANY, $user->notify_recordings);
        // Untouched, not emptied: the channels card did not send anything.
        $this->assertNull($user->notification_channels);
    }

    public function test_the_settings_page_offers_the_transports_the_account_has(): void
    {
        $user = User::factory()->create(['email' => 'viewer@example.org']);

        $this->actingAs($user)
            ->get(route('settings.edit'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings')
                ->where('notifications.email', 'viewer@example.org')
                ->where('notifications.channels.available', ['mail'])
                ->where('notifications.telegram.linked', false));
    }

    public function test_the_archive_carries_the_settings_panel_for_a_signed_in_viewer(): void
    {
        $user = $this->subscriber();

        // The bell opens the settings panel, so the archive carries the whole payload
        // rather than one flag.
        $this->actingAs($user)
            ->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications.email', 'viewer@example.org')
                ->has('notifications.categories', 2)
                ->has('notifications.scopeOptions'));
    }

    public function test_a_guest_is_offered_no_bell_at_all(): void
    {
        config(['auth.required' => false]);

        $this->get(route('recordings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('notifications', null));
    }

    public function test_following_a_show_from_the_guide_shows_up_on_the_guide(): void
    {
        $user = User::factory()->create();

        $show = Show::create([
            'title' => 'Dance Comp',
            'slug' => 'dance-comp',
            'source_id' => $this->source->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addHour(),
            'scheduled_end' => now()->addHours(2),
        ]);

        $this->actingAs($user)->post(route('notifications.shows.follow', $show))->assertRedirect();

        $this->actingAs($user)
            ->get(route('schedule.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canFollow', true)
                ->where('days.0.channels.0.shows.0.followed', true));

        $this->actingAs($user)->delete(route('notifications.shows.unfollow', $show));

        $this->assertSame(0, $user->showSubscriptions()->count());
    }

    public function test_a_restricted_recording_is_not_announced_to_people_who_cannot_watch_it(): void
    {
        $user = $this->subscriber();
        $recording = $this->recording(['required_roles' => ['admin']]);
        $recording->forceFill(['published_at' => now()->subHours(5)])->saveQuietly();

        Notification::fake();
        (new NotifyRecordingSubscribersJob($recording->id))->handle(app(ViewerNotifier::class));

        Notification::assertNothingSentTo($user);
    }
}
