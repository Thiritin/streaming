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
# Liveness plus a system sample. Viewer counts stay derived from `source_users` in the
# app, which knows about signed-out viewers too, so there is nothing useful for the box
# to report there.
cat > /opt/streaming/heartbeat.sh <<'HEARTBEAT'
#!/bin/bash
# Reports that this server is alive, and what it is doing. Installed by the
# provisioning script; run once a minute from cron.
set -u

APP_URL="{{ $serverUrl }}"
SERVER_ID="{{ $server->id }}"
SHARED_SECRET="{{ $server->shared_secret }}"
STATE="/opt/streaming/heartbeat.state"

# Whether the local stack is actually serving, not merely whether the box is powered
# on - a heartbeat that says "alive" while nginx is down is worse than none.
if curl -fsS -m 5 -o /dev/null "http://localhost/health" 2>/dev/null; then
    STATUS="healthy"
else
    STATUS="unhealthy"
fi

NOW=$(date +%s)

# CPU and network arrive as counters that only ever climb, so what gets reported is
# the delta against the previous run divided by the seconds between them. That state
# lives in one file next to this script; if it is missing or looks wrong - first run
# after an install, a reboot, a counter wrap - the rate fields are simply left out
# rather than reported as an impossible spike.
read -r _ CPU_USER CPU_NICE CPU_SYS CPU_IDLE CPU_IOWAIT CPU_IRQ CPU_SOFTIRQ CPU_STEAL _ < /proc/stat
CPU_TOTAL=$((CPU_USER + CPU_NICE + CPU_SYS + CPU_IDLE + CPU_IOWAIT + CPU_IRQ + CPU_SOFTIRQ + CPU_STEAL))
CPU_IDLE_ALL=$((CPU_IDLE + CPU_IOWAIT))

# Physical interfaces only: loopback is not traffic, and docker/veth/bridge devices
# would double-count the same bytes that already crossed eth0.
read -r NET_RX NET_TX <<< "$(sed 's/:/ /' /proc/net/dev | awk '
    NR > 2 && $1 !~ /^(lo|docker|veth|br-|virbr|tun|tap)/ { rx += $2; tx += $10 }
    END { printf "%d %d\n", rx + 0, tx + 0 }
')"

CPU_PERCENT=""
RX_RATE=""
TX_RATE=""

if [ -r "$STATE" ]; then
    read -r P_TS P_TOTAL P_IDLE P_RX P_TX < "$STATE" 2>/dev/null || true
    ELAPSED=$((NOW - ${P_TS:-0}))

    if [ "$ELAPSED" -gt 0 ] && [ "$ELAPSED" -lt 3600 ]; then
        D_TOTAL=$((CPU_TOTAL - ${P_TOTAL:-0}))
        D_IDLE=$((CPU_IDLE_ALL - ${P_IDLE:-0}))

        if [ "$D_TOTAL" -gt 0 ] && [ "$D_IDLE" -ge 0 ]; then
            CPU_PERCENT=$(awk -v t="$D_TOTAL" -v i="$D_IDLE" 'BEGIN { printf "%.2f", (t - i) * 100 / t }')
        fi

        D_RX=$((NET_RX - ${P_RX:-0}))
        D_TX=$((NET_TX - ${P_TX:-0}))

        if [ "$D_RX" -ge 0 ]; then RX_RATE=$((D_RX / ELAPSED)); fi
        if [ "$D_TX" -ge 0 ]; then TX_RATE=$((D_TX / ELAPSED)); fi
    fi
fi

printf '%s %s %s %s %s\n' "$NOW" "$CPU_TOTAL" "$CPU_IDLE_ALL" "$NET_RX" "$NET_TX" > "$STATE"

# MemAvailable rather than MemFree: page cache is free memory in every sense that
# matters here, and MemFree alone reads as "out of memory" on a healthy box.
MEM_TOTAL_KB=$(awk '/^MemTotal:/ { print $2 }' /proc/meminfo)
MEM_AVAIL_KB=$(awk '/^MemAvailable:/ { print $2 }' /proc/meminfo)
MEM_TOTAL=$((MEM_TOTAL_KB * 1024))
MEM_USED=$(((MEM_TOTAL_KB - MEM_AVAIL_KB) * 1024))

read -r DISK_TOTAL DISK_USED <<< "$(df -PB1 / | awk 'NR == 2 { print $2, $3 }')"

LOAD_1=$(awk '{ print $1 }' /proc/loadavg)
UPTIME=$(awk '{ printf "%d", $1 }' /proc/uptime)
CORES=$(nproc 2>/dev/null || echo 1)

# Rates are omitted rather than sent as null when there is no previous sample, which
# keeps the payload valid JSON either way.
METRICS="\"cpu_cores\":${CORES},\"load_1\":${LOAD_1},\"memory_used_bytes\":${MEM_USED},\"memory_total_bytes\":${MEM_TOTAL},\"disk_used_bytes\":${DISK_USED},\"disk_total_bytes\":${DISK_TOTAL},\"uptime_seconds\":${UPTIME}"
if [ -n "$CPU_PERCENT" ]; then METRICS="${METRICS},\"cpu_percent\":${CPU_PERCENT}"; fi
if [ -n "$RX_RATE" ]; then METRICS="${METRICS},\"net_rx_bytes_per_sec\":${RX_RATE}"; fi
if [ -n "$TX_RATE" ]; then METRICS="${METRICS},\"net_tx_bytes_per_sec\":${TX_RATE}"; fi

curl -fsS -m 10 -X POST "${APP_URL}/api/server/${SERVER_ID}/heartbeat" \
    -H "X-Shared-Secret: ${SHARED_SECRET}" \
    -H "Accept: application/json" \
    -H "Content-Type: application/json" \
    -d "{\"health\":{\"local\":\"${STATUS}\"},\"metrics\":{${METRICS}}}" \
    >/dev/null 2>&1
HEARTBEAT

chmod 700 /opt/streaming/heartbeat.sh

# A drop-in rather than root's crontab. The old form piped `crontab -l | grep -v` into
# `crontab -`, and on a fresh box with no crontab that grep matches nothing and exits 1,
# which under `set -e` killed the subshell before the echo - so `crontab -` was handed an
# empty file and every server installed an empty crontab. A file here is also idempotent
# on reinstall without having to read the old entries back first.
cat > /etc/cron.d/streaming-heartbeat <<'CRON'
* * * * * root /opt/streaming/heartbeat.sh
CRON

chmod 644 /etc/cron.d/streaming-heartbeat

# Drop the entry the old install script tried to leave in root's crontab, so a box that
# was provisioned before this change does not end up running the heartbeat twice.
if crontab -l 2>/dev/null | grep -q '/opt/streaming/heartbeat.sh'; then
    crontab -l 2>/dev/null | grep -v '/opt/streaming/heartbeat.sh' | crontab -
fi

# Once immediately, so a fresh server does not look stale for its first minute.
/opt/streaming/heartbeat.sh || true

exit 0