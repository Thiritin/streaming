<?php

return [
    /*
     * The master switch moved to config/features.php, so it can be flipped from
     * /manage > Settings > Features rather than only from the environment. Read
     * it through App\Support\Features::chat().
     */

    'default' => [
        /*
         * Max Tries is the amount of messages a user can send within Rate Decay seconds
         * before they are rate limited. Only applies to users without
         * the `chat.ignore.ratelimit` permission.
         *
         * Slow Mode Seconds is the enforced gap between messages when slow mode is on
         * (0 disables it). It can be overridden per source from the mod tools.
         */
        'maxTries' => (int) env('CHAT_MAX_TRIES', 8),
        'rateDecay' => (int) env('CHAT_RATE_DECAY', 30),
        'slowModeSeconds' => (int) env('CHAT_SLOW_MODE_SECONDS', 0),
        'maxMessageLength' => (int) env('CHAT_MAX_MESSAGE_LENGTH', 500),
    ],

    /*
     * Links to these domains stay clickable in chat, everything else is stripped.
     * Empty by default, so a fresh install strips every link until an operator opts
     * domains in.
     *
     * Held as written rather than as a list: /manage > Settings > Chat edits it as one
     * per line and the settings table holds strings, so splitting is the reader's job.
     * App\Services\ChatMessageSanitizer::getAllowedDomains does it, and still accepts a
     * list for anything that sets this in config directly.
     */
    'allowed_domains' => (string) env('CHAT_ALLOWED_DOMAINS', ''),

    /*
     * How many messages the client keeps in memory / the backlog endpoints return.
     */
    'history' => [
        'initial' => 60,
        'page' => 50,
        'buffer' => 300,
    ],
];
