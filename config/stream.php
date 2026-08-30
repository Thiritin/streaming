<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server provisioning
    |--------------------------------------------------------------------------
    |
    | Where servers are created, what sizes may be chosen, and what each role starts
    | on. Read by CreateVirtualMachineJob and by the "Provision Cloud Server" action.
    |
    | Sizing is a money decision, not a code one. Hetzner bills hourly, so the gap
    | between ccx33 and ccx43 over a two week event is around EUR 70 - worth an operator
    | choosing per server without a deploy.
    |
    | `ccx` sizes are dedicated vCPU, which the x264 ladder needs: three encodes per
    | live source, so four sources is twelve concurrent encodes. Measure with
    | scripts/bench-ladder.sh before trusting a smaller box. Edges are bandwidth-bound
    | rather than CPU-bound, so the cheapest shared size serves as many viewers as the
    | uplink allows.
    */
    'server' => [
        // Who creates the machine. `manual` calls no API at all: the operator supplies
        // an address and a hostname and runs the install script themselves, which also
        // means every secret the generated config bakes in lands on hardware this
        // installation does not own and cannot wipe.
        'provider' => env('CLOUD_DRIVER', 'hetzner'),

        // Where servers are created. Capacity is per location, and a size can be
        // unplaceable in one while fine in another.
        'location' => env('HETZNER_LOCATION', 'nbg1'),

        // What a new machine boots. The transcoder and uploader images are x86 only.
        'image' => env('HETZNER_IMAGE', 'ubuntu-22.04'),

        // Looked up by name in the cloud project. Empty skips the lookup, which
        // provisions a machine nobody can log into by hand.
        'ssh_key' => env('HETZNER_SSH_KEY'),

        // The private network edges reach the origin over. Empty skips the lookup and
        // they use the public address instead.
        'network' => env('HETZNER_NETWORK', 'stream'),

        // Fallback only. The dropdown asks Hetzner what it can actually place right
        // now (see Hetzner::availableServerTypes) - a static list goes stale both when
        // a generation is retired and when a size is temporarily out of stock, and
        // both produce the same unhelpful `422 unsupported location for server type`.
        // This list is what gets used if the API cannot be reached.
        'types' => [
            'cx23' => 'cx23 - 2 vCPU / 4 GB (shared)',
            'cx33' => 'cx33 - 4 vCPU / 8 GB (shared)',
            'cpx22' => 'cpx22 - 2 vCPU / 4 GB (shared)',
            'cpx32' => 'cpx32 - 4 vCPU / 8 GB (shared)',
            'cpx42' => 'cpx42 - 8 vCPU / 16 GB (shared)',
            'ccx13' => 'ccx13 - 2 vCPU / 8 GB (dedicated)',
            'ccx23' => 'ccx23 - 4 vCPU / 16 GB (dedicated)',
            'ccx33' => 'ccx33 - 8 vCPU / 32 GB (dedicated)',
            'ccx43' => 'ccx43 - 16 vCPU / 64 GB (dedicated)',
        ],

        // Preselected in the dropdown, and used for anything provisioned outside it.
        // Origins need dedicated cores for the x264 ladder. Edges are bandwidth-bound,
        // so the cheapest shared size carries as many viewers as the uplink allows.
        'defaults' => [
            'origin' => env('HETZNER_ORIGIN_TYPE', 'ccx33'),
            'edge' => env('HETZNER_EDGE_TYPE', 'cx23'),
        ],

        // Viewer capacity gate per edge, used by the balancer. Not derived from the
        // instance size: edges run out of uplink long before CPU, so this tracks
        // bandwidth per server rather than cores.
        'max_clients' => [
            'origin' => 1000,
            'edge' => 100,
        ],

        // How long the per-minute system samples reported by heartbeat.sh are kept.
        // One row per server per minute is about 43k rows a month per server, which is
        // nothing for the database and more history than anyone reviews after an
        // event. PruneServerMetricsJob drops the rest nightly.
        'metrics_retention_days' => (int) env('SERVER_METRICS_RETENTION_DAYS', 30),
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

    /*
    | Container images baked into the generated provisioning scripts, built from
    | docker/ in this repo by .github/workflows/docker-support-images.yml.
    |
    | Fully qualified on purpose. These defaults used to be bare names -
    | `ffmpeg-hls:latest` - which Docker resolves as official Docker Hub images that do
    | not exist, so `docker compose up` on a fresh origin died with "pull access
    | denied" and the whole stack never started. The registry and namespace have to be
    | part of the default, not something an environment is trusted to remember.
    |
    | Override per environment if the images are published somewhere else.
    */
    'images' => [
        'ffmpeg_hls' => env('STREAM_IMAGE_FFMPEG_HLS', 'ghcr.io/thiritin/ffmpeg-hls:latest'),
        'archive_uploader' => env('STREAM_IMAGE_ARCHIVE_UPLOADER', 'ghcr.io/thiritin/archive-uploader:latest'),
    ],

    // Filesystem disk holding the segment archive and the generated recording
    // playlists. Must be the same bucket archive_uploader.py writes to on the origin.
    //
    // Production uses the dedicated `dvr` disk. Locally there is one versitygw bucket
    // for everything, so point this at `s3` rather than configuring DVR_AWS_* twice.
    'archive_disk' => env('ARCHIVE_DISK', 'dvr'),

    // Bucket size the archive is allowed to fill, in bytes. Zero or unset means the
    // manage panel reports what is stored but not what is left, which is the honest
    // answer: S3 has no free-space call and no provider this runs against exposes a
    // quota over the API, so the limit is whatever was bought and only an operator
    // knows it.
    'archive_quota_bytes' => (int) env('ARCHIVE_QUOTA_BYTES', 0),

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

    // System streamkey for internal operations (thumbnails, monitoring, etc.). Edited at
    // /manage > Settings > Playback security; the env vars are the shipped fallback.
    // STREAM_KEY is the older name for the same key and is read here so an existing
    // deployment keeps working, rather than in a second config entry of its own.
    'system_streamkey' => env('STREAM_SYSTEM_STREAMKEY') ?: env('STREAM_KEY', ''),

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

        // How long a segment token stays byte-identical. Segment tokens are shared
        // by every viewer of a source rather than minted per viewer, so a playlist
        // body is rendered and compressed once per bucket instead of once per
        // request; the bucket is how much staleness that sharing is allowed to
        // introduce into the expiry. See PlaybackTokenService::issueSegmentToken.
        'bucket' => (int) env('HLS_TOKEN_BUCKET', 60),

        // How long before expiry the server pushes a fresh token to the player.
        // The remainder is the budget for the 403 recovery path.
        'refresh_margin' => (int) env('HLS_TOKEN_REFRESH_MARGIN', 180),
    ],

    // The key a hardware control surface authenticates with; see
    // docs/admin/companion.md. One key for the installation, not one per source:
    // which source a surface drives is part of its request, and the people who
    // run the rooms are the same people either way.
    //
    // No default and never an env(): the key is generated and stored at
    // /manage > Settings > Control surfaces, and read through App\Support\ControlKey.
    // This entry only names where the settings registry stores it. Nothing saved
    // means the control API is off, which is what a fresh install has.
    'control_key' => null,

    // Same shape, for the key an offline import authenticates with (tools/streaming-archiver,
    // docs/admin/archive-import.md). A null placeholder naming where the settings registry
    // stores it, never an env(): the table is the only source, so nothing can disagree
    // with what the panel shows. Read it through App\Support\ImportKey::current().
    'import_key' => null,

    // Where the panel points at a built Stream Control module. Every release attaches
    // one under a fixed asset name (.github/workflows/companion.yml), so the "latest"
    // URL keeps answering with the newest build and nothing has to be updated per
    // release. A fork that publishes its own builds overrides this; empty hides the
    // download link, which is what an installation with no published release wants.
    'companion_module_url' => env(
        'COMPANION_MODULE_URL',
        'https://github.com/Thiritin/streaming/releases/latest/download/stream-control-companion.tgz',
    ),

    // Where the panel points at built streaming-archiver binaries. One asset per platform is
    // attached to every release under a fixed name (.github/workflows/streaming-archiver.yml), so
    // the "latest" URL keeps answering with the newest build. Empty hides the download
    // links; see App\Support\ImportCli for the asset names this has to agree with.
    'import_cli_base_url' => env(
        'IMPORT_CLI_BASE_URL',
        'https://github.com/Thiritin/streaming/releases/latest/download',
    ),

    // Local dev loops: with DEV_STREAMS=true, sources play the HLS that
    // scripts/dev-streams.sh writes into public/dev-streams/<slug> instead of
    // being proxied to an edge server that does not exist on a laptop.
    'dev_streams' => env('DEV_STREAMS', false),
];
