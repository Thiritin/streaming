# njs verifies playback tokens locally with an HMAC, so a request carrying ?t=
# never reaches Laravel. See docs/streaming-auth-redesign.md.
load_module modules/ngx_http_js_module.so;

user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log warn;
pid /var/run/nginx.pid;

# Passed through to njs as process.env. Keeping the secrets in the environment
# rather than in this file means they are not written to disk here.
env HLS_VIEWER_SECRET;
env HLS_EMBED_SECRET;
env HLS_TOKEN_LEEWAY;
env STREAM_SYSTEM_STREAMKEY;

events {
    worker_connections 4096;
    use epoll;
    multi_accept on;
}

http {
    js_import hlsAuth from /etc/nginx/njs/hls-auth.js;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    # Logging
    access_log /var/log/nginx/access.log;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;
    types_hash_max_size 2048;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    {{-- An 1800-entry DVR playlist is ~179KB raw and ~10KB gzipped, so compressing
         m3u8 is worth real bandwidth. video/mp2t is deliberately absent: MPEG-TS is
         already compressed, so gzipping segments burns CPU for no size gain. --}}
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/vnd.apple.mpegurl;

    # Rate limiting
    # No rate or connection limiting.
    #
    # It was keyed on $binary_remote_addr, which is the wrong key for this workload: a
    # convention NATs its whole audience onto one public IP, so a 10-connection cap
    # meant ten people and a 30r/s cap was shared by hundreds. At hls_time 2 a single
    # viewer makes about one request a second, so neither ceiling ever shaped real
    # load - they only ever throttled the on-site audience.
    #
    # Re-keying on the playback token subject was tried and then dropped. It needed an
    # njs `js_set` on the request path, which fails at *startup* rather than
    # degrading, and it could not be validated before the event. Untested code that
    # takes an edge down on reload is worse than no cap at all.
    #
    # What still gates access: every playlist and segment goes through `auth_request
    # /auth`, verified locally against an HMAC playback token scoped to one source and
    # expiring in 15 minutes. Edges are bandwidth-bound long before connection count
    # matters, and worker_connections above is the real ceiling.

    # Cache paths for different content types - optimized for quality switching
    # There is no auth cache: token verification is local and costs microseconds,
    # so there is nothing to amortise.
    proxy_cache_path /var/cache/nginx/hls levels=1:2 keys_zone=hls_cache:10m
                     max_size=100m inactive=2s use_temp_path=off;

    proxy_cache_path /var/cache/nginx/segments levels=1:2 keys_zone=segment_cache:100m
                     max_size=4g inactive=2m use_temp_path=off
                     loader_files=200 loader_sleep=50ms loader_threshold=300ms;

@if($useInternalNetwork)
    # Upstream for origin Caddy via internal network (HTTPS with internal IP)
    upstream origin_internal {
        server {{ $originInternalUpstream }};
        keepalive 32;
    }
@else
    # Upstream for origin Caddy server (HTTPS with public hostname)
    upstream origin_caddy {
        server {{ $originUpstream }};
        keepalive 32;
    }
@endif

    server {
        listen 80;
        listen [::]:80;
        server_name _;

        # Rate limiting

        # Health check endpoint
        location /health {
            access_log off;
            return 200 "healthy\n";
            add_header Content-Type text/plain;
        }

        # Playback token verification, entirely local: no network call, no PHP.
        # A request with no token, or with one that does not verify, is refused
        # here; nothing on this path can reach Laravel.
        location = /auth {
            internal;
            js_content hlsAuth.verify;
        }

        # HLS m3u8 playlist files - proxy and cache from origin
        location ~ ^/live/(.+\.m3u8)$ {
            # Playlists are authenticated too now; previously only segments were.
            auth_request /auth;
            auth_request_set $auth_status $upstream_status;

@if($useInternalNetwork)
            # Proxy to origin Caddy via internal network (HTTPS with internal IP)
            proxy_pass https://origin_internal$request_uri;
            proxy_http_version 1.1;

            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};

            proxy_set_header Host {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@else
            # Proxy to origin Caddy server (HTTPS)
            proxy_pass https://origin_caddy$request_uri;
            proxy_http_version 1.1;

            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};

            proxy_set_header Host {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@endif

            # Cache configuration for m3u8 playlists
            proxy_cache hls_cache;
            # Cache key uses URI without query parameters
            proxy_cache_key "$scheme$proxy_host$uri";
            # One second, not the segment duration. Laravel's playlist proxy caches
            # the same body again on its own phase, so the two windows add up: at 2s
            # each, a viewer's playlist end measured 3 to 6 seconds behind the newest
            # segment, and a live player's whole forward buffer is only a few seconds
            # of that. Halving this halves the jitter and costs one extra request per
            # variant per second to the origin, which is per stream, not per viewer.
            proxy_cache_valid 200 1s;
            proxy_cache_valid 404 1s;
            proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
            proxy_cache_lock on;
            proxy_cache_lock_timeout 5s;

            # CORS headers
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS' always;
            add_header 'Access-Control-Allow-Headers' 'Range' always;
            add_header 'Access-Control-Expose-Headers' 'Content-Length, Content-Range' always;

            # Add cache status header for debugging
            add_header X-Cache-Status $upstream_cache_status;

            # HLS headers
            add_header Content-Type "application/vnd.apple.mpegurl";
            add_header Cache-Control "no-cache, no-store, must-revalidate";
            add_header X-Content-Type-Options "nosniff";
        }

        # TS segment files - proxy and cache from origin
        location ~ ^/live/(.+\.ts)$ {
            # Perform authentication check
            auth_request /auth;
            auth_request_set $auth_status $upstream_status;

@if($useInternalNetwork)
            # Proxy to origin Caddy via internal network (HTTPS with internal IP)
            proxy_pass https://origin_internal$request_uri;
            proxy_http_version 1.1;

            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};

            proxy_set_header Host {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@else
            # Proxy to origin Caddy server (HTTPS)
            proxy_pass https://origin_caddy$request_uri;
            proxy_http_version 1.1;

            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};

            proxy_set_header Host {{ $originServer ? $originServer->hostname : trim('origin.'.config('dns.zone'), '.') }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@endif

            # Cache configuration for TS segments
            proxy_cache segment_cache;
            # Cache key uses URI without query parameters
            proxy_cache_key "$scheme$proxy_host$uri";
            proxy_cache_valid 200 2m;
            proxy_cache_valid 404 10s;
            proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
            proxy_cache_lock on;
            proxy_cache_lock_timeout 5s;

            # CORS headers
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS' always;
            add_header 'Access-Control-Allow-Headers' 'Range' always;
            add_header 'Access-Control-Expose-Headers' 'Content-Length, Content-Range' always;

            # Add cache status header for debugging
            add_header X-Cache-Status $upstream_cache_status;

            # Cache headers for CDN and browsers
            expires 2m;
            add_header Cache-Control "public, max-age=120, immutable";
            add_header Content-Type "video/mp2t";
            add_header X-Content-Type-Options "nosniff";
        }

        # Default location
        location / {
            return 404;
        }
    }
}
