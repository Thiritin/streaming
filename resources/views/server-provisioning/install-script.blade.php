#!/bin/bash

# Streaming {{ ucfirst($server->type->value) }} Server Installation Script
# Generated: {{ now()->format('Y-m-d H:i:s') }}
# Server ID: {{ $server->id }}
# Hostname: {{ $server->hostname }}

set -e

echo "================================================"
echo "Streaming Server Installation"
echo "Server Type: {{ $server->type->value }}"
echo "Generated: {{ now()->format('Y-m-d H:i:s') }}"
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
mkdir -p /opt/streaming
cd /opt/streaming

# Create environment file
cat > .env <<EOF
SERVER_ID={{ $server->id }}
SERVER_TYPE={{ $server->type->value }}
SHARED_SECRET={{ $sharedSecret }}
APP_URL={{ $serverUrl }}

# DVR S3 Storage Configuration
DVR_AWS_ACCESS_KEY_ID={{ config('filesystems.disks.dvr.key') }}
DVR_AWS_SECRET_ACCESS_KEY={{ config('filesystems.disks.dvr.secret') }}
DVR_AWS_DEFAULT_REGION={{ config('filesystems.disks.dvr.region') }}
DVR_AWS_BUCKET={{ config('filesystems.disks.dvr.bucket') }}
DVR_AWS_ENDPOINT={{ config('filesystems.disks.dvr.endpoint') }}
EOF

# Download configuration files from server
echo "Downloading configuration files..."

# Base URL for config downloads
CONFIG_URL="{{ $serverUrl }}/api/server/config"

# Download Docker Compose configuration
echo "Downloading docker-compose.yml..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o docker-compose.yml \
     "${CONFIG_URL}/docker-compose" || {
    echo "Failed to download docker-compose.yml"
    exit 1
}

@if($server->type === \App\Enum\ServerTypeEnum::ORIGIN)
# Download Origin server configurations
echo "Downloading SRS configuration..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o srs.conf \
     "${CONFIG_URL}/srs-origin" || {
    echo "Failed to download srs.conf"
    exit 1
}

echo "Downloading Nginx configuration..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o nginx.conf \
     "${CONFIG_URL}/nginx-origin" || {
    echo "Failed to download nginx.conf"
    exit 1
}

echo "Downloading Caddy configuration..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o Caddyfile \
     "${CONFIG_URL}/caddy-origin" || {
    echo "Failed to download Caddyfile"
    exit 1
}
@else
# Download Edge server configurations
echo "Downloading Nginx configuration..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o nginx.conf \
     "${CONFIG_URL}/nginx-edge" || {
    echo "Failed to download nginx.conf"
    exit 1
}

echo "Downloading Caddy configuration..."
curl -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -o Caddyfile \
     "${CONFIG_URL}/caddy-edge" || {
    echo "Failed to download Caddyfile"
    exit 1
}

# The edge nginx image is built here because it needs the njs module, which
# verifies playback tokens on this host instead of calling back into Laravel.
#
# -f and "Accept: application/json" matter: a bad shared secret otherwise
# renders as a 302 to the login page, and the HTML would be saved as the config.
echo "Downloading edge nginx Dockerfile..."
curl -fsS -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -H "Accept: application/json" \
     -o Dockerfile.edge-nginx \
     "${CONFIG_URL}/dockerfile-edge" || {
    echo "Failed to download Dockerfile.edge-nginx"
    exit 1
}

echo "Downloading playback token verifier..."
curl -fsS -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -H "Accept: application/json" \
     -o hls-auth.js \
     "${CONFIG_URL}/hls-auth-js" || {
    echo "Failed to download hls-auth.js"
    exit 1
}
@endif

echo "All configuration files downloaded successfully!"

# Start services
# --build so a changed edge nginx Dockerfile or njs module is actually picked up.
echo "Starting Docker services..."
docker compose up -d --build

# Wait for services to be ready
echo "Waiting for services to start..."
WAITED=0
MAX_WAIT=60
while [ $WAITED -lt $MAX_WAIT ]; do
    if [ "{{ $server->type->value }}" = "origin" ]; then
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
HOSTNAME="{{ $server->hostname }}"

echo "================================================"
echo "Server Information:"
echo "  Public IP: $PUBLIC_IP"
echo "  Hostname: $HOSTNAME"
echo "  Server Type: {{ $server->type->value }}"
echo "  Server ID: {{ $server->id }}"
echo "================================================"

# Register server with main app (optional - may fail if network not ready)
echo "Attempting to register server with main application..."
curl -L -X POST "{{ $serverUrl }}/api/server/register" \
     -H "X-Shared-Secret: {{ $sharedSecret }}" \
     -H "Content-Type: application/json" \
     -d "{
         \"server_id\": \"{{ $server->id }}\",
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

# Heartbeat.
#
# The cron line below has existed for a long time; the script it runs never did, so
# no server has ever checked in and `servers.last_heartbeat` only moved because the
# app was stamping its own rows. Writing it is the whole fix.
#
# Liveness only. Viewer counts are derived from `source_users` in the app, which knows
# about signed-out viewers too, so there is nothing useful for the box to report there.
cat > /opt/streaming/heartbeat.sh <<'HEARTBEAT'
#!/bin/bash
# Reports that this server is alive. Installed by the provisioning script.
set -u

APP_URL="{{ $serverUrl }}"
SERVER_ID="{{ $server->id }}"
SHARED_SECRET="{{ $server->shared_secret }}"

# Whether the local stack is actually serving, not merely whether the box is powered
# on - a heartbeat that says "alive" while nginx is down is worse than none.
if curl -fsS -m 5 -o /dev/null "http://localhost/health" 2>/dev/null; then
    STATUS="healthy"
else
    STATUS="unhealthy"
fi

curl -fsS -m 10 -X POST "${APP_URL}/api/server/${SERVER_ID}/heartbeat" \
    -H "X-Shared-Secret: ${SHARED_SECRET}" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -d "{\"health\":{\"local\":\"${STATUS}\"}}" \
    >/dev/null 2>&1
HEARTBEAT

chmod 700 /opt/streaming/heartbeat.sh

# Replace rather than append, so reinstalling does not stack duplicate entries.
(crontab -l 2>/dev/null | grep -v '/opt/streaming/heartbeat.sh'; \
 echo "* * * * * /opt/streaming/heartbeat.sh") | crontab -

# Once immediately, so a fresh server does not look stale for its first minute.
/opt/streaming/heartbeat.sh || true

exit 0