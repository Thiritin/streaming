<?php

namespace Tests\Feature;

use App\Enum\SourceStatusEnum;
use App\Jobs\Telegram\NotifyUpcomingShowsJob;
use App\Models\BrandingSetting;
use App\Models\FeedbackReport;
use App\Models\Recording;
use App\Models\Role;
use App\Models\Show;
use App\Models\Source;
use App\Models\TelegramChat;
use App\Models\TelegramLinkCode;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramNotifier;
use App\Support\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot's own half: what arrives on the webhook, and what the notifier posts.
 */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private int $nextMessageId = 100;

    protected function setUp(): void
    {
        parent::setUp();

        BrandingSetting::setValue(TelegramSettings::TOKEN_KEY, '123456:test-token');

        // The feedback endpoint is open to guests; the mandatory-login middleware would
        // otherwise answer first.
        config(['auth.required' => false]);

        // A new message id per send, so a test can tell two posted messages apart.
        Http::fake([
            'api.telegram.org/*' => fn () => Http::response([
                'ok' => true,
                'result' => ['message_id' => $this->nextMessageId++],
            ]),
        ]);
    }

    private function deliver(array $update)
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => TelegramSettings::webhookSecret()])
            ->postJson(route('api.telegram.webhook'), $update);
    }

    private function chat(array $attributes = []): TelegramChat
    {
        return TelegramChat::create(array_merge([
            'chat_id' => '-100999',
            'title' => 'Control room',
            'enabled' => true,
            'interactive' => true,
            'notify_shows' => true,
            'notify_feedback' => true,
        ], $attributes));
    }

    private function press(string $data, int $messageId, string $chatId = '-100999'): array
    {
        return ['callback_query' => [
            'id' => 'cb-1',
            'from' => ['id' => 5, 'username' => 'operator'],
            'data' => $data,
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId]],
        ]];
    }

    public function test_the_webhook_needs_the_secret_telegram_was_given(): void
    {
        $this->postJson(route('api.telegram.webhook'), ['update_id' => 1])->assertNotFound();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
            ->postJson(route('api.telegram.webhook'), ['update_id' => 1])
            ->assertNotFound();
    }

    public function test_the_webhook_is_closed_without_a_bot(): void
    {
        $secret = TelegramSettings::webhookSecret();
        BrandingSetting::where('key', TelegramSettings::TOKEN_KEY)->delete();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret])
            ->postJson(route('api.telegram.webhook'), ['update_id' => 1])
            ->assertNotFound();
    }

    public function test_link_turns_a_code_into_a_chat_with_nothing_switched_on(): void
    {
        $code = TelegramLinkCode::mint();

        $this->deliver(['message' => [
            'text' => '/link@stream_bot '.$code->code,
            'chat' => ['id' => '-100999', 'type' => 'supergroup', 'title' => 'Hall 2 crew'],
        ]])->assertOk();

        $chat = TelegramChat::sole();

        $this->assertSame('Hall 2 crew', $chat->title);
        $this->assertTrue($chat->enabled);
        $this->assertFalse($chat->interactive);
        $this->assertFalse($chat->notify_shows);
        $this->assertNotNull($code->refresh()->used_at);
    }

    /**
     * A forum group is one chat id and many topics, and the point of using topics is that
     * they are configured apart: shows in one, reports in another.
     */
    public function test_each_topic_links_as_its_own_configuration(): void
    {
        $stage = TelegramLinkCode::mint();
        $support = TelegramLinkCode::mint();

        $this->deliver(['message' => [
            'text' => '/link '.$stage->code,
            'is_topic_message' => true,
            'message_thread_id' => 12,
            'chat' => ['id' => '-100999', 'type' => 'supergroup', 'title' => 'Tech crew'],
            'reply_to_message' => ['forum_topic_created' => ['name' => 'Main stage']],
        ]])->assertOk();

        $this->deliver(['message' => [
            'text' => '/link '.$support->code,
            'is_topic_message' => true,
            'message_thread_id' => 34,
            'chat' => ['id' => '-100999', 'type' => 'supergroup', 'title' => 'Tech crew'],
            'reply_to_message' => ['forum_topic_created' => ['name' => 'Support']],
        ]])->assertOk();

        $this->assertDatabaseCount('telegram_chats', 2);

        $stageChat = TelegramChat::where('thread_id', 12)->sole();

        $this->assertSame('Main stage', $stageChat->topic_title);
        $this->assertSame('Tech crew · Main stage', $stageChat->label());

        // The answer went back into the topic it was asked from, not into General.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (int) $request['message_thread_id'] === 12);
    }

    public function test_a_topic_only_gets_what_that_topic_asked_for(): void
    {
        $shows = TelegramChat::create([
            'chat_id' => '-100999',
            'thread_id' => 12,
            'title' => 'Tech crew',
            'topic_title' => 'Main stage',
            'enabled' => true,
            'notify_shows' => true,
            'notify_feedback' => false,
        ]);

        TelegramChat::create([
            'chat_id' => '-100999',
            'thread_id' => 34,
            'title' => 'Tech crew',
            'topic_title' => 'Support',
            'enabled' => true,
            'notify_shows' => false,
            'notify_feedback' => true,
        ]);

        Show::factory()->create([
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(2),
        ]);

        (new NotifyUpcomingShowsJob)->handle(
            app(TelegramClient::class),
            app(TelegramNotifier::class),
        );

        $message = TelegramMessage::sole();

        $this->assertSame($shows->id, $message->telegram_chat_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (int) $request['message_thread_id'] === 12);
    }

    public function test_a_button_pressed_in_a_topic_acts_on_that_topics_row(): void
    {
        $chat = TelegramChat::create([
            'chat_id' => '-100999',
            'thread_id' => 12,
            'title' => 'Tech crew',
            'enabled' => true,
            'interactive' => true,
            'notify_shows' => true,
        ]);

        $show = Show::factory()->create(['status' => 'scheduled']);
        TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 700,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_UPCOMING,
        ]);

        $press = $this->press("s:start:{$show->id}", 700);
        $press['callback_query']['message']['is_topic_message'] = true;
        $press['callback_query']['message']['message_thread_id'] = 12;

        $this->deliver($press)->assertOk();

        $this->assertSame('live', $show->refresh()->status);
    }

    public function test_a_used_code_does_not_work_twice(): void
    {
        $code = TelegramLinkCode::mint();
        $code->forceFill(['used_at' => now()])->save();

        $this->deliver(['message' => [
            'text' => '/link '.$code->code,
            'chat' => ['id' => '-100777', 'type' => 'group', 'title' => 'Somewhere else'],
        ]])->assertOk();

        $this->assertDatabaseCount('telegram_chats', 0);
    }

    public function test_an_upcoming_show_is_announced_once_per_chat(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->create([
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(3),
            'scheduled_end' => now()->addHour(),
        ]);

        (new NotifyUpcomingShowsJob)->handle(app(TelegramClient::class), app(TelegramNotifier::class));
        (new NotifyUpcomingShowsJob)->handle(app(TelegramClient::class), app(TelegramNotifier::class));

        $this->assertDatabaseCount('telegram_messages', 1);

        $message = TelegramMessage::sole();
        $this->assertSame(TelegramMessage::KIND_SHOW, $message->kind);
        $this->assertSame($show->id, $message->subject_id);
        $this->assertSame($chat->id, $message->telegram_chat_id);
    }

    public function test_a_chat_only_hears_about_its_own_sources(): void
    {
        $mine = Source::factory()->create();
        $theirs = Source::factory()->create();
        $this->chat(['source_ids' => [$mine->id]]);

        Show::factory()->create([
            'source_id' => $theirs->id,
            'status' => 'scheduled',
            'scheduled_start' => now()->addMinutes(2),
        ]);

        (new NotifyUpcomingShowsJob)->handle(app(TelegramClient::class), app(TelegramNotifier::class));

        $this->assertDatabaseCount('telegram_messages', 0);
    }

    public function test_the_start_button_takes_a_show_live(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->create(['status' => 'scheduled', 'scheduled_start' => now()->addMinutes(2)]);
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 500,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_UPCOMING,
        ]);

        $this->deliver($this->press("s:start:{$show->id}", 500))->assertOk();

        $this->assertSame('live', $show->refresh()->status);
        $this->assertSame(TelegramMessage::STATE_LIVE, $message->refresh()->state);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/editMessageText'));
    }

    public function test_ending_a_show_takes_two_presses(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->live()->create();
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 501,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_LIVE,
        ]);

        $this->deliver($this->press("s:end:{$show->id}", 501))->assertOk();

        $this->assertSame('live', $show->refresh()->status);
        $this->assertSame(TelegramMessage::STATE_CONFIRM_END, $message->refresh()->state);

        $this->deliver($this->press("s:endnow:{$show->id}", 501))->assertOk();

        $this->assertSame('ended', $show->refresh()->status);
        $this->assertSame(TelegramMessage::STATE_CLOSED, $message->refresh()->state);
    }

    public function test_cancelling_the_confirmation_leaves_the_show_running(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->live()->create();
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 502,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_CONFIRM_END,
        ]);

        $this->deliver($this->press("s:keep:{$show->id}", 502))->assertOk();

        $this->assertSame('live', $show->refresh()->status);
        $this->assertSame(TelegramMessage::STATE_LIVE, $message->refresh()->state);
    }

    public function test_an_info_only_chat_cannot_press_anything(): void
    {
        $chat = $this->chat(['interactive' => false]);
        $show = Show::factory()->create(['status' => 'scheduled']);
        TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 503,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_UPCOMING,
        ]);

        $this->deliver($this->press("s:start:{$show->id}", 503))->assertOk();

        $this->assertSame('scheduled', $show->refresh()->status);
    }

    public function test_a_report_is_posted_and_can_be_resolved_from_the_chat(): void
    {
        $chat = $this->chat();

        $this->post(route('feedback.store'), [
            'type' => FeedbackReport::TYPE_ISSUE,
            'message' => 'Audio is out of sync.',
        ])->assertSessionHasNoErrors();

        $report = FeedbackReport::sole();
        $message = TelegramMessage::where('kind', TelegramMessage::KIND_FEEDBACK)->sole();

        $this->assertSame($report->id, $message->subject_id);

        $this->deliver($this->press("f:resolve:{$report->id}", $message->message_id))->assertOk();

        $report->refresh();

        $this->assertSame(FeedbackReport::STATUS_RESOLVED, $report->status);
        $this->assertSame('@operator (Telegram)', $report->handled_note);
    }

    public function test_a_show_started_elsewhere_updates_the_message(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->create(['status' => 'scheduled']);
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 504,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_UPCOMING,
        ]);

        $show->goLive();

        $this->assertSame(TelegramMessage::STATE_LIVE, $message->refresh()->state);
    }

    /**
     * The whole point of holding the message ids: a show taken live from the panel or a
     * control surface has to rewrite the chat, or the group is left looking at a Start
     * button for something that is already on air.
     */
    public function test_starting_a_show_from_the_panel_rewrites_the_chat(): void
    {
        $role = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'permissions' => ['admin.access', 'stream.manage'],
            'priority' => 100,
        ]);
        $admin = User::factory()->create();
        $admin->roles()->attach($role);

        $chat = $this->chat();
        $show = Show::factory()->create(['status' => 'scheduled']);
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 505,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_UPCOMING,
        ]);

        $this->actingAs($admin)
            ->post(route('manage.shows.go-live', $show))
            ->assertRedirect();

        $this->assertSame('live', $show->refresh()->status);
        $this->assertSame(TelegramMessage::STATE_LIVE, $message->refresh()->state);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/editMessageText')) {
                return false;
            }

            // The message now reads as live, and offers ending rather than starting.
            $this->assertSame(505, (int) $request['message_id']);
            $this->assertStringContainsString('🔴', $request['text']);
            $this->assertStringContainsString('Live since', $request['text']);
            $this->assertStringContainsString('End show', $request['reply_markup']);
            $this->assertStringNotContainsString('Start show', $request['reply_markup']);

            return true;
        });
    }

    /**
     * Started before anyone announced it - auto mode, a very early start, or a show
     * created live. There is no message to rewrite, so the chat gets one that already
     * reads as live.
     */
    public function test_a_show_that_goes_live_unannounced_still_reaches_the_chat(): void
    {
        $this->chat();
        $show = Show::factory()->create([
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(6),
        ]);

        $show->goLive();

        $message = TelegramMessage::where('kind', TelegramMessage::KIND_SHOW)->sole();

        $this->assertSame(TelegramMessage::STATE_LIVE, $message->state);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $this->assertStringContainsString('Live since', $request['text']);
            $this->assertStringContainsString('End show', $request['reply_markup']);

            return true;
        });
    }

    public function test_a_recording_is_announced_and_rewritten_when_published(): void
    {
        $chat = $this->chat(['notify_recordings' => true]);
        $source = Source::factory()->create();

        $recording = Recording::create([
            'source_id' => $source->id,
            'title' => 'Opening Ceremony',
            'date' => now(),
            'status' => 'draft',
            'is_published' => false,
        ]);

        $message = TelegramMessage::where('kind', TelegramMessage::KIND_RECORDING)->sole();

        $this->assertSame($recording->id, $message->subject_id);
        $this->assertSame('draft', $message->state);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $this->assertStringContainsString('Opening Ceremony', $request['text']);
            $this->assertStringContainsString('Draft', $request['text']);
            $this->assertStringContainsString('Publish', $request['reply_markup']);

            return true;
        });

        $recording->forceFill(['is_published' => true])->save();

        $this->assertSame('published', $message->refresh()->state);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/editMessageText')) {
                return false;
            }

            $this->assertStringContainsString('Published.', $request['text']);
            $this->assertStringContainsString('Watch', $request['reply_markup']);
            $this->assertStringNotContainsString('📢 Publish', $request['reply_markup']);

            return true;
        });
    }

    public function test_the_publish_button_publishes_the_recording(): void
    {
        $chat = $this->chat(['notify_recordings' => true]);
        $recording = Recording::create([
            'source_id' => Source::factory()->create()->id,
            'title' => 'Closing Ceremony',
            'date' => now(),
            'status' => 'draft',
            'is_published' => false,
        ]);

        $message = TelegramMessage::where('kind', TelegramMessage::KIND_RECORDING)->sole();

        $this->deliver($this->press("r:publish:{$recording->id}", $message->message_id))->assertOk();

        $this->assertTrue($recording->refresh()->is_published);
        $this->assertSame('published', $message->refresh()->state);
    }

    public function test_processing_a_recording_does_not_touch_the_chat(): void
    {
        $this->chat(['notify_recordings' => true]);
        $recording = Recording::create([
            'source_id' => Source::factory()->create()->id,
            'title' => 'Panel',
            'date' => now(),
            'status' => 'draft',
            'is_published' => false,
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);

        // What ProcessRecordingJob writes back. None of it is worth an edit.
        $recording->forceFill(['duration' => 3600, 'thumbnail_path' => 'a/b.jpg', 'views' => 5])->save();

        Http::assertNothingSent();
    }

    public function test_a_source_going_online_and_offline_is_logged(): void
    {
        $chat = $this->chat(['notify_sources' => true]);
        $source = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);

        $source->update(['status' => SourceStatusEnum::ONLINE]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $this->assertStringContainsString('is online', $request['text']);

            return true;
        });

        // A log, not a conversation: nothing is kept to edit later.
        $this->assertDatabaseCount('telegram_messages', 0);

        $source->update(['status' => SourceStatusEnum::ERROR]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && str_contains($request['text'], 'is in error'));
    }

    public function test_a_status_the_chat_already_holds_is_not_posted_twice(): void
    {
        $this->chat(['notify_sources' => true]);
        $source = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);

        $source->update(['status' => SourceStatusEnum::ONLINE]);
        $sent = count(Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage')));

        // Written again by a callback that changed nothing the chat has not been told.
        $source->update(['status' => SourceStatusEnum::OFFLINE]);
        $source->update(['status' => SourceStatusEnum::ONLINE]);
        $source->forceFill(['status' => SourceStatusEnum::ONLINE])->save();

        $after = count(Http::recorded(fn ($request) => str_contains($request->url(), '/sendMessage')));

        $this->assertSame($sent + 2, $after);
    }

    public function test_a_chat_only_hears_about_its_own_sources_for_alerts(): void
    {
        $mine = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);
        $theirs = Source::factory()->create(['status' => SourceStatusEnum::OFFLINE]);
        $this->chat(['notify_sources' => true, 'source_ids' => [$mine->id]]);

        $theirs->update(['status' => SourceStatusEnum::ONLINE]);

        Http::assertNothingSent();
    }

    public function test_a_show_that_ends_unannounced_stays_quiet(): void
    {
        $this->chat();
        $show = Show::factory()->live()->create();

        $show->endLivestream();

        $this->assertDatabaseCount('telegram_messages', 0);
    }

    public function test_ending_a_show_from_the_panel_takes_the_buttons_away(): void
    {
        $chat = $this->chat();
        $show = Show::factory()->live()->create();
        $message = TelegramMessage::create([
            'telegram_chat_id' => $chat->id,
            'message_id' => 506,
            'kind' => TelegramMessage::KIND_SHOW,
            'subject_id' => $show->id,
            'state' => TelegramMessage::STATE_LIVE,
        ]);

        $show->endLivestream();

        $this->assertSame(TelegramMessage::STATE_CLOSED, $message->refresh()->state);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/editMessageText')) {
                return false;
            }

            $this->assertStringContainsString('Ended at', $request['text']);
            $this->assertStringNotContainsString('End show', $request['reply_markup']);

            return true;
        });
    }
}
