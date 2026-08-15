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

    // The ABR ladder is not configured here. It lives in one place, the
    // -var_stream_map and -b:v arguments in docker/ffmpeg-hls/stream-manager.sh,
    // because that is what actually produces the renditions. A 'qualities' array
    // used to sit here, read by nothing, and had already drifted (it claimed 3000
    // kbps for hd against the transcoder's 3500).

    // Docker internal networking configuration
    'docker' => [
        'hls_host' => env('DOCKER_HLS_HOST', 'edge'),
        'hls_port' => env('DOCKER_HLS_PORT', 80),
    ],

    // Container images baked into the generated provisioning scripts. Built
    // from docker/ in this repo; set the full reference including the registry
    // namespace an operator publishes them under.
    'images' => [
        'ffmpeg_hls' => env('STREAM_IMAGE_FFMPEG_HLS', 'ffmpeg-hls:latest'),
        'archive_uploader' => env('STREAM_IMAGE_ARCHIVE_UPLOADER', 'archive-uploader:latest'),
    ],

    // Filesystem disk holding the segment archive and the generated recording
    // playlists. Must be the same bucket archive_uploader.py writes to on the origin.
    //
    // Production uses the dedicated `dvr` disk. Locally there is one versitygw bucket
    // for everything, so point this at `s3` rather than configuring DVR_AWS_* twice.
    'archive_disk' => env('ARCHIVE_DISK', 'dvr'),

    // How segment URLs inside a recording playlist are produced.
    //
    // 'signed'  Presigned S3 URLs, straight from the bucket to the player. Production.
    //           The bucket MUST send CORS headers or hls.js cannot fetch the segments:
    //           it reads them over XHR, so a missing Access-Control-Allow-Origin fails
    //           playback even though the URL itself is valid.
    //
    // 'proxy'   Streamed through the app on its own origin. Local only. The dev S3
    //           (versitygw) sends no CORS headers and speaks plain HTTP, which a page
    //           served over TLS blocks as mixed content; a presigned URL cannot be put
    //           behind a proxy to fix either, because the signature covers the Host.
    //           Never use this in production: it puts PHP in the media path for every
    //           two second segment.
    'archive_url_mode' => env('ARCHIVE_URL_MODE', 'signed'),

    // Lifetime of a presigned segment URL. A VOD playlist is fetched once at the start
    // of a session rather than refreshed, so this only has to outlast a viewing; the
    // trade is that a leaked playlist stays usable until the signatures lapse.
    'archive_url_ttl' => (int) env('ARCHIVE_URL_TTL', 86400),

    // Whether the archived original-quality rendition is advertised in a recording's
    // master playlist.
    //
    // The transcoder mirrors the publisher's own bitstream (ARCHIVE_SOURCE on the
    // origin), so the archive holds whatever was actually sent rather than topping
    // out at the 6 Mbps fhd rung. It is never part of the live ladder.
    //
    // Off by default because advertising it hands hls.js a rung at the full
    // contribution bitrate, which every viewer on a fast connection will then pull
    // out of S3. It is always playable explicitly at /archive/{slug}/source.m3u8,
    // which is the path for pulling a master for editing.
    'archive_source_in_master' => (bool) env('ARCHIVE_SOURCE_IN_MASTER', false),

    // System streamkey for internal operations (thumbnails, monitoring, etc.)
    'system_streamkey' => env('STREAM_SYSTEM_STREAMKEY', ''),

    // Playback tokens. Short-lived HMAC-signed capabilities that replace the
    // permanent per-user streamkey, verified locally on the edges so PHP stays
    // out of the media path. See docs/streaming-auth-redesign.md.
    'token' => [
        // Separate secrets per token type, so leaking the viewer secret cannot
        // be used to mint long-lived embed keys. Both are shared with the edges.
        'viewer_secret' => env('HLS_VIEWER_SECRET'),
        'embed_secret' => env('HLS_EMBED_SECRET'),

        // Viewer token lifetime. Expiry is the revocation mechanism, so a ban
        // takes effect within this window at worst.
        'ttl' => (int) env('HLS_TOKEN_TTL', 900),

        // Seconds a token is still accepted past its expiry, to absorb clock
        // drift between app and edges and a refresh that lands late.
        'leeway' => (int) env('HLS_TOKEN_LEEWAY', 60),

        // How long before expiry the server pushes a fresh token to the player.
        // The remainder is the budget for the 403 recovery path.
        'refresh_margin' => (int) env('HLS_TOKEN_REFRESH_MARGIN', 180),
    ],

    // Local dev loops: with DEV_STREAMS=true, sources play the HLS that
    // scripts/dev-streams.sh writes into public/dev-streams/<slug> instead of
    // being proxied to an edge server that does not exist on a laptop.
    'dev_streams' => env('DEV_STREAMS', false),

    // Local streaming server override configuration
    // When client IPs match these subnets, force use of the specified hostname
    'local_streaming_ipv4_subnet' => env('LOCAL_STREAMING_IPV4_SUBNET', ''),
    'local_streaming_ipv6_subnet' => env('LOCAL_STREAMING_IPV6_SUBNET', ''),
    'local_streaming_hostname' => env('LOCAL_STREAMING_HOSTNAME', ''),
];
