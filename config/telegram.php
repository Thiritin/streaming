<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram bot
    |--------------------------------------------------------------------------
    |
    | One bot for the installation. Everything an operator sets lives in the
    | settings table and is edited at /manage > Settings > Telegram; the keys here
    | are the shipped fallbacks, and the token deliberately has no env() behind it
    | for the same reason the control and import keys have none - a second source
    | could only ever disagree with the row the panel shows.
    |
    | Read these through App\Support\TelegramSettings, never config() directly.
    |
    */

    'bot_token' => null,

    /*
     | The secret Telegram sends back in X-Telegram-Bot-Api-Secret-Token on every
     | webhook call. Generated when the webhook is registered, never typed, and
     | rotated by registering again.
     */
    'webhook_secret' => null,

    /*
     | How long before a show's scheduled start the chat is told about it. The
     | message is what carries the Start button, so this is also "how early can
     | somebody press Play from Telegram".
     */
    'show_lead_minutes' => 5,

    /*
     | The bot's @name, without the @. Never typed in normal use: it is read off
     | the token with getMe when the token is saved, because a bot's handle
     | belongs to Telegram rather than to this installation. The field in the
     | panel exists so an operator can see it, and override it in the one case
     | where Telegram's answer is not what people are told to talk to.
     */
    'bot_username' => null,

    'api_url' => 'https://api.telegram.org',

    /*
     | How long a `/link` code stays valid. It is pasted into a group chat, so it
     | should not outlive the moment somebody is standing there doing it.
     */
    'link_code_ttl_minutes' => 30,

];
