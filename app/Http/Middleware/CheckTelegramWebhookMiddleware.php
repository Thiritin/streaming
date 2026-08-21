<?php

namespace App\Http\Middleware;

use App\Support\Features;
use App\Support\TelegramSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Telegram webhook.
 *
 * Telegram echoes the secret it was given at setWebhook back in a header on every call,
 * which is the whole authentication story for this endpoint - the URL itself is not a
 * secret and turns up in logs.
 *
 * An installation with no bot, or with telegram switched off, answers 404: there is no
 * endpoint to find, rather than one that refuses politely.
 */
class CheckTelegramWebhookMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Features::telegram() && TelegramSettings::configured(), 404);

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        abort_unless($provided !== '' && hash_equals(TelegramSettings::webhookSecret(), $provided), 404);

        return $next($request);
    }
}
