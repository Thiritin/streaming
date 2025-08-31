# EF Streaming Edge Server Installation Script
# Generated: 2025-08-31 18:55:53
# Server ID: 10
# Hostname: edge-10-Ur16YGLgHK2J.stream.eurofurence.org

set -e

echo "================================================"
echo "EF Streaming Server Installation"
echo "Server Type: edge"
echo "Generated: 2025-08-31 18:55:53"
echo "================================================"

# Update system
apt-get update
apt-get upgrade -y

# Install Docker
if ! command -v docker &> /dev/null; then
    echo "Installing Docker..."
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
else
    echo "Docker already installed"
fi

# Install Docker Compose
if ! command -v docker-compose &> /dev/null; then
    echo "Installing Docker Compose..."
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
else
    echo "Docker Compose already installed"
fi

# Create working directory
mkdir -p /opt/ef-streaming
cd /opt/ef-streaming

# Create environment file
cat > .env <<EOF
SERVER_ID=10
SERVER_TYPE=edge
SHARED_SECRET=2rf93eFTm1dmlxRwDVyfGgkk5QYxVixG7TUW3JLK
APP_URL=https://well-oarfish-oddly.ngrok-free.app

# DVR S3 Storage Configuration
DVR_AWS_ACCESS_KEY_ID=
DVR_AWS_SECRET_ACCESS_KEY=
DVR_AWS_DEFAULT_REGION=eu-west-1
DVR_AWS_BUCKET=streaming-dvr
DVR_AWS_ENDPOINT=https://s3.eurofurence.org
EOF

# Download Edge Docker Compose configuration
cat > docker-compose.yml <<'DOCKERCOMPOSE'
version: '3.8'

services:
  # Edge Nginx - Caching proxy for HLS content
  edge-nginx:
    image: nginx:alpine
    container_name: edge-nginx
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
    tmpfs:
      - /var/cache/nginx:rw,noexec,nosuid,size=512m
    restart: unless-stopped
    networks:
      - streaming

  # Edge Caddy - SSL termination for edge
  edge-caddy:
    image: caddy:alpine
    container_name: edge-caddy
    ports:
      - "80:80"
      - "443:443"
    environment:
      DOMAIN: edge-10-Ur16YGLgHK2J.stream.eurofurence.org
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
    restart: unless-stopped
    depends_on:
      - edge-nginx
    networks:
      - streaming

networks:
  streaming:
    driver: bridge

volumes:
  caddy-data:
  caddy-config:DOCKERCOMPOSE

# Create Edge Nginx configuration
cat > nginx.conf <<'NGINXCONF'
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

    # Upstream for origin Caddy server
    upstream origin_caddy {
        server origin-8-1Fn5B9fkKSmO.stream.eurofurence.org:8070;
        keepalive 32;
    }

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
            proxy_pass http://well-oarfish-oddly.ngrok-free.app:443/api/hls/auth;
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
            # Proxy to origin Caddy server
            proxy_pass http://origin_caddy$request_uri;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
            
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
            
            # Proxy to origin Caddy server
            proxy_pass http://origin_caddy$request_uri;
            proxy_http_version 1.1;
            proxy_set_header Host $host;
            proxy_set_header X-Real-IP $remote_addr;
            proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
            proxy_set_header Connection "";
            
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
}NGINXCONF

# Create Edge Caddy configuration
cat > Caddyfile <<'CADDYFILE'
edge-10-Ur16YGLgHK2J.stream.eurofurence.org {
    reverse_proxy edge-nginx:80
}CADDYFILE
# Start services
echo "Starting Docker services..."
docker compose up -d

# Wait for services to be ready
echo "Waiting for services to start..."
WAITED=0
MAX_WAIT=60
while [ $WAITED -lt $MAX_WAIT ]; do
    if [ "edge" = "origin" ]; then
        # For origin, check if SRS is responding
        if curl -s http://localhost:1985/api/v1/versions > /dev/null 2>&1; then
            echo "Origin services are ready!"
            break
        fi
    else
        # For edge, check if nginx is responding
        if curl -s http://localhost:8081/health > /dev/null 2>&1; then
            echo "Edge services are ready!"
            break
        fi
    fi
    echo "Waiting for services... ($WAITED/$MAX_WAIT seconds)"
    sleep 5
    WAITED=$((WAITED + 5))
done

# Show service status
docker compose ps

# Get server information
# Force IPv4 for PUBLIC_IP and use the configured hostname
PUBLIC_IP=$(curl -4 -s ifconfig.me)
HOSTNAME="edge-10-Ur16YGLgHK2J.stream.eurofurence.org"

echo "================================================"
echo "Server Information:"
echo "  Public IP: $PUBLIC_IP"
echo "  Hostname: $HOSTNAME"
echo "  Server Type: edge"
echo "  Server ID: 10"
echo "================================================"

# Register server with main app (optional - may fail if network not ready)
echo "Attempting to register server with main application..."
curl -L -X POST "https://well-oarfish-oddly.ngrok-free.app/api/server/register" \
     -H "X-Shared-Secret: 2rf93eFTm1dmlxRwDVyfGgkk5QYxVixG7TUW3JLK" \
     -H "Content-Type: application/json" \
     -d "{
         \"server_id\": \"10\",
         \"hostname\": \"$HOSTNAME\",
         \"ip\": \"$PUBLIC_IP\",
         \"status\": \"active\"
     }" || echo "Registration failed - server will register on first heartbeat"

echo "================================================"
echo "Installation complete!"
echo "Server is ready at: $PUBLIC_IP"
echo "================================================"

# Setup auto-restart on boot
systemctl enable docker

# Setup heartbeat cron job (every minute)
(crontab -l 2>/dev/null; echo "* * * * * /opt/ef-streaming/heartbeat.sh") | crontab -

exit 0
