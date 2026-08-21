<?php

namespace App\Services\Telegram;

use App\Models\TelegramChat;
use App\Support\Features;
use App\Support\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Bot API, as much of it as this installation uses.
 *
 * Everything goes through call(), which answers a plain array rather than throwing:
 * a chat the bot was kicked out of is a normal thing to discover, not an exception,
 * and the caller decides whether that means "disable this chat" or "give up on this
 * one message".
 *
 * With no token saved, or with the telegram feature switched off, every method is a
 * no-op. That is the state of a fresh install, and it is also what the switch is for.
 */
class TelegramClient
{
    /**
     * Errors that mean this chat is not coming back on its own: the bot was removed,
     * blocked, or the chat no longer exists. Matched on the description because
     * Telegram answers all of them with 400 or 403.
     */
    private const FATAL_DESCRIPTIONS = [
        'bot was kicked',
        'bot was blocked',
        'chat not found',
        'user is deactivated',
        'bot is not a member',
        'have no rights to send',
        'group chat was upgraded',
    ];

    public function enabled(): bool
    {
        return Features::telegram() && TelegramSettings::configured();
    }

    /**
     * Post into a chat. Answers the new message id, or null when nothing was sent.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     */
    public function send(TelegramChat $chat, string $text, ?array $keyboard = null): ?int
    {
        $response = $this->call('sendMessage', array_filter([
            'chat_id' => $chat->chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $keyboard ? json_encode(['inline_keyboard' => $keyboard]) : null,
        ], fn ($value) => $value !== null));

        if (! ($response['ok'] ?? false)) {
            $this->handleFailure($chat, $response);

            return null;
        }

        $chat->forceFill(['last_message_at' => now(), 'last_error' => null])->save();

        return $response['result']['message_id'] ?? null;
    }

    /**
     * Post into a chat that has no row here: the answer to a command from a group the
     * bot has never been linked to, which is most of what `/link` and `/chatid` are for.
     */
    public function reply(string $chatId, string $text, ?int $replyTo = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_to_message_id' => $replyTo,
        ], fn ($value) => $value !== null));
    }

    /**
     * Rewrite a message already posted. A message whose text and buttons are already
     * what we are about to write answers "message is not modified", which is a success
     * as far as this is concerned.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     */
    public function edit(TelegramChat $chat, int $messageId, string $text, ?array $keyboard = null): bool
    {
        $response = $this->call('editMessageText', [
            'chat_id' => $chat->chat_id,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard ?? []]),
        ]);

        if ($response['ok'] ?? false) {
            return true;
        }

        if (str_contains(mb_strtolower($response['description'] ?? ''), 'not modified')) {
            return true;
        }

        $this->handleFailure($chat, $response);

        return false;
    }

    /**
     * Every callback query has to be answered, or the button spins in the client for
     * a while before giving up. The text is the little toast Telegram shows.
     */
    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert,
        ], fn ($value) => $value !== null && $value !== false));
    }

    /**
     * Point Telegram at this installation, with the secret it will have to echo back.
     * Registering again rotates nothing by itself; the secret is reused so a rerun is
     * harmless.
     *
     * @return array<string, mixed>
     */
    public function registerWebhook(): array
    {
        return $this->setWebhook(TelegramSettings::webhookUrl(), TelegramSettings::webhookSecret());
    }

    /**
     * @return array<string, mixed>
     */
    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            // Nothing here reads edits, channel posts or polls, and an update we never
            // handle is bandwidth and log noise.
            'allowed_updates' => json_encode(['message', 'callback_query', 'my_chat_member']),
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    /**
     * Who the token belongs to. Used by the panel to show the bot's @name, and as the
     * check that a pasted token is real.
     *
     * @return array<string, mixed>|null
     */
    public function me(): ?array
    {
        $response = $this->call('getMe', []);

        return ($response['ok'] ?? false) ? ($response['result'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function call(string $method, array $payload): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'description' => 'Telegram is not configured.'];
        }

        $url = rtrim((string) config('telegram.api_url'), '/').'/bot'.TelegramSettings::token().'/'.$method;

        try {
            $response = Http::timeout(10)->asForm()->post($url, $payload);
        } catch (ConnectionException $e) {
            Log::warning('Telegram API unreachable', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'description' => 'Telegram is unreachable.'];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'description' => 'Telegram answered with something that is not JSON.'];
        }

        if (! ($body['ok'] ?? false)) {
            Log::warning('Telegram API refused a call', [
                'method' => $method,
                'error_code' => $body['error_code'] ?? null,
                'description' => $body['description'] ?? null,
            ]);
        }

        return $body;
    }

    /**
     * A send that failed for a reason the chat cannot recover from switches the chat
     * off with the reason on it, so the panel shows a dead row as dead instead of
     * retrying it every minute for the rest of the convention.
     *
     * @param  array<string, mixed>  $response
     */
    private function handleFailure(TelegramChat $chat, array $response): void
    {
        $description = (string) ($response['description'] ?? 'Unknown Telegram error');
        $lower = mb_strtolower($description);

        foreach (self::FATAL_DESCRIPTIONS as $needle) {
            if (str_contains($lower, $needle)) {
                $chat->disable($description);

                return;
            }
        }

        $chat->forceFill(['last_error' => mb_substr($description, 0, 250)])->save();
    }
}
