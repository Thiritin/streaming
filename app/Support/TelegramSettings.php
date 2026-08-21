<?php

namespace App\Support;

use App\Models\BrandingSetting;
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

    public static function webhookUrl(): string
    {
        return route('api.telegram.webhook');
    }
}
