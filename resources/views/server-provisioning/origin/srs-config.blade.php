listen              1935;
max_connections     300;
server_id           71;

srs_log_tank        console;
daemon              off;

# HTTP API for stream monitoring
http_api {
    enabled         on;
    listen          1985;
}

# HTTP server for stats/debugging (not for HLS)
http_server {
    enabled         on;
    listen          8082;
    dir             ./objs/nginx/html;
}

# Disable RTC
rtc_server {
    enabled         off;
}

# Main vhost - simple passthrough
vhost __defaultVhost__ {
    # Webhook authentication for publishing
    http_hooks {
        enabled         on;
        on_publish      {{ $serverUrl }}/api/srs/auth;
        on_unpublish    {{ $serverUrl }}/api/srs/unpublish;
        on_dvr          {{ $serverUrl }}/api/srs/dvr;
    }

    # DVR is off: the segment archive is the recording path now.
    #
    # SRS used to write segmented MP4 alongside HLS, as the source for the old
    # extract/concat/re-encode pipeline and later as a cold backup. Both jobs are gone:
    # recordings are cut from the HLS segments the transcoder already produces, and a
    # 90 minute soak showed the MP4 copy costing more disk than the archive it backed up.
    # See docs/dvr-archive-plan.md.
    dvr {
        enabled             off;
    }

    # Force low latency for all streams
    play {
        gop_cache       on;
        mw_latency      1800;
    }

    # No HTTP remux
    http_remux {
        enabled         off;
    }
}