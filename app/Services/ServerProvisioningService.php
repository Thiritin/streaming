<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Str;

class ServerProvisioningService
{
    /**
     * Generate install script for a server
     * This can be used for both Hetzner cloud-init and manual installation
     */
    public function generateInstallScript(Server $server): string
    {
        $serverUrl = config('app.url');
        $sharedSecret = $server->shared_secret ?: Str::random(32);

        // Update server with shared secret if not set
        if (!$server->shared_secret) {
            $server->update(['shared_secret' => $sharedSecret]);
        }

        // Generate the install script
        $script = <<<BASH
#!/bin/bash
# EF Streaming Server Installation Script
# Generated: {$this->getCurrentTimestamp()}
# Server Type: {$server->type->value}
# Server ID: {$server->id}

set -e  # Exit on error

echo "================================================"
echo "EF Streaming Server Installation"
echo "Server Type: {$server->type->value}"
echo "================================================"

# Update system
echo "Updating system packages..."
apt-get update
apt-get upgrade -y

# Install required packages
echo "Installing required packages..."
apt-get install -y \
    apt-transport-https \
    ca-certificates \
    curl \
    software-properties-common \
    python3 \
    python3-pip \
    nginx \
    ffmpeg \
    htop \
    net-tools \
    iotop

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
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-\$(uname -s)-\$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
fi

# Create directory structure
echo "Creating directory structure..."
mkdir -p /opt/ef-streaming/{config,scripts,logs}
cd /opt/ef-streaming

# Download configuration files
echo "Downloading configuration files..."

# Download appropriate configs based on server type
if [ "{$server->type->value}" = "origin" ]; then
    # Origin server configs
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o config/origin.conf \\
         "{$serverUrl}/api/server/config/srs-origin?server_id={$server->id}"
    
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o config/origin-nginx.conf \\
         "{$serverUrl}/api/server/config/nginx-origin?server_id={$server->id}"
    
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o config/origin-caddy.Caddyfile \\
         "{$serverUrl}/api/server/config/caddy-origin?server_id={$server->id}"
    
    # Create FFmpeg HLS directory and download files
    mkdir -p ffmpeg-hls
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o ffmpeg-hls/Dockerfile \\
         "{$serverUrl}/api/server/config/ffmpeg-dockerfile?server_id={$server->id}"
    
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o ffmpeg-hls/stream-manager.sh \\
         "{$serverUrl}/api/server/config/ffmpeg-script?server_id={$server->id}"
    chmod +x ffmpeg-hls/stream-manager.sh
else
    # Edge server configs
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o config/edge-nginx.conf \\
         "{$serverUrl}/api/server/config/nginx-edge?server_id={$server->id}"
    
    curl -H "X-Shared-Secret: {$sharedSecret}" \\
         -o config/edge-caddy.Caddyfile \\
         "{$serverUrl}/api/server/config/caddy-edge?server_id={$server->id}"
fi

curl -H "X-Shared-Secret: {$sharedSecret}" \\
     -o docker-compose.yml \\
     "{$serverUrl}/api/server/config/docker-compose?server_id={$server->id}"

# Download HLS session tracker
echo "Installing HLS session tracker..."
curl -H "X-Shared-Secret: {$sharedSecret}" \\
     -o scripts/hls_session_tracker.py \\
     "{$serverUrl}/api/server/scripts/hls-tracker?server_id={$server->id}"
chmod +x scripts/hls_session_tracker.py

# Install Python dependencies
pip3 install requests

# Configure host NGINX (optional - for local proxying)
echo "Configuring host NGINX (optional)..."
if [ -f config/host-nginx.conf ]; then
    cp config/host-nginx.conf /etc/nginx/sites-available/ef-streaming
    ln -sf /etc/nginx/sites-available/ef-streaming /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    nginx -t && systemctl reload nginx || echo "Host NGINX config skipped"
fi

# Create log directory for HLS tracking
mkdir -p /var/log/nginx
touch /var/log/nginx/hls_activity.log
chown www-data:www-data /var/log/nginx/hls_activity.log

# Test and reload NGINX
nginx -t && systemctl reload nginx

# Create systemd service for HLS tracker
cat > /etc/systemd/system/hls-tracker.service <<EOF
[Unit]
Description=HLS Session Tracker
After=network.target nginx.service
Wants=nginx.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/opt/ef-streaming
Environment="HLS_ACTIVITY_LOG=/var/log/nginx/hls_activity.log"
Environment="SESSION_TIMEOUT=60"
Environment="CHECK_INTERVAL=10"
Environment="API_ENDPOINT={$serverUrl}/api/hls"
Environment="API_KEY={$sharedSecret}"
ExecStart=/usr/bin/python3 /opt/ef-streaming/scripts/hls_session_tracker.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Enable and start HLS tracker
systemctl daemon-reload
systemctl enable hls-tracker
systemctl start hls-tracker

# Start Docker containers
echo "Starting Docker containers..."
cd /opt/ef-streaming
docker-compose up -d

# Configure firewall (if ufw is installed)
if command -v ufw &> /dev/null; then
    echo "Configuring firewall..."
    ufw allow 22/tcp    # SSH
    ufw allow 80/tcp    # HTTP
    ufw allow 443/tcp   # HTTPS
    ufw allow 1935/tcp  # RTMP
    ufw allow 8080/tcp  # SRS HTTP API
    ufw --force enable
fi

# Register server with API
echo "Registering server with API..."
curl -X POST \\
     -H "Content-Type: application/json" \\
     -H "X-Shared-Secret: {$sharedSecret}" \\
     -d '{
         "server_id": {$server->id},
         "hostname": "'\$(hostname -f)'",
         "ip": "'\$(curl -s ifconfig.me)'",
         "status": "active"
     }' \\
     "{$serverUrl}/api/server/register"

# Setup monitoring
echo "Setting up monitoring..."
cat > /opt/ef-streaming/scripts/health-check.sh <<'HEALTHSCRIPT'
#!/bin/bash
# Health check script

# Check if Docker containers are running
if ! docker ps | grep -q srs; then
    echo "SRS container not running"
    exit 1
fi

# Check if NGINX is running
if ! systemctl is-active --quiet nginx; then
    echo "NGINX not running"
    exit 1
fi

# Check if HLS tracker is running
if ! systemctl is-active --quiet hls-tracker; then
    echo "HLS tracker not running"
    exit 1
fi

echo "All services healthy"
exit 0
HEALTHSCRIPT
chmod +x /opt/ef-streaming/scripts/health-check.sh

# Add cron job for health reporting
(crontab -l 2>/dev/null; echo "*/5 * * * * /opt/ef-streaming/scripts/health-check.sh && curl -X POST -H 'X-Shared-Secret: {$sharedSecret}' {$serverUrl}/api/server/{$server->id}/heartbeat") | crontab -

echo "================================================"
echo "Installation complete!"
echo "Server Type: {$server->type->value}"
echo "Server ID: {$server->id}"
echo ""
echo "Services status:"
systemctl status nginx --no-pager | head -n 5
systemctl status hls-tracker --no-pager | head -n 5
docker ps
echo ""
echo "Logs:"
echo "  NGINX: /var/log/nginx/error.log"
echo "  HLS Tracker: journalctl -u hls-tracker -f"
echo "  Docker: docker-compose logs -f"
echo "================================================"
BASH;

