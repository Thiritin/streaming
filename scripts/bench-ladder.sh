#!/usr/bin/env bash
#
# How much CPU does one stream's ABR ladder cost?
#
# Run this on the box you are sizing - a Hetzner ccx33 answers differently from
# a laptop, and the answer decides stream.server.origin.max_streams. It encodes
# a fixed 1080p30 clip through the production ladder and reports cores per
# stream at realtime, which is what you divide the machine's cores by.
#
#   ./scripts/bench-ladder.sh              both variants, 30s clip
#   ./scripts/bench-ladder.sh 60           longer clip, steadier number
#   STREAMS=5 ./scripts/bench-ladder.sh 60 also run 5 concurrently, realtime
#                                          paced, and say whether it keeps up
#
# The concurrent run is the one that decides sizing. Cores-per-stream measured
# one stream at a time is optimistic: it gets the whole machine, and on a CPU
# with unequal cores it gets the fast ones. Five at once is what a convention
# evening actually looks like.
#
# Needs ffmpeg with libx264. On a bare Hetzner box:
#   docker run --rm -v /tmp/bench:/tmp/bench -w /tmp/bench \
#     linuxserver/ffmpeg:latest ...    # or just apt install ffmpeg
#
set -euo pipefail

DURATION="${1:-30}"
WORK="${BENCH_DIR:-/tmp/ladder-bench}"
SRC="$WORK/src.mp4"

mkdir -p "$WORK"

cores() {
  nproc 2>/dev/null || sysctl -n hw.ncpu
}

# CPU seconds consumed, divided by clip length, is cores-at-realtime.
#
# ffmpeg's own -benchmark reports this, so there is nothing to install: no GNU
# time, no coreutils. It prints one line to stderr at the end:
#   bench: utime=46.990s stime=2.380s rtime=6.960s
measure() {
  local label="$1"
  shift

  # 2>&1 >/dev/null keeps stderr (where -benchmark writes) and drops stdout.
  # The || true matters under `set -e`: a run that printed no bench line should
  # report FAILED below, not kill the script.
  local report
  report=$( { "$@" 2>&1 >/dev/null || true; } | grep -E '^bench: utime' | tail -1 || true )

  awk -v label="$label" -v duration="$DURATION" '
    function secs(field,   value) {
      split(field, value, "=")
      sub("s", "", value[2])
      return value[2] + 0
    }
    { total = secs($2) + secs($3) }
    END {
      if (NR == 0 || total == 0) {
        printf "  %-34s FAILED (rerun without -loglevel error to see why)\n", label
      } else {
        printf "  %-34s %6.2f CPU-s  ->  %.2f cores/stream\n", label, total, total / duration
      }
    }
  ' <<<"$report"
}

if [[ ! -s "$SRC" ]]; then
  echo "Rendering a ${DURATION}s 1080p30 source clip..."
  ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i "testsrc2=size=1920x1080:rate=30" \
    -f lavfi -i "sine=frequency=220:sample_rate=48000" \
    -t "$DURATION" \
    -c:v libx264 -preset veryfast -b:v 6000k -pix_fmt yuv420p \
    -g 60 -keyint_min 60 -sc_threshold 0 \
    -c:a aac -b:a 128k \
    "$SRC"
fi

HLS_ARGS=(
  -f hls -hls_time 2 -hls_list_size 60
  -hls_flags independent_segments+delete_segments
)

echo
echo "Host: $(cores) cores, clip ${DURATION}s @ 1080p30"
echo

# The ladder as deployed: three encodes from one decoded source.
measure "current ladder (3 encodes)" \
  ffmpeg -hide_banner -nostats -benchmark -y -i "$SRC" \
    -filter_complex "[0:v]split=3[v1][v2][v3]; [v1]scale=w=854:h=480[v1out]; [v2]scale=w=1280:h=720[v2out]; [v3]scale=w=1920:h=1080[v3out]" \
    -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 2000k \
      -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0 \
      -force_key_frames "expr:gte(t,n_forced*2)" \
    -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 4000k \
      -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0 \
      -force_key_frames "expr:gte(t,n_forced*2)" \
    -map "[v3out]" -c:v:2 libx264 -b:v:2 6000k -maxrate:v:2 6500k -bufsize:v:2 6500k \
      -preset:v:2 faster -profile:v:2 main -g 60 -keyint_min 60 -sc_threshold 0 \
      -force_key_frames "expr:gte(t,n_forced*2)" \
    -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 \
    -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 \
    -map 0:a -c:a:2 aac -b:a:2 192k -ac 2 \
    "${HLS_ARGS[@]}" \
    -hls_segment_filename "$WORK/a_%v_%05d.ts" -master_pl_name "a_master.m3u8" \
    -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" "$WORK/a_%v.m3u8"

