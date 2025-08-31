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

    # DVR configuration for recording streams
    dvr {
        enabled             on;
        # Apply to all streams
        dvr_apply           all;
        # Use segment plan to split files
        dvr_plan            segment;
        # Path with stream-based organization and timestamp
        # Creates: /dvr/recordings/[app]/[stream]/[2006]-[01]-[02]/[15]-[04]-[05]_[timestamp].mp4
        dvr_path            /dvr/recordings/[app]/[stream]/[2006]-[01]-[02]/[15]-[04]-[05]_[timestamp].mp4;
        # 10 minutes per segment (600 seconds)
        dvr_duration        600;
        # Wait for keyframe before splitting
        dvr_wait_keyframe   on;
        # Full time jitter handling for proper timestamps
        time_jitter         full;
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