        return $script;
    }

    /**
     * Generate cloud-init configuration for Hetzner
     */
    public function generateCloudInit(Server $server): string
    {
        $installScript = $this->generateInstallScript($server);

        // Convert to cloud-init format
        $cloudInit = <<<YAML
#cloud-config
package_upgrade: true
packages:
  - apt-transport-https
  - ca-certificates
  - curl
  - software-properties-common
  - python3
  - python3-pip
  - nginx
  - ffmpeg

write_files:
  - path: /root/install.sh
    permissions: '0755'
    content: |
YAML;

        // Indent the install script for YAML
        $lines = explode("\n", $installScript);
        foreach ($lines as $line) {
            $cloudInit .= "      " . $line . "\n";
        }

        $cloudInit .= <<<YAML

runcmd:
  - /root/install.sh > /var/log/ef-streaming-install.log 2>&1
YAML;

        return $cloudInit;
    }

    /**
     * Generate Docker Compose configuration
     */
    public function generateDockerCompose(Server $server): string
    {
        $isOrigin = $server->type->value === 'origin';
        $appUrl = config('app.url');
        
        if ($isOrigin) {
            // Origin server configuration
            $config = <<<YAML
version: '3.8'

services:
  # Origin SRS - RTMP ingest
  origin-srs:
    image: ossrs/srs:6
    container_name: origin-srs
    restart: always
    ports:
      - "1935:1935"    # RTMP
      - "1985:1985"    # HTTP API
      - "8082:8082"    # HTTP FLV
    volumes:
      - ./config/origin.conf:/usr/local/srs/conf/origin.conf:ro
      - ./logs/srs:/usr/local/srs/objs/logs
    environment:
      - SRS_HTTP_PORT=8082
    command: ["./objs/srs", "-c", "/usr/local/srs/conf/origin.conf"]
    networks:
      - streaming

  # FFmpeg HLS transcoder
  origin-ffmpeg-hls:
    build:
      context: ./ffmpeg-hls
      dockerfile: Dockerfile
    container_name: origin-ffmpeg-hls
    restart: always
    environment:
      - SRS_API_URL=http://origin-srs:1985/api/v1
      - SRS_RTMP_URL=rtmp://origin-srs:1935
      - OUTPUT_BASE_DIR=/var/www/hls/live
      - CHECK_INTERVAL=5
    volumes:
      - hls-content:/var/www/hls
      - ./logs/ffmpeg:/var/log/ffmpeg
    depends_on:
      - origin-srs
    networks:
      - streaming

  # Origin Nginx - Auth and serve HLS
  origin-nginx:
    image: nginx:alpine
    container_name: origin-nginx
    restart: always
    ports:
      - "8083:8083"
    volumes:
      - ./config/origin-nginx.conf:/etc/nginx/nginx.conf:ro
      - hls-content:/var/www/hls:ro
      - nginx-cache:/var/cache/nginx
      - ./logs/nginx:/var/log/nginx
    depends_on:
      - origin-ffmpeg-hls
    networks:
      - streaming

  # Origin Caddy - SSL termination
  origin-caddy:
    image: caddy:alpine
    container_name: origin-caddy
    restart: always
    ports:
      - "8080:8080"    # Production port
      - "443:443"      # HTTPS
    environment:
      - DOMAIN=\${DOMAIN:-localhost}
      - NGINX_ORIGIN_PORT=8083
    volumes:
      - ./config/origin-caddy.Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
      - ./logs/caddy:/var/log/caddy
    depends_on:
      - origin-nginx
    networks:
      - streaming

volumes:
  hls-content:
  nginx-cache:
  caddy-data:
  caddy-config:

networks:
  streaming:
    driver: bridge
YAML;
        } else {
            // Edge server configuration
            $originUrl = $this->getOriginUrl();
            
            $config = <<<YAML
version: '3.8'

services:
  # Edge Nginx - Cache and proxy from origin
  edge-nginx:
    image: nginx:alpine
    container_name: edge-nginx
    restart: always
    ports:
      - "8081:8081"
    volumes:
      - ./config/edge-nginx.conf:/etc/nginx/nginx.conf:ro
      - nginx-cache:/var/cache/nginx
      - ./logs/nginx:/var/log/nginx
    environment:
      - ORIGIN_URL={$originUrl}
      - APP_URL={$appUrl}
    networks:
      - streaming

  # Edge Caddy - SSL termination
  edge-caddy:
    image: caddy:alpine
    container_name: edge-caddy
    restart: always
    ports:
      - "8080:8080"
      - "443:443"
    environment:
      - DOMAIN=\${DOMAIN:-localhost}
      - NGINX_EDGE_PORT=8081
    volumes:
      - ./config/edge-caddy.Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
      - ./logs/caddy:/var/log/caddy
    depends_on:
      - edge-nginx
    networks:
      - streaming

volumes:
  nginx-cache:
  caddy-data:
  caddy-config:

networks:
  streaming:
    driver: bridge
YAML;
        }

        return $config;
    }

    /**
     * Generate SRS configuration
     */
    public function generateSrsConfig(Server $server): string
    {
        $serverUrl = config('app.url');
        $isOrigin = $server->type->value === 'origin';

        $config = <<<CONF
# SRS Configuration
# Server Type: {$server->type->value}

listen              1935;
max_connections     1000;
daemon              off;
srs_log_tank        console;

http_server {
    enabled         on;
    listen          8080;
    dir             ./objs/nginx/html;
}

http_api {
    enabled         on;
    listen          1985;
    raw_api {
        enabled     on;
        allow_query on;
        allow_update on;
    }
}

stats {
    network         0;
}

# RTMP server configuration
vhost __defaultVhost__ {
CONF;

        if ($isOrigin) {
            // Origin server - receives RTMP and transcodes
            $config .= <<<CONF

    # Security - validate stream key
    on_publish {
        url {$serverUrl}/api/srs/auth;
    }

    # Notify when stream starts/stops
    on_play {
        url {$serverUrl}/api/srs/play;
    }
    on_stop {
        url {$serverUrl}/api/srs/stop;
    }

    # HLS output
    hls {
        enabled         on;
        hls_path        ./objs/nginx/html;
        hls_fragment    2;
        hls_window      10;
        hls_cleanup     on;
        hls_dispose     10;
        hls_m3u8_file   [app]/[stream]/index.m3u8;
        hls_ts_file     [app]/[stream]/[seq].ts;
    }

    # Transcode to multiple qualities
    transcode {
        enabled     on;
        ffmpeg      /usr/local/bin/ffmpeg;

        # Full HD - 1080p
        engine fhd {
            enabled         on;
            vcodec          libx264;
            vbitrate        6000;
            vfps            30;
            vwidth          1920;
            vheight         1080;
            vprofile        high;
            vpreset         medium;
            acodec          aac;
            abitrate        192;
            asample_rate    48000;
            achannels       2;
            output          rtmp://127.0.0.1:[port]/live/[stream]_fhd;
        }

        # HD - 720p
        engine hd {
            enabled         on;
            vcodec          libx264;
            vbitrate        3000;
            vfps            30;
            vwidth          1280;
            vheight         720;
            vprofile        main;
            vpreset         fast;
            acodec          aac;
            abitrate        160;
            asample_rate    44100;
            achannels       2;
            output          rtmp://127.0.0.1:[port]/live/[stream]_hd;
        }

        # SD - 480p
        engine sd {
            enabled         on;
            vcodec          libx264;
            vbitrate        1500;
            vfps            30;
            vwidth          854;
            vheight         480;
            vprofile        main;
            vpreset         fast;
            acodec          aac;
            abitrate        128;
            asample_rate    44100;
            achannels       2;
            output          rtmp://127.0.0.1:[port]/live/[stream]_sd;
        }
    }
CONF;
        } else {
            // Edge server - just proxy HLS
            $config .= <<<CONF

    # Edge server - proxy mode
    mode            remote;

    # HLS proxy
    hls {
        enabled         on;
        hls_path        ./objs/nginx/html;
        hls_fragment    2;
        hls_window      10;
    }
CONF;
        }

        $config .= "\n}\n";

        return $config;
    }

    /**
     * Get current timestamp for script generation
     */
    private function getCurrentTimestamp(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    /**
     * Get origin server URL
     */
    private function getOriginUrl(): string
    {
        // Try to find an active origin server
        $originServer = \App\Models\Server::where('type', 'origin')
            ->where('status', 'active')
            ->first();
        
        if ($originServer) {
            $port = $originServer->port == 443 ? '' : ':' . $originServer->port;
            $protocol = $originServer->port == 443 ? 'https' : 'http';
            return "{$protocol}://{$originServer->hostname}{$port}";
        }
        
        // Fallback to config value
        return config('streaming.origin_url', 'http://origin.example.com:8080');
    }

    /**
     * Generate Nginx configuration for origin
     */
    public function generateNginxOriginConfig(Server $server): string
    {
        $appUrl = config('app.url');
        $sharedSecret = $server->shared_secret;
        
        return <<<NGINX
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

    access_log /var/log/nginx/access.log;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;

    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/vnd.apple.mpegurl video/mp2t;

    upstream laravel_auth {
        server {$appUrl};
        keepalive 32;
    }

    proxy_cache_path /var/cache/nginx/auth levels=1:2 keys_zone=auth_cache:10m
                     max_size=100m inactive=10s use_temp_path=off;

    server {
        listen 8083;
        listen [::]:8083;
        server_name _;

        location /health {
            access_log off;
            return 200 "healthy\n";
            add_header Content-Type text/plain;
        }

        location = /auth {
            internal;
            proxy_pass {$appUrl}/api/stream/auth;
            proxy_pass_request_body off;
            proxy_set_header Content-Length "";
            proxy_set_header X-Original-URI \$request_uri;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_set_header X-Stream-Key \$arg_streamkey;
            proxy_set_header X-Shared-Secret {$sharedSecret};
            
            proxy_cache auth_cache;
            proxy_cache_key "\$remote_addr:\$arg_streamkey";
            proxy_cache_valid 200 10s;
            proxy_cache_valid 401 403 1s;
        }

        location ~ ^/live/(.+\.m3u8)\$ {
            auth_request /auth;
            root /var/www/hls;
            try_files \$uri =404;
            
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS' always;
            add_header Content-Type "application/vnd.apple.mpegurl";
            add_header Cache-Control "no-cache, no-store, must-revalidate";
        }

        location ~ ^/live/(.+\.ts)\$ {
            auth_request /auth;
            root /var/www/hls;
            try_files \$uri =404;
            
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header 'Access-Control-Allow-Methods' 'GET, HEAD, OPTIONS' always;
            expires 1h;
            add_header Cache-Control "public, max-age=3600, immutable";
            add_header Content-Type "video/mp2t";
        }

        location / {
            return 404;
        }
    }
}
NGINX;
    }

    /**
     * Generate Nginx configuration for edge
     */
    public function generateNginxEdgeConfig(Server $server): string
    {
        $originUrl = $this->getOriginUrl();
        $appUrl = config('app.url');
        
        return <<<NGINX
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

    access_log /var/log/nginx/access.log;

    sendfile on;
    tcp_nopush on;
    tcp_nodelay on;
    keepalive_timeout 65;

    gzip on;
    gzip_vary on;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml text/javascript
               application/json application/javascript application/xml+rss
               application/vnd.apple.mpegurl video/mp2t;

    limit_req_zone \$binary_remote_addr zone=viewer_limit:10m rate=30r/s;
    limit_conn_zone \$binary_remote_addr zone=viewer_conn:10m;

    proxy_cache_path /var/cache/nginx/hls levels=1:2 keys_zone=hls_cache:10m
                     max_size=100m inactive=2s use_temp_path=off;
    
    proxy_cache_path /var/cache/nginx/segments levels=1:2 keys_zone=segment_cache:100m
                     max_size=2g inactive=1h use_temp_path=off;

    map \$args \$cache_key_args {
        ~^(.*)&?streamkey=[^&]*(.*)$ \$1\$2;
        default \$args;
    }

    upstream origin_server {
        server {$originUrl};
        keepalive 32;
    }

    server {
        listen 8081;
        listen [::]:8081;
        server_name _;

        limit_req zone=viewer_limit burst=50 nodelay;
        limit_conn viewer_conn 10;

        location /health {
            access_log off;
            return 200 "healthy\n";
            add_header Content-Type text/plain;
        }

        location /hls/ {
            proxy_pass {$appUrl};
            proxy_http_version 1.1;
            proxy_set_header Host \$host;
            proxy_set_header X-Real-IP \$remote_addr;
            proxy_cache off;
        }

        location ~ ^/live/(.+\.m3u8)\$ {
            proxy_pass {$originUrl}\$request_uri;
            proxy_http_version 1.1;
            proxy_set_header Host \$host;
            
            proxy_cache hls_cache;
            proxy_cache_key "\$scheme\$proxy_host\$uri\$cache_key_args";
            proxy_cache_valid 200 2s;
            proxy_cache_valid 404 1s;
            proxy_cache_lock on;
            
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header X-Cache-Status \$upstream_cache_status;
            add_header Content-Type "application/vnd.apple.mpegurl";
            add_header Cache-Control "no-cache, no-store, must-revalidate";
        }

        location ~ ^/live/(.+\.ts)\$ {
            proxy_pass {$originUrl}\$request_uri;
            proxy_http_version 1.1;
            proxy_set_header Host \$host;
            
            proxy_cache segment_cache;
            proxy_cache_key "\$scheme\$proxy_host\$uri";
            proxy_cache_valid 200 1h;
            proxy_cache_valid 404 10s;
            proxy_cache_lock on;
            
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header X-Cache-Status \$upstream_cache_status;
            expires 1h;
            add_header Cache-Control "public, max-age=3600, immutable";
            add_header Content-Type "video/mp2t";
        }

        location / {
            return 404;
        }
    }
}
NGINX;
    }

    /**
     * Generate Caddy configuration for origin
     */
    public function generateCaddyOriginConfig(Server $server): string
    {
        $domain = $server->hostname ?: 'localhost';
        
        return <<<CADDY
{$domain}:8080 {
    log {
        output file /var/log/caddy/access.log
        format json
    }

    reverse_proxy localhost:8083 {
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Original-URI {uri}
        
        transport http {
            keepalive 32
            keepalive_idle_conns 10
        }
    }
}
CADDY;
    }

    /**
     * Generate Caddy configuration for edge
     */
    public function generateCaddyEdgeConfig(Server $server): string
    {
        $domain = $server->hostname ?: 'localhost';
        
        return <<<CADDY
{$domain}:8080 {
    log {
        output file /var/log/caddy/access.log
        format json
    }

    reverse_proxy localhost:8081 {
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-For {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}
CADDY;
    }

    /**
     * Generate FFmpeg HLS Dockerfile
     */
    public function generateFFmpegDockerfile(): string
    {
        return <<<DOCKERFILE
FROM alpine:latest

RUN apk add --no-cache \\
    ffmpeg \\
    bash \\
    curl \\
    jq

COPY stream-manager.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/stream-manager.sh

CMD ["/usr/local/bin/stream-manager.sh"]
DOCKERFILE;
    }

    /**
     * Generate FFmpeg stream manager script
     */
    public function generateFFmpegStreamManager(): string
    {
        return file_get_contents(base_path('docker/ffmpeg-hls/stream-manager.sh'));
    }
}
