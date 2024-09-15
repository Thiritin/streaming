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
    ],

    'stream' => [
        'origin_ip' => env('ORIGIN_IP')
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
        'token' => env('HETZNER_TOKEN')
    ],

    'signage' => [
        'streamkey' => env('SIGNAGE_STREAMKEY'),
    ],

    // This is the URL of the origin server, where a low res version is being pushed to via rtmp.
    'forward' => [
        'url' => env('RTMP_FORWARD'),
        'vrchaturl' => env('RTMP_VRCHAT_URL')
    ]
];
