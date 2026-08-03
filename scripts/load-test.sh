#!/usr/bin/env bash
#
# Point N simulated viewers at the local edge and report what breaks first.
#
# Each viewer is an ffmpeg client pulling the real HLS ladder through the edge,
# so this exercises the same path a browser does: playlist refreshes, segment
# fetches, nginx caching and rate limits included.
#
#   ./scripts/load-test.sh                    50 viewers, 60s, first channel
#   ./scripts/load-test.sh 200 120 prime      200 viewers, 120s, channel "prime"
#
# Note the edge config rate-limits per client IP (30r/s, see edge nginx). Every
# viewer here shares one IP, so past a few hundred you are measuring the rate
# limiter, not the server. Treat 429s in the summary as that ceiling, not a bug.
#
set -euo pipefail

cd "$(dirname "$0")/.."

VIEWERS="${1:-50}"
DURATION="${2:-60}"
CHANNEL="${3:-}"
EDGE="${EDGE_URL:-http://localhost:8085}"

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "ffmpeg is required." >&2
  exit 1
fi

if [[ -z "$CHANNEL" ]]; then
  CHANNEL="$(php artisan dev:stream-keys --limit=1 2>/dev/null | tail -n 1 | cut -d: -f1)"
fi

if [[ -z "$CHANNEL" ]]; then
  echo "No channel given and none seeded. Pass a slug: $0 50 60 prime" >&2
  exit 1
fi

PLAYLIST="${EDGE}/live/${CHANNEL}.m3u8"
RESULTS="$(mktemp -d)"
trap 'rm -rf "$RESULTS"' EXIT

echo "Load test"
echo "  playlist : $PLAYLIST"
echo "  viewers  : $VIEWERS"
echo "  duration : ${DURATION}s"
echo ""

status="$(curl -s -o /dev/null -w '%{http_code}' "$PLAYLIST" || true)"
if [[ "$status" != "200" ]]; then
  echo "Edge returned HTTP $status for the playlist. Is the stack up and publishing?" >&2
  echo "  ./scripts/dev-stack.sh status" >&2
  exit 1
fi

started="$(date +%s)"

for ((i = 1; i <= VIEWERS; i++)); do
  (
    # -t bounds the run; failures land in the per-viewer log for the summary.
    if ffmpeg -hide_banner -loglevel error -nostdin \
        -i "$PLAYLIST" -t "$DURATION" -f null - 2>"${RESULTS}/viewer_${i}.log"; then
      echo ok > "${RESULTS}/viewer_${i}.status"
    else
      echo fail > "${RESULTS}/viewer_${i}.status"
    fi
  ) &
done

wait

elapsed=$(( $(date +%s) - started ))
ok=$(grep -l ok "${RESULTS}"/*.status 2>/dev/null | wc -l | tr -d ' ')
fail=$(grep -l fail "${RESULTS}"/*.status 2>/dev/null | wc -l | tr -d ' ')

echo ""
echo "Finished in ${elapsed}s"
echo "  completed : $ok"
echo "  failed    : $fail"

if [[ "$fail" != "0" ]]; then
  echo ""
  echo "Most common errors:"
  cat "${RESULTS}"/viewer_*.log 2>/dev/null | sed 's/[0-9]\{2,\}/N/g' | sort | uniq -c | sort -rn | head -5
fi

echo ""
echo "Edge and origin request rates:"
docker compose -f docker-compose.dev.yml logs --no-log-prefix --tail=100000 edge-nginx 2>/dev/null \
  | awk '{print $9}' | sort | uniq -c | sort -rn | head -5 \
  || echo "  (edge logs unavailable)"
