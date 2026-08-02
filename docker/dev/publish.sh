#!/bin/bash
#
# Fake broadcasters. One ffmpeg per channel, each pushing a distinct animated
# pattern into the SRS ingress app with that channel's stream key, exactly the
# way OBS would.
#
# CHANNELS is a space-separated list of slug:streamkey pairs, produced by
# `php artisan dev:stream-keys` and passed in by scripts/dev-stack.sh.
#
# MODE picks how the video is produced:
#
#   loop  (default)  Encode a short clip per pattern once, then push it on an
#                    endless loop with -c copy. After the first start there is
#                    no encoding at all, so ten channels cost about as much CPU
#                    as none. The wall clock in the overlay is frozen at capture
#                    time, which is the one thing you give up.
#   live             Encode continuously, one x264 per channel. Use it when you
#                    need a moving clock or genuinely fresh frames.
#
set -uo pipefail

RTMP_URL="${RTMP_URL:-rtmp://origin-srs:1935/ingress}"
SIZE="${SIZE:-1280x720}"
FPS="${FPS:-30}"
CHANNELS="${CHANNELS:-}"
MODE="${MODE:-loop}"
CLIP_SECONDS="${CLIP_SECONDS:-20}"
CLIP_DIR="${CLIP_DIR:-/clips}"

# GOP length in seconds. Must match hls_time in the transcoder, otherwise a
# copy-mode ladder cannot cut segments on keyframes.
GOP_SECONDS="${GOP_SECONDS:-2}"

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

overlay() {
  local label="$1"

  echo "drawtext=text='${label}':fontsize=48:fontcolor=white:borderw=3:bordercolor=black@0.6:x=40:y=40,drawtext=text='%{localtime\\:%H\\\\\\:%M\\\\\\:%S}':fontsize=32:fontcolor=white:borderw=2:bordercolor=black@0.6:x=40:y=110"
}

# Encode one loopable clip per pattern. Cached in CLIP_DIR, so this only costs
# something the first time the stack comes up with a given size and pattern set.
build_clip() {
  local index="$1" pattern="$2" label="$3"
  local clip="${CLIP_DIR}/${label}-${SIZE}-${FPS}.mp4"

  if [[ -s "$clip" ]]; then
    echo "$clip"
    return 0
  fi

  echo "Building loop clip ${index} (${CLIP_SECONDS}s, ${SIZE}@${FPS})..." >&2

  # No -re here: this renders as fast as the CPU allows and then never runs
  # again. Closed 2s GOPs keep the clip usable by the copy-mode ladder.
  ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i "$pattern" \
    -f lavfi -i "sine=frequency=$((200 + index * 60)):sample_rate=48000" \
    -t "$CLIP_SECONDS" \
    -vf "$(overlay "$label")" \
    -c:v libx264 -preset veryfast -tune zerolatency -pix_fmt yuv420p \
    -g $((FPS * GOP_SECONDS)) -keyint_min $((FPS * GOP_SECONDS)) -sc_threshold 0 \
    -b:v 4000k -maxrate 4000k -bufsize 8000k \
    -c:a aac -b:a 128k -ar 48000 -ac 2 \
    -movflags +faststart \
    "$clip" >&2 || return 1

  echo "$clip"
}

publish_loop() {
  local clip="$1" slug="$2" key="$3"

  # -re paces the loop at wall-clock speed; -c copy means no encoder runs at
  # all. genpts rewrites the timestamps that -stream_loop resets, which SRS
  # would otherwise reject as non-monotonic at every wrap.
  ffmpeg -hide_banner -loglevel warning \
    -fflags +genpts -re -stream_loop -1 -i "$clip" \
    -c copy \
    -f flv "${RTMP_URL}/${slug}?secret=${key}" &
}

publish_live() {
  local pattern="$1" slug="$2" key="$3" index="$4"

  # -re keeps generation at wall-clock speed; without it ffmpeg races ahead and
  # SRS drops the connection.
  ffmpeg -hide_banner -loglevel warning \
    -re -f lavfi -i "$pattern" \
    -f lavfi -i "sine=frequency=$((200 + index * 60)):sample_rate=48000" \
    -vf "$(overlay "$slug")" \
    -c:v libx264 -preset veryfast -tune zerolatency -pix_fmt yuv420p \
    -g $((FPS * GOP_SECONDS)) -keyint_min $((FPS * GOP_SECONDS)) -sc_threshold 0 \
    -b:v 4000k -maxrate 4000k -bufsize 8000k \
    -c:a aac -b:a 128k -ar 48000 -ac 2 \
    -f flv "${RTMP_URL}/${slug}?secret=${key}" &
}

mkdir -p "$CLIP_DIR"

index=0
for entry in $CHANNELS; do
  slug="${entry%%:*}"
  key="${entry#*:}"
  pattern_index=$((index % ${#patterns[@]}))
  pattern="${patterns[$pattern_index]}"
  index=$((index + 1))

  echo "Publishing ${slug} -> ${RTMP_URL}/${slug} (mode: ${MODE})"

  if [[ "$MODE" == "loop" ]]; then
    # One clip per channel, so the slug stays burned into the picture even when
    # more channels than patterns are running and two of them share a look.
    if clip="$(build_clip "$pattern_index" "$pattern" "$slug")"; then
      publish_loop "$clip" "$slug" "$key"
    else
      echo "Clip build failed for ${slug}, falling back to live encoding." >&2
      publish_live "$pattern" "$slug" "$key" "$index"
    fi
  else
    publish_live "$pattern" "$slug" "$key" "$index"
  fi

  pids+=($!)
done

echo "Started ${#pids[@]} publisher(s)."
wait
