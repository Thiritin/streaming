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

    /*
     | Read once, by the migration that seeded the convention's provider row, and by
     | nothing else. The ways in are rows in `auth_providers` now, edited at /manage >
     | Settings > Sign-in providers; these entries survive only so an installation that
     | still sets them in its environment came out of that migration configured.
     */
    'oidc' => [
        'url' => env('OIDC_URL'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'secret' => env('OIDC_SECRET'),
    ],

    'attsrv' => [
        'url' => env('ATTSRV_URL'),
    ],

    'hetzner' => [
        'token' => env('HETZNER_TOKEN'),
    ],
];
