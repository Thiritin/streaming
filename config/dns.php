<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DNS Server Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for dynamic DNS updates using nsupdate with TSIG
    | authentication. These values are used to generate the dns.key file
    | dynamically from environment variables.
    |
    */

    'server' => env('DNS_SERVER', '85.199.154.53'),

    'zone' => env('DNS_ZONE', 'stream.eurofurence.org'),

    'key_name' => env('DNS_KEY_NAME', 'stream-ddns'),

    'key_algorithm' => env('DNS_KEY_ALGORITHM', 'hmac-sha256'),

    'key_secret' => env('DNS_KEY_SECRET'),

    'ttl' => env('DNS_TTL', 60),
];
