<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Models\Source;
use App\Models\TelegramChat;
use App\Models\TelegramLinkCode;
use App\Support\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]]),
        ]);
    }

    private function chat(array $attributes = []): TelegramChat
    {
        return TelegramChat::create(array_merge([
            'chat_id' => '-1001234567890',
            'title' => 'Control room',
            'type' => 'supergroup',
            'enabled' => true,
        ], $attributes));
    }

    public function test_the_list_shows_what_each_chat_is_set_to_receive(): void
    {
        $this->chat(['notify_shows' => true, 'notify_sources' => true, 'interactive' => true]);

        $this->actingAs($this->admin)
            ->get(route('manage.telegram.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Manage/Telegram/Index')
                ->where('table.rows.0.cells.receives', 'Shows, Sources')
                ->where('table.rows.0.cells.mode.label', 'Actions')
                ->where('bot.configured', false));
    }

    public function test_the_module_is_administrators_only(): void
    {
        $this->chat();

        $this->actingAs($this->moderator)
            ->get(route('manage.telegram.index'))
            ->assertForbidden();
    }

    public function test_the_module_is_gone_when_the_feature_is_off(): void
    {
        BrandingSetting::setValue('telegram', '0');

        $this->actingAs($this->admin)
            ->get(route('manage.telegram.index'))
            ->assertNotFound();
    }

    public function test_a_link_code_can_be_minted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.telegram.code'))
            ->assertRedirect();

        $code = TelegramLinkCode::sole();

        $this->assertTrue($code->usable());
        $this->assertSame($this->admin->id, $code->created_by);
    }

    public function test_a_chat_can_be_added_by_id(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.telegram.store'), ['chat_id' => '12345', 'title' => 'Maintainer'])
            ->assertRedirect();

        $chat = TelegramChat::sole();

        $this->assertSame('12345', $chat->chat_id);
        // Added, and told nothing until somebody decides what it should hear.
        $this->assertFalse($chat->notify_shows);
        $this->assertFalse($chat->interactive);
    }

    public function test_a_chat_id_has_to_look_like_one(): void
    {
        $this->actingAs($this->admin)
            ->post(route('manage.telegram.store'), ['chat_id' => '@somegroup'])
            ->assertSessionHasErrors('chat_id');
    }

    public function test_flags_and_sources_are_saved(): void
    {
        $chat = $this->chat();
        $source = Source::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('manage.telegram.update', $chat), [
                'title' => 'Hall 2',
                'enabled' => true,
                'interactive' => true,
                'notify_shows' => true,
                'notify_recordings' => true,
                'notify_sources' => true,
                'notify_feedback' => false,
                'source_ids' => [$source->id],
            ])
            ->assertRedirect(route('manage.telegram.index'));

        $chat->refresh();

        $this->assertTrue($chat->interactive);
        $this->assertTrue($chat->notify_recordings);
        $this->assertTrue($chat->notify_sources);
        $this->assertFalse($chat->notify_feedback);
        $this->assertSame([$source->id], $chat->source_ids);
        $this->assertTrue($chat->coversSource($source->id));
        $this->assertFalse($chat->coversSource($source->id + 1));
    }

    public function test_a_test_post_reports_what_telegram_said(): void
    {
        BrandingSetting::setValue(TelegramSettings::TOKEN_KEY, '123:abc');
        $chat = $this->chat();

        $this->actingAs($this->admin)
            ->post(route('manage.telegram.test', $chat))
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
        $this->assertNotNull($chat->refresh()->last_message_at);
    }

    /**
     * Saving a token is the whole setup step: an operator should never have to know
     * that setWebhook exists, and a token with no webhook behind it is a bot whose
     * buttons quietly do nothing.
     */
    public function test_saving_a_bot_token_registers_the_webhook(): void
    {
        $this->actingAs($this->admin)
            ->put(route('manage.settings.update', 'notifications'), [
                'values' => [
                    'telegram_bot_token' => '654321:fresh-token',
                    'telegram_show_lead_minutes' => '7',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('654321:fresh-token', TelegramSettings::token());
        $this->assertSame(7, TelegramSettings::leadMinutes());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/setWebhook')) {
                return false;
            }

            $this->assertStringContainsString('654321:fresh-token', $request->url());
            $this->assertSame(route('api.telegram.webhook'), $request['url']);
            $this->assertSame(TelegramSettings::webhookSecret(), $request['secret_token']);

            return true;
        });
    }

    public function test_a_webhook_telegram_refuses_is_reported_rather_than_swallowed(): void
    {
        Http::fake([
            'api.telegram.org/*setWebhook*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: bad webhook: HTTPS url must be provided',
            ], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update', 'notifications'), [
                'values' => ['telegram_bot_token' => '654321:fresh-token'],
            ])
            ->assertRedirect();

        // Saved either way: the token is not the thing that failed.
        $this->assertSame('654321:fresh-token', TelegramSettings::token());
    }

    public function test_clearing_the_token_takes_the_bot_off_the_air(): void
    {
        BrandingSetting::setValue(TelegramSettings::TOKEN_KEY, '123:abc');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update', 'notifications'), [
                'values' => ['telegram_bot_token' => '__clear__'],
            ])
            ->assertRedirect();

        $this->assertFalse(TelegramSettings::configured());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
    }

    public function test_an_unrelated_settings_pane_leaves_the_token_alone(): void
    {
        BrandingSetting::setValue(TelegramSettings::TOKEN_KEY, '123:abc');

        $this->actingAs($this->admin)
            ->put(route('manage.settings.update', 'identity'), [
                'values' => [
                    'convention_name' => 'Test Con',
                    'site_name' => 'Test Stream',
                    'identity_name' => 'Test ID',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('123:abc', TelegramSettings::token());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/setWebhook'));
    }

    public function test_removing_a_chat_stops_it(): void
    {
        $chat = $this->chat();

        $this->actingAs($this->admin)
            ->delete(route('manage.telegram.destroy', $chat))
            ->assertRedirect(route('manage.telegram.index'));

        $this->assertDatabaseCount('telegram_chats', 0);
    }
}
