#!/usr/bin/env bash
#
# Local mirror of the production streaming path: SRS ingress, ABR transcoding,
# origin, edge, and S3-compatible storage for DVR and thumbnails.
#
#   ./scripts/dev-stack.sh up        start everything and begin publishing
#   ./scripts/dev-stack.sh publish   restart the fake broadcasters with fresh keys
#   ./scripts/dev-stack.sh status    what is live according to SRS
#   ./scripts/dev-stack.sh logs      follow all container logs
#   ./scripts/dev-stack.sh down      stop the stack (keeps volumes)
#   ./scripts/dev-stack.sh reset     stop and delete volumes (HLS, DVR, S3)
#
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.dev.yml"

require_docker() {
  if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running. Start Docker Desktop and try again." >&2
    exit 1
  fi
}

stream_keys() {
  php artisan dev:stream-keys 2>/dev/null | tail -n 1
}

start_publishers() {
  local keys
  keys="$(stream_keys)"

  if [[ -z "$keys" ]]; then
    echo "No channels seeded yet. Run:" >&2
    echo "  php artisan db:seed --class=DevStreamChannelsSeeder" >&2
    exit 1
  fi

  # Print slugs only; the stream keys stay out of the terminal scrollback.
  echo "Publishing channels: $(echo "$keys" | tr ' ' '\n' | cut -d: -f1 | paste -sd' ' -)"
  DEV_PUBLISH_CHANNELS="$keys" $COMPOSE up -d --force-recreate publisher
}

case "${1:-up}" in
  up)
    require_docker
    $COMPOSE up -d origin-srs hls-transcoder origin-nginx origin-caddy edge-nginx edge-caddy s3 s3-init dvr-uploader
    start_publishers

    cat <<'EOF'

Stack is up.

  RTMP ingress   rtmp://localhost:1935/ingress/<slug>?secret=<stream_key>
  SRS API        http://localhost:1985/api/v1/streams
  Origin         http://localhost:8070/live/<slug>.m3u8
  Edge           http://localhost:8085/live/<slug>.m3u8
  S3 (versitygw) http://localhost:7070

The app keeps serving HLS through its own /hls routes, which proxy to the edge
server row on localhost:8085. Keep DEV_STREAMS unset or false in .env so the
app uses this stack rather than the standalone file loops.

EOF
    ;;

  publish)
    require_docker
    start_publishers
    ;;

  status)
    require_docker
    echo "SRS streams:"
    curl -s http://localhost:1985/api/v1/streams | php -r 'echo json_encode(json_decode(stream_get_contents(STDIN)), JSON_PRETTY_PRINT), PHP_EOL;' 2>/dev/null \
      || echo "  (SRS API not reachable)"
    echo ""
    $COMPOSE ps
    ;;

  logs)
    require_docker
    $COMPOSE logs -f --tail=80 "${2:-}"
    ;;

  down)
    require_docker
    $COMPOSE down
    ;;

  reset)
    require_docker
    $COMPOSE down -v
    echo "Stack stopped and volumes removed."
    ;;

  *)
    echo "Usage: $0 {up|publish|status|logs [service]|down|reset}" >&2
    exit 1
    ;;
esac
