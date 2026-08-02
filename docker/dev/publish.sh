#!/bin/bash
#
# Fake broadcasters. One ffmpeg per channel, each pushing a distinct animated
# pattern into the SRS ingress app with that channel's stream key, exactly the
# way OBS would.
#
# CHANNELS is a space-separated list of slug:streamkey pairs, produced by
# `php artisan dev:stream-keys` and passed in by scripts/dev-stack.sh.
#
set -uo pipefail

RTMP_URL="${RTMP_URL:-rtmp://origin-srs:1935/ingress}"
SIZE="${SIZE:-1280x720}"
FPS="${FPS:-30}"
CHANNELS="${CHANNELS:-}"

if [[ -z "$CHANNELS" ]]; then
  echo "No CHANNELS set. Run ./scripts/dev-stack.sh publish to start broadcasters."
  # Idle instead of crash-looping: the stack is still useful without publishers.
  tail -f /dev/null
fi

# Each channel gets its own look so you can tell the streams apart on the grid.
patterns=(
  "testsrc2=size=${SIZE}:rate=${FPS}"
  "life=size=${SIZE}:rate=${FPS}:mold=10:ratio=0.1:death_color=#39ff14:life_color=#00b3a4"
  "smptehdbars=size=${SIZE}:rate=${FPS}"
  "mandelbrot=size=${SIZE}:rate=${FPS}:maxiter=180"
  "rgbtestsrc=size=${SIZE}:rate=${FPS}"
)

pids=()

cleanup() {
  for pid in "${pids[@]:-}"; do
    kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

index=0
for entry in $CHANNELS; do
  slug="${entry%%:*}"
  key="${entry#*:}"
  pattern="${patterns[$((index % ${#patterns[@]}))]}"
  index=$((index + 1))

  echo "Publishing ${slug} -> ${RTMP_URL}/${slug}"

  # -re keeps generation at wall-clock speed; without it ffmpeg races ahead and
  # SRS drops the connection.
  ffmpeg -hide_banner -loglevel warning \
    -re -f lavfi -i "$pattern" \
    -f lavfi -i "sine=frequency=$((200 + index * 60)):sample_rate=48000" \
    -vf "drawtext=text='${slug}':fontsize=48:fontcolor=white:borderw=3:bordercolor=black@0.6:x=40:y=40,drawtext=text='%{localtime\\:%H\\\\\\:%M\\\\\\:%S}':fontsize=32:fontcolor=white:borderw=2:bordercolor=black@0.6:x=40:y=110" \
    -c:v libx264 -preset veryfast -tune zerolatency -pix_fmt yuv420p \
    -g $((FPS * 2)) -keyint_min $((FPS * 2)) -sc_threshold 0 \
    -b:v 4000k -maxrate 4000k -bufsize 8000k \
    -c:a aac -b:a 128k -ar 48000 -ac 2 \
    -f flv "${RTMP_URL}/${slug}?secret=${key}" &

  pids+=($!)
done

echo "Started ${#pids[@]} publisher(s)."
wait
