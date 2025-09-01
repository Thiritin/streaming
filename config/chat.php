<?php

return [
    'default' => [
        /*
         * Max Tries is the amount of tries a user can send a message before they are rate limited.
         * This is only used for users that are not a moderator or higher.
         * Rate Decay is the amount of time in seconds before the rate limit resets.
         * In Slow Mode Rate Decay is the amount of seconds between each message.
         */
        'maxTries' => 8,
        'rateDecay' => 30,
        'slowMode' => false,
    ],
];
