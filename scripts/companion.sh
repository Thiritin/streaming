#!/usr/bin/env bash
#
# Bitfocus Companion in Docker, with the Stream Control module mounted from the repo.
#
#   ./scripts/companion.sh up      start Companion on http://localhost:8000
#   ./scripts/companion.sh reload  reinstall module dependencies and restart
#   ./scripts/companion.sh logs    follow the container log
#   ./scripts/companion.sh down    stop it (keeps the Companion config volume)
#   ./scripts/companion.sh reset   stop and delete the config volume
#
set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.companion.yml"
MODULE_DIR="companion/module-stream-control"

require_docker() {
  if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running. Start Docker Desktop and try again." >&2
    exit 1
  fi
}

# Installed on the host so the container skips its own install step: the module is a
# mount, not an image layer, and reinstalling on every start costs a minute of boot.
install_module_deps() {
  if [[ ! -d "$MODULE_DIR/node_modules" ]]; then
    echo "Installing module dependencies..."
    (cd "$MODULE_DIR" && npm install --omit=dev --no-audit --no-fund)
  fi
}

case "${1:-up}" in
  up)
    require_docker
    install_module_deps
    $COMPOSE up -d
    echo
    echo "Companion:  http://localhost:8000"
    echo "Add a connection: Connections, then search for Stream Control."
    echo "Base URL and token: /manage > Sources > the source > Control surface."
    ;;
  reload)
    require_docker
    rm -rf "$MODULE_DIR/node_modules"
    install_module_deps
    $COMPOSE restart
    ;;
  logs)
    $COMPOSE logs -f
    ;;
  down)
    $COMPOSE down
    ;;
  reset)
    $COMPOSE down -v
    ;;
  *)
    echo "Usage: $0 {up|reload|logs|down|reset}" >&2
    exit 1
    ;;
esac