# The top rendition is the broadcaster's own 1080p, so re-encoding it spends the
# most expensive encode in the ladder to produce a slightly worse copy of the
# input. Passing it through only works when broadcasters are held to 1080p at a
# bitrate you are willing to serve.
measure "fhd passed through (2 encodes)" \
  ffmpeg -hide_banner -nostats -benchmark -y -i "$SRC" \
    -filter_complex "[0:v]split=2[v1][v2]; [v1]scale=w=854:h=480[v1out]; [v2]scale=w=1280:h=720[v2out]" \
    -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 2000k \
      -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0 \
      -force_key_frames "expr:gte(t,n_forced*2)" \
    -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 4000k \
      -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0 \
      -force_key_frames "expr:gte(t,n_forced*2)" \
    -map 0:v -c:v:2 copy \
    -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 \
    -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 \
    -map 0:a -c:a:2 copy \
    "${HLS_ARGS[@]}" \
    -hls_segment_filename "$WORK/b_%v_%05d.ts" -master_pl_name "b_master.m3u8" \
    -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" "$WORK/b_%v.m3u8"

cat <<EOF

Divide usable cores by the figure above to get concurrent streams. Leave 1-2
cores for SRS, nginx, Caddy and the DVR uploader, and take another 20-40% off:
testsrc2 is easier to encode than a lit stage with a moving crowd.

EOF

# Concurrency is the decisive test. -re paces each input at wall-clock speed, so
# a run that finishes in the clip's own duration kept up with live; anything
# longer is how far behind the box falls with that many broadcasters on it.
if [[ -n "${STREAMS:-}" ]]; then
  echo "Now $STREAMS concurrent, realtime paced:"
  echo

  concurrent() {
    local variant="$1" start end
    local pids=()

    start=$(date +%s)

    for i in $(seq "$STREAMS"); do
      if [[ "$variant" == full ]]; then
        ffmpeg -hide_banner -loglevel error -nostats -re -i "$SRC" \
          -filter_complex "[0:v]split=3[v1][v2][v3]; [v1]scale=w=854:h=480[v1out]; [v2]scale=w=1280:h=720[v2out]; [v3]scale=w=1920:h=1080[v3out]" \
          -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 2000k -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames "expr:gte(t,n_forced*2)" \
          -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 4000k -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames "expr:gte(t,n_forced*2)" \
          -map "[v3out]" -c:v:2 libx264 -b:v:2 6000k -maxrate:v:2 6500k -bufsize:v:2 6500k -preset:v:2 faster -profile:v:2 main -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames "expr:gte(t,n_forced*2)" \
          -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 -map 0:a -c:a:2 aac -b:a:2 192k -ac 2 \
          "${HLS_ARGS[@]}" -hls_segment_filename "$WORK/c${i}_%v_%05d.ts" -master_pl_name "c${i}_master.m3u8" \
          -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" "$WORK/c${i}_%v.m3u8" >/dev/null 2>&1 &
      else
        ffmpeg -hide_banner -loglevel error -nostats -re -i "$SRC" \
          -filter_complex "[0:v]split=2[v1][v2]; [v1]scale=w=854:h=480[v1out]; [v2]scale=w=1280:h=720[v2out]" \
          -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 2000k -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames "expr:gte(t,n_forced*2)" \
          -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 4000k -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0 -force_key_frames "expr:gte(t,n_forced*2)" \
          -map 0:v -c:v:2 copy \
          -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 -map 0:a -c:a:2 copy \
          "${HLS_ARGS[@]}" -hls_segment_filename "$WORK/c${i}_%v_%05d.ts" -master_pl_name "c${i}_master.m3u8" \
          -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" "$WORK/c${i}_%v.m3u8" >/dev/null 2>&1 &
      fi
      pids+=($!)
    done

    wait "${pids[@]}"
    end=$(date +%s)

    awk -v v="$variant" -v n="$STREAMS" -v wall="$((end - start))" -v d="$DURATION" 'BEGIN {
      printf "  %-30s %d streams   wall %3ds (clip %ds)   %s\n",
        v, n, wall, d, (wall <= d * 1.05 ? "KEEPS UP" : sprintf("FALLS BEHIND (%.2fx realtime)", d / wall))
    }'

    rm -f "$WORK"/c*
  }

  concurrent full
  concurrent passthrough

  cat <<EOF

FALLS BEHIND means segments are produced slower than realtime: the playlist
stops advancing and every viewer on that origin stalls, not just one stream.
Size for KEEPS UP with headroom, not for the edge of it.

EOF
fi
