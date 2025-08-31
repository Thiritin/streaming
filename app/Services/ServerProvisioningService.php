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
curl -H "X-Shared-Secret: {$sharedSecret}" \\
     -o config/nginx.conf \\
     "{$serverUrl}/api/server/config/nginx?server_id={$server->id}"

curl -H "X-Shared-Secret: {$sharedSecret}" \\
     -o config/edge.conf \\
     "{$serverUrl}/api/server/config/srs?server_id={$server->id}"

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

# Configure NGINX
echo "Configuring NGINX..."
cp config/nginx.conf /etc/nginx/sites-available/ef-streaming
ln -sf /etc/nginx/sites-available/ef-streaming /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

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
        $config = <<<YAML
version: '3.8'

services:
  srs:
    image: ossrs/srs:5
    container_name: srs-server
    restart: always
    ports:
      - "1935:1935"    # RTMP
      - "1985:1985"    # HTTP API
      - "8080:8080"    # HTTP FLV/HLS
    volumes:
      - ./config/edge.conf:/usr/local/srs/conf/edge.conf
      - ./logs:/usr/local/srs/objs/logs
    environment:
      - CANDIDATE=\${EXTERNAL_IP:-127.0.0.1}
    command: ["./objs/srs", "-c", "/usr/local/srs/conf/edge.conf"]
    networks:
      - streaming

networks:
  streaming:
    driver: bridge
YAML;

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
}
