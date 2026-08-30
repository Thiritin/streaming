<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DNS
    |--------------------------------------------------------------------------
    |
    | Which provider the A record for a server's hostname is written with, and what
    | that provider needs. Edited at /manage > Settings > Servers; the env values
    | below are the shipped defaults a saved row overlays.
    |
    | Whatever the provider, the zone has to resolve from the public internet: a
    | server takes a Let's Encrypt certificate for its own hostname on first boot,
    | so a private or split-horizon zone leaves it unreachable on 443 and the
    | readiness check never passes.
    |
    */

    // rfc2136 on any sign of nsupdate configuration, which is what every install that
    // predates this pane looks like; nothing at all means nothing is written. Any of the
    // three, not all of them: guessing `none` for an install that was working is silent,
    // and guessing rfc2136 for one that was not is a logged failure.
    'driver' => env('DNS_DRIVER')
        ?: ((env('DNS_SERVER') || env('DNS_ZONE') || env('DNS_KEY_SECRET')) ? 'rfc2136' : 'none'),

    'zone' => env('DNS_ZONE'),

    'ttl' => env('DNS_TTL', 60),

    // rfc2136 / nsupdate. The key has to be authorised for the zone and the updating
    // address allowed: authenticating and being permitted to write are two separate
    // permissions on a BIND zone, and a key can pass the first and fail the second.
    'server' => env('DNS_SERVER'),

    'key_name' => env('DNS_KEY_NAME', 'stream-ddns'),

    'key_algorithm' => env('DNS_KEY_ALGORITHM', 'hmac-sha256'),

    'key_secret' => env('DNS_KEY_SECRET'),

    'cloudflare' => [
        'token' => env('CLOUDFLARE_DNS_TOKEN'),

        // Optional: resolved from the zone name and cached when left empty.
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
    ],

    // Hetzner's DNS console, which is a separate product from Hetzner Cloud and takes
    // a token of its own. The zone must be delegated to Hetzner's nameservers.
    'hetzner' => [
        'token' => env('HETZNER_DNS_TOKEN'),

        'zone_id' => env('HETZNER_DNS_ZONE_ID'),
    ],
];
