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

    # Gzip compression for HLS content
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/vnd.apple.mpegurl video/mp2t;

    server {
        listen 80;
        listen [::]:80;
        server_name _;

        # Health check endpoint
        location /health {
            access_log off;
            return 200 "healthy\n";
            add_header Content-Type text/plain;
        }

        # HLS m3u8 playlist files - serve from shared volume (no auth needed on origin)
        location ~ ^/live/(.+\.m3u8)$ {
            # No authentication for m3u8 playlists
            
            # Serve files from the HLS output directory
            root /var/www/hls;
            try_files $uri =404;
            
            # Content type headers only
            add_header Content-Type "application/vnd.apple.mpegurl";
            add_header X-Content-Type-Options "nosniff";
        }

        # TS segment files - serve from shared volume (no auth needed on origin)
        location ~ ^/live/(.+\.ts)$ {
            # Serve files from the HLS output directory
            root /var/www/hls;
            try_files $uri =404;
            
            # Content type headers only
            add_header Content-Type "video/mp2t";
            add_header X-Content-Type-Options "nosniff";
        }

        # Default location
        location / {
            return 404;
        }
    }
}