#!/usr/bin/env bash
#
# Fake live channels for local design work.
#
# Runs one ffmpeg per channel, each generating a distinct animated pattern and
# writing a rolling HLS playlist into public/dev-streams/<slug>/. With
# DEV_STREAMS=true in .env, Source::getHlsUrl() points the app at those files, so
# the browse hero and the tile hover previews play real video without an SRS
# origin, an edge server or OBS.
#
#   ./scripts/dev-streams.sh          start every channel (Ctrl+C stops all)
#   ./scripts/dev-streams.sh prime    start one channel by slug
#
set -euo pipefail

cd "$(dirname "$0")/.."

OUT_ROOT="public/dev-streams"
SEGMENT_SECONDS=2
SEGMENT_WINDOW=6           # segments kept in the playlist (~12s of DVR)
SIZE="1280x720"
FPS=30

# slug|channel label|ffmpeg video source
CHANNELS=(
  "prime|Prime|testsrc2=size=${SIZE}:rate=${FPS}"
  "dance-stage|Dance Stage|life=size=${SIZE}:rate=${FPS}:mold=10:ratio=0.1:death_color=#39ff14:life_color=#00b3a4"
  "panel-room|Panel Room|smptehdbars=size=${SIZE}:rate=${FPS}"
  "art-track|Art Track|mandelbrot=size=${SIZE}:rate=${FPS}:maxiter=180"
)

pids=()

cleanup() {
  echo ""
  echo "Stopping ${#pids[@]} stream(s)..."
  for pid in "${pids[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  echo "Stopped."
}
trap cleanup EXIT INT TERM

start_channel() {
  local slug="$1" label="$2" source="$3"
  local dir="${OUT_ROOT}/${slug}"

  mkdir -p "$dir"
  rm -f "$dir"/*.ts "$dir"/*.m3u8

  # -re paces generation at wall-clock speed so the playlist behaves like a live
  # edge rather than writing an hour of segments in ten seconds.
  ffmpeg -hide_banner -loglevel error \
    -re -f lavfi -i "$source" \
    -f lavfi -i "sine=frequency=220:sample_rate=48000" \
    -vf "drawtext=text='${label}':fontsize=48:fontcolor=white:borderw=3:bordercolor=black@0.6:x=40:y=40,drawtext=text='%{localtime\\:%H\\\\\\:%M\\\\\\:%S}':fontsize=32:fontcolor=white:borderw=2:bordercolor=black@0.6:x=40:y=110" \
    -c:v libx264 -preset veryfast -tune zerolatency -pix_fmt yuv420p \
    -g $((FPS * SEGMENT_SECONDS)) -keyint_min $((FPS * SEGMENT_SECONDS)) -sc_threshold 0 \
    -b:v 2500k -maxrate 2500k -bufsize 5000k \
    -c:a aac -b:a 96k -ar 48000 -ac 2 \
    -f hls \
    -hls_time "$SEGMENT_SECONDS" \
    -hls_list_size "$SEGMENT_WINDOW" \
    -hls_flags delete_segments+independent_segments+omit_endlist \
    -hls_segment_filename "${dir}/seg_%05d.ts" \
    "${dir}/index.m3u8" &

  pids+=($!)
  echo "  ${label}  ->  /dev-streams/${slug}/index.m3u8"
}

wanted="${1:-all}"
started=0

echo "Starting dev streams (${SIZE} @ ${FPS}fps, ${SEGMENT_SECONDS}s segments)"

for entry in "${CHANNELS[@]}"; do
  IFS='|' read -r slug label source <<< "$entry"

  if [[ "$wanted" != "all" && "$wanted" != "$slug" ]]; then
    continue
  fi

  start_channel "$slug" "$label" "$source"
  started=$((started + 1))
done

if [[ "$started" -eq 0 ]]; then
  echo "No channel matched '${wanted}'. Known slugs:"
  for entry in "${CHANNELS[@]}"; do
    echo "  ${entry%%|*}"
  done
  exit 1
fi

echo ""
echo "Set DEV_STREAMS=true in .env, then seed channels with:"
echo "  php artisan db:seed --class=DevStreamChannelsSeeder"
echo ""
echo "Ctrl+C to stop."

wait
