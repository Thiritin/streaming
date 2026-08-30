<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the installation's Telegram bot is configured with.
 *
 * One source, as with the control and import keys: the settings table, edited at
 * /manage > Settings > Telegram. The config file only names the shipped fallbacks.
 *
 * Nothing saved means no bot, which is the state of a fresh install: every send is a
 * no-op and the webhook answers 404 rather than pretending to be a Telegram endpoint.
 */
final class TelegramSettings
{
    public const TOKEN_KEY = 'telegram_bot_token';

    public const SECRET_KEY = 'telegram_webhook_secret';

    public const LEAD_KEY = 'telegram_show_lead_minutes';

    public const USERNAME_KEY = 'telegram_bot_username';

    public static function token(): string
    {
        return trim((string) BrandingSetting::getValue(self::TOKEN_KEY, config('telegram.bot_token')));
    }

    public static function configured(): bool
    {
        return self::token() !== '';
    }

    /**
     * The secret Telegram echoes back on every webhook call. Generated on first use
     * rather than typed: it is machine-to-machine and nobody needs to read it.
     */
    public static function webhookSecret(): string
    {
        $secret = trim((string) BrandingSetting::getValue(self::SECRET_KEY, config('telegram.webhook_secret')));

        if ($secret === '') {
            $secret = Str::random(48);
            BrandingSetting::setValue(self::SECRET_KEY, $secret, 'Telegram webhook secret token');
        }

        return $secret;
    }

    public static function rotateWebhookSecret(): string
    {
        $secret = Str::random(48);
        BrandingSetting::setValue(self::SECRET_KEY, $secret, 'Telegram webhook secret token');

        return $secret;
    }

    /**
     * How many minutes before its scheduled start a show is announced. Clamped so a
     * typo cannot turn the upcoming scan into a scan of the whole programme.
     */
    public static function leadMinutes(): int
    {
        $minutes = (int) BrandingSetting::getValue(self::LEAD_KEY, config('telegram.show_lead_minutes', 5));

        return max(1, min(120, $minutes ?: 5));
    }

    /**
     * The bot's @name, without the @, or null when nothing knows it yet.
     *
     * Everything viewer-facing needs this: a link code is useless without knowing
     * which bot to send it to, and the one-tap connect link is built out of it.
     */
    public static function username(): ?string
    {
        $username = trim((string) BrandingSetting::getValue(self::USERNAME_KEY, config('telegram.bot_username')));

        return $username === '' ? null : ltrim($username, '@');
    }

    /**
     * Record what getMe answered, unless an operator has typed something in the
     * field themselves - their answer is the one people were told to talk to.
     */
    public static function rememberUsername(?string $username): void
    {
        $username = ltrim(trim((string) $username), '@');

        if ($username === '' || self::username() !== null) {
            return;
        }

        BrandingSetting::setValue(self::USERNAME_KEY, $username, 'Telegram bot @name, read off the token');
    }

    /**
     * The handle, asking Telegram for it once if nothing has stored it yet.
     *
     * The stored value is written when a token is saved, so this only does any work on
     * an installation whose token predates that. Cached either way: it is read on a
     * page viewers open, not one operators do.
     */
    public static function resolveUsername(): ?string
    {
        $stored = self::username();

        if ($stored !== null || ! self::configured()) {
            return $stored;
        }

        // An empty string rather than null, because Cache::remember treats a cached
        // null as a miss and would call getMe again on every page load for a bot that
        // has no handle to give.
        $username = Cache::remember(
            'telegram_bot_username_lookup',
            3600,
            fn () => (string) (app(TelegramClient::class)->me()['username'] ?? ''),
        );

        self::rememberUsername($username);

        return $username === '' ? null : ltrim($username, '@');
    }

    /**
     * A token cleared takes the handle with it. Leaving a stale @name behind would
     * point every viewer at a bot this installation no longer runs.
     */
    public static function forgetUsername(): void
    {
        // Deleted one at a time so the model's deleted hook drops its cache entry; a
        // mass delete on the query builder fires no events and the old handle would
        // keep being read for the rest of the hour.
        BrandingSetting::where('key', self::USERNAME_KEY)->get()->each->delete();

        Cache::forget('telegram_bot_username_lookup');
    }

    /**
     * Where a viewer is sent to attach their account: Telegram opens the bot with
     * Start already carrying the code, so nothing has to be pasted.
     *
     * Null without a known @name, and the caller falls back to showing the code.
     */
    public static function connectUrl(string $code): ?string
    {
        $username = self::username();

        return $username === null ? null : 'https://t.me/'.$username.'?start='.rawurlencode($code);
    }

    public static function webhookUrl(): string
    {
        return route('api.telegram.webhook');
    }
}
