<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Streaming Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the streaming infrastructure including RTMP, HLS,
    | and server provisioning settings.
    |
    */

    // RTMP server configuration
    'rtmp_host' => env('STREAM_RTMP_HOST', 'localhost:1935'),
    'rtmp_port' => env('STREAM_RTMP_PORT', 1935),

    // Session validation
    'validate_session_ip' => env('STREAM_VALIDATE_SESSION_IP', false),
    'session_timeout' => env('STREAM_SESSION_TIMEOUT', 60), // seconds

    // HLS tracker API key
    'hls_tracker_api_key' => env('STREAM_HLS_TRACKER_API_KEY', ''),

    // Server provisioning
    'server' => [
        'origin' => [
            'type' => 'ccx33',
            'max_streams' => 10,
        ],
        'edge' => [
            'type' => 'cx22',
            'max_clients' => 100,
        ],
    ],

    // Auto-scaling thresholds
    'autoscale' => [
        'enabled' => env('STREAM_AUTOSCALE_ENABLED', false),
        'min_servers' => env('STREAM_AUTOSCALE_MIN_SERVERS', 1),
        'max_servers' => env('STREAM_AUTOSCALE_MAX_SERVERS', 10),
        'scale_up_threshold' => env('STREAM_AUTOSCALE_UP_THRESHOLD', 80), // % capacity
        'scale_down_threshold' => env('STREAM_AUTOSCALE_DOWN_THRESHOLD', 20), // % capacity
        'cooldown_minutes' => env('STREAM_AUTOSCALE_COOLDOWN', 5),
    ],

    // Stream quality settings (bitrates in kbps)
    'qualities' => [
        'fhd' => [
            'resolution' => '1920x1080',
            'video_bitrate' => 6000,
            'audio_bitrate' => 192,
            'fps' => 30,
        ],
        'hd' => [
            'resolution' => '1280x720',
            'video_bitrate' => 3000,
            'audio_bitrate' => 160,
            'fps' => 30,
        ],
        'sd' => [
            'resolution' => '854x480',
            'video_bitrate' => 1500,
            'audio_bitrate' => 128,
            'fps' => 30,
        ],
    ],

    // Docker internal networking configuration
    'docker' => [
        'hls_host' => env('DOCKER_HLS_HOST', 'edge'),
        'hls_port' => env('DOCKER_HLS_PORT', 80),
    ],

    // Internal session ID for system operations
    'internal_session_id' => env('STREAM_INTERNAL_SESSION_ID', ''),
];
