#!/usr/bin/env bash
#
# Build the Stream Control module into a .tgz Companion can import.
#
#   ./scripts/companion-package.sh
#
# Writes companion/module-stream-control/stream-control-<version>.tgz. Hand that file
# to whoever runs Companion: Modules, then Import module package. No Node, no npm and
# no developer module path needed on their machine, because the build bundles the
# dependencies into a single file.
#
set -euo pipefail

cd "$(dirname "$0")/.."

MODULE_DIR="companion/module-stream-control"

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is not installed. Install Node 22 and try again." >&2
  exit 1
fi

cd "$MODULE_DIR"

if [[ ! -d node_modules/@companion-module/tools ]]; then
  echo "Installing build dependencies..."
  npm install --no-audit --no-fund
fi

rm -rf pkg
npm run --silent package

TARBALL="$(ls -t ./*.tgz | head -n 1)"

echo
echo "Built $MODULE_DIR/${TARBALL#./}"
echo "Import it in Companion: Modules, then Import module package."
