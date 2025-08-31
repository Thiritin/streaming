user nginx;
worker_processes auto;
error_log /var/log/nginx/error.log warn;
pid /var/run/nginx.pid;

events {
    worker_connections 4096;
    use epoll;
    multi_accept on;
}

http {
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
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/vnd.apple.mpegurl video/mp2t;

    # Rate limiting
    limit_req_zone $binary_remote_addr zone=viewer_limit:10m rate=30r/s;
    limit_conn_zone $binary_remote_addr zone=viewer_conn:10m;

    # Cache paths for different content types - optimized for quality switching
    proxy_cache_path /var/cache/nginx/auth levels=1:2 keys_zone=auth_cache:10m
                     max_size=100m inactive=10s use_temp_path=off;

    proxy_cache_path /var/cache/nginx/hls levels=1:2 keys_zone=hls_cache:10m
                     max_size=100m inactive=2s use_temp_path=off;

    proxy_cache_path /var/cache/nginx/segments levels=1:2 keys_zone=segment_cache:100m
                     max_size=2g inactive=1h use_temp_path=off
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
        limit_req zone=viewer_limit burst=50 nodelay;
        limit_conn viewer_conn 10;

        # Health check endpoint
        location /health {
            access_log off;
            return 200 "healthy\n";
            add_header Content-Type text/plain;
        }

        # Authentication subrequest endpoint
        location = /auth {
            internal;
            proxy_pass {{ $nginxUpstream }}/api/hls/auth;
            proxy_pass_request_body off;
            proxy_set_header Content-Length "";
            proxy_set_header X-Original-URI $request_uri;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header X-Forwarded-Proto $scheme;
            proxy_set_header X-Edge-Server "edge-nginx";

            # Pass streamkey as header for authentication
            proxy_set_header X-Stream-Key $arg_streamkey;

            # Cache auth responses for performance
            proxy_cache auth_cache;
            proxy_cache_key "$remote_addr:$arg_streamkey:$uri";
            proxy_cache_valid 200 10s;
            proxy_cache_valid 401 403 1s;
        }

        # HLS m3u8 playlist files - proxy and cache from origin
        location ~ ^/live/(.+\.m3u8)$ {
@if($useInternalNetwork)
            # Proxy to origin Caddy via internal network (HTTPS with internal IP)
            proxy_pass https://origin_internal$request_uri;
            proxy_http_version 1.1;
            
            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            
            proxy_set_header Host {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@else
            # Proxy to origin Caddy server (HTTPS)
            proxy_pass https://origin_caddy$request_uri;
            proxy_http_version 1.1;
            
            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            
            proxy_set_header Host {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@endif

            # Cache configuration for m3u8 playlists
            proxy_cache hls_cache;
            # Cache key uses URI without query parameters
            proxy_cache_key "$scheme$proxy_host$uri";
            proxy_cache_valid 200 2s;
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
            proxy_ssl_name {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            
            proxy_set_header Host {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@else
            # Proxy to origin Caddy server (HTTPS)
            proxy_pass https://origin_caddy$request_uri;
            proxy_http_version 1.1;
            
            # SSL/SNI configuration for proper certificate validation
            proxy_ssl_server_name on;
            proxy_ssl_name {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            
            proxy_set_header Host {{ $originServer ? $originServer->hostname : 'origin.stream.eurofurence.org' }};
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
@endif

            # Cache configuration for TS segments
            proxy_cache segment_cache;
            # Cache key uses URI without query parameters
            proxy_cache_key "$scheme$proxy_host$uri";
            proxy_cache_valid 200 5m;
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
            expires 5m;
            add_header Cache-Control "public, max-age=300, immutable";
            add_header Content-Type "video/mp2t";
            add_header X-Content-Type-Options "nosniff";
        }

        # Default location
        location / {
            return 404;
        }
    }
}
