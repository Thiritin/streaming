<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'oidc' => [
        'url' => env('OIDC_URL'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'secret' => env('OIDC_SECRET'),

        /*
         | Maps identity-provider group IDs from the userinfo "groups" claim onto
         | role slugs. The IDs are issued by whichever provider an installation
         | runs, so they come from the environment as "group:role" pairs, e.g.
         | OIDC_GROUP_ROLE_MAP="KVJ...=admin,54Z...=staff".
         */
        'group_role_map' => collect(explode(',', (string) env('OIDC_GROUP_ROLE_MAP', '')))
            ->map(fn ($pair) => array_map('trim', explode('=', $pair, 2)))
            ->filter(fn ($pair) => count($pair) === 2 && $pair[0] !== '' && $pair[1] !== '')
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]])
            ->all(),
    ],

    'stream' => [
        'origin_ip' => env('ORIGIN_IP'),
    ],

    'attsrv' => [
        'url' => env('ATTSRV_URL'),
    ],

    'srs' => [
        'username' => env('SRS_USERNAME'),
        'password' => env('SRS_PASSWORD'),
        'origin' => env('SRS_ORIGIN'),
    ],

    'hetzner' => [
        'token' => env('HETZNER_TOKEN'),
    ],

    // This is the URL of the origin server, where a low res version is being pushed to via rtmp.
    'forward' => [
        'url' => env('RTMP_FORWARD'),
        'vrchaturl' => env('RTMP_VRCHAT_URL'),
    ],
];
