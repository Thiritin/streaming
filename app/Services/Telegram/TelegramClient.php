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
            // A forum supergroup needs the thread, or the post lands in General however
            // carefully somebody linked the topic they meant.
            'message_thread_id' => $chat->isTopic() ? $chat->thread_id : null,
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
    public function reply(string $chatId, string $text, ?int $threadId = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            // Answer in the topic the command was sent from, not in General.
            'message_thread_id' => $threadId ?: null,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
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
     * Post to a chat that is somebody's private conversation with the bot rather than a
     * row in `telegram_chats`: a viewer's own notifications.
     *
     * Answers null when Telegram took it, and the refusal otherwise. There is no chat
     * row to disable on failure, so a viewer who blocked the bot is reported back to
     * the caller and unlinked there instead - a dead chat id left on an account would
     * otherwise be retried for every recording published for the rest of the run.
     *
     * A photo turns the message into a card: Telegram captions are limited to 1024
     * characters against 4096 for text, so anything longer is sent as text with the
     * image dropped rather than truncated.
     *
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     */
    public function sendToChat(
        string $chatId,
        string $text,
        ?string $photoUrl = null,
        ?array $keyboard = null,
    ): ?string {
        $usePhoto = $photoUrl !== null && mb_strlen($text) <= 1024;

        $response = $this->call($usePhoto ? 'sendPhoto' : 'sendMessage', array_filter([
            'chat_id' => $chatId,
            $usePhoto ? 'photo' : 'text' => $usePhoto ? $photoUrl : $text,
            $usePhoto ? 'caption' : 'disable_web_page_preview' => $usePhoto ? $text : true,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard ? json_encode(['inline_keyboard' => $keyboard]) : null,
        ], fn ($value) => $value !== null));

        if ($response['ok'] ?? false) {
            return null;
        }

        // A photo Telegram will not fetch - an expired presigned URL, a host it cannot
        // reach - must not cost the viewer the message itself.
        if ($usePhoto) {
            return $this->sendToChat($chatId, $text, null, $keyboard);
        }

        return (string) ($response['description'] ?? 'Unknown Telegram error');
    }

    /**
     * Whether a failed send means this chat is gone for good rather than briefly
     * unhappy. The caller decides what to do about it; for a viewer that is unlinking
     * their account, for an operator chat it is disabling the row.
     */
    public function isFatal(string $description): bool
    {
        $lower = mb_strtolower($description);

        foreach (self::FATAL_DESCRIPTIONS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

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
