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

    'server' => env('DNS_SERVER'),

    'zone' => env('DNS_ZONE'),

    'key_name' => env('DNS_KEY_NAME', 'stream-ddns'),

    'key_algorithm' => env('DNS_KEY_ALGORITHM', 'hmac-sha256'),

    'key_secret' => env('DNS_KEY_SECRET'),

    'ttl' => env('DNS_TTL', 60),
];
