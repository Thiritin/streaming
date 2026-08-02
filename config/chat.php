<?php

return [
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
     */
    'allowed_domains' => [
        'eurofurence.org',
    ],

    /*
     * How many messages the client keeps in memory / the backlog endpoints return.
     */
    'history' => [
        'initial' => 60,
        'page' => 50,
        'buffer' => 300,
        // Lines sent to the browse page chat excerpt; it shows as many as fit.
        'excerpt' => 40,
    ],
];
