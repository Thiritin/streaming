#!/usr/bin/env bash
#
# Publish several sources at once and pull the result with many viewers, to find out
# whether the real fleet holds up before an event does it for you.
#
# This is the whole path, not a component: encoders -> SRS -> the x264 ladder -> HLS ->
# edges -> clients. The number it exists to answer is the origin's, because that is the
# one nobody can work out on paper - four live sources is twelve concurrent x264
# encodes, and whether a given box carries that is measurable, not guessable.
#
#   ./scripts/soak-test.sh 4 100 300
#     four publishers, a hundred viewers, five minutes
#
# Required:
#   ORIGIN_RTMP    rtmp://<origin-ip>:1935
#   SOURCES        slug:key,slug:key,...   (from /manage > Sources)
#   EDGE_HOSTS     edge-a.example.org,edge-b.example.org
#   VIEWER_KEY     a streamkey or token the edges accept
#
# WHERE TO RUN THIS
#
# Not on a laptop. A hundred viewers is roughly 400 Mbps of downstream and four
# publishers is 24 Mbps up, so a home connection measures your own line rather than the
# fleet. Provision a throwaway box in the same Hetzner location (a cpx42 is about EUR
# 0.11/hr and takes minutes), run it there, and destroy it afterwards. In-datacenter
# traffic also keeps the test honest: the edges' public uplink is a known 1 Gbps and
# does not need discovering, whereas the origin's encode headroom does.
#
# WHY IT IS BUILT THE WAY IT IS
#
# Publishers encode one short clip each, once, and then loop it with `-c copy`. A
# publisher that re-encodes competes with the thing being measured, and at four
# publishers the load generator becomes the bottleneck instead of the origin. Same
# trick as docker/dev/publish.sh.
#
# Viewers fetch segments with curl rather than decoding them. scripts/load-test.sh runs
# a full ffmpeg decode per viewer, which is fine for fifty and hopeless for several
# hundred - the generator saturates long before the edge does. A real player's load on
# a server is HTTP requests and bytes, and that is what this reproduces.
#
set -uo pipefail

PUBLISHERS="${1:-4}"
VIEWERS="${2:-100}"
DURATION="${3:-300}"

: "${ORIGIN_RTMP:?set ORIGIN_RTMP, e.g. rtmp://167.233.244.142:1935}"
: "${SOURCES:?set SOURCES, e.g. main:key1,docks-nightclub:key2}"
: "${EDGE_HOSTS:?set EDGE_HOSTS, e.g. edge-16-x.stream.example.org,edge-17-y.stream.example.org}"
: "${VIEWER_KEY:?set VIEWER_KEY to a streamkey the edges accept}"

SIZE="${SIZE:-1920x1080}"
FPS="${FPS:-30}"
BITRATE="${BITRATE:-6000k}"
CLIP_SECONDS="${CLIP_SECONDS:-20}"

WORK="$(mktemp -d)"
CLIPS="${CLIPS:-$WORK/clips}"
mkdir -p "$CLIPS" "$WORK/logs"
trap 'echo; echo "stopping..."; pkill -P $$ 2>/dev/null; rm -rf "$WORK"' EXIT INT TERM

IFS=',' read -ra SOURCE_LIST <<< "$SOURCES"
IFS=',' read -ra EDGE_LIST <<< "$EDGE_HOSTS"

if [ "${#SOURCE_LIST[@]}" -lt "$PUBLISHERS" ]; then
  echo "Only ${#SOURCE_LIST[@]} sources given but $PUBLISHERS publishers asked for." >&2
  exit 1
fi

command -v ffmpeg >/dev/null || { echo "ffmpeg is required." >&2; exit 1; }

echo "Soak test"
echo "  publishers : $PUBLISHERS x ${SIZE}@${FPS} ${BITRATE}"
echo "  viewers    : $VIEWERS across ${#EDGE_LIST[@]} edge(s)"
echo "  duration   : ${DURATION}s"
echo

# ---------------------------------------------------------------- publishers

# One reusable clip per publisher. Encoded once; the publish loop never runs an
# encoder, so the generator's CPU does not enter the measurement.
build_clip() {
  local idx=$1 clip="$CLIPS/pattern-${idx}-${SIZE}-${FPS}.mp4"
  [ -f "$clip" ] && { echo "$clip"; return; }

  ffmpeg -hide_banner -loglevel error -y \
    -f lavfi -i "testsrc2=size=${SIZE}:rate=${FPS}" \
    -f lavfi -i "sine=frequency=$((300 + idx * 110)):sample_rate=48000" \
    -c:v libx264 -preset veryfast -b:v "$BITRATE" \
    -g $((FPS * 2)) -keyint_min $((FPS * 2)) -sc_threshold 0 -pix_fmt yuv420p \
    -c:a aac -b:a 160k -ac 2 \
    -t "$CLIP_SECONDS" "$clip" >/dev/null 2>&1

  echo "$clip"
}

start_publisher() {
  local idx=$1 slug=$2 key=$3 clip
  clip="$(build_clip "$idx")"

  # -re paces at wall clock; -stream_loop repeats the clip; genpts rewrites the
  # timestamps the loop resets, which SRS rejects otherwise.
  ffmpeg -hide_banner -loglevel warning -nostdin \
    -re -stream_loop -1 -fflags +genpts -i "$clip" \
    -c copy -t "$DURATION" \
    -f flv "${ORIGIN_RTMP}/ingress/${slug}?secret=${key}" \
    > "$WORK/logs/pub-${slug}.log" 2>&1 &

  echo "  publishing $slug"
}

echo "Building clips (once) and starting publishers..."
for ((i = 0; i < PUBLISHERS; i++)); do
  entry="${SOURCE_LIST[$i]}"
  start_publisher "$i" "${entry%%:*}" "${entry#*:}"
done

# The ladder needs a moment before any playlist exists to pull.
echo "  waiting 25s for the ladder to produce segments..."
sleep 25

# ---------------------------------------------------------------- viewers

# One viewer: resolve the master, pick a rendition, then keep fetching whatever the
# media playlist offers, at roughly the pace a player would. Records one line per
# failure so the summary can distinguish "slow" from "broken".
viewer() {
  local id=$1 host=$2 slug=$3 rendition=$4
  local base="https://${host}/live"
  local log="$WORK/logs/viewer-${id}.err"
  local seen="" deadline=$(( $(date +%s) + DURATION ))

  while [ "$(date +%s)" -lt "$deadline" ]; do
    local playlist
    playlist=$(curl -s -m 10 --fail "${base}/${slug}_${rendition}.m3u8?streamkey=${VIEWER_KEY}" 2>/dev/null) || {
      echo "playlist-fail" >> "$log"; sleep 2; continue
    }

    # Newest few segments only, which is where a live player sits.
    local segs
    segs=$(echo "$playlist" | grep -v '^#' | grep '\.ts$' | tail -3)

    for seg in $segs; do
      case "$seen" in *"|$seg|"*) continue ;; esac
      seen="${seen}|${seg}|"
      code=$(curl -s -m 15 -o /dev/null -w '%{http_code}' \
             "${base}/${seg}?streamkey=${VIEWER_KEY}" 2>/dev/null)
      [ "$code" = "200" ] || echo "segment-$code" >> "$log"
    done

    # Trim the memory of seen segments so it cannot grow without bound.
    seen="${seen: -4000}"
    sleep 2
  done
}

echo
echo "Starting $VIEWERS viewers..."
RENDITIONS=(sd hd fhd)
for ((v = 0; v < VIEWERS; v++)); do
  host="${EDGE_LIST[$((v % ${#EDGE_LIST[@]}))]}"
  entry="${SOURCE_LIST[$((v % PUBLISHERS))]}"
  # Weight towards hd, which is what most viewers actually land on.
  case $((v % 5)) in 0) r=sd ;; 4) r=fhd ;; *) r=hd ;; esac
  viewer "$v" "$host" "${entry%%:*}" "$r" &
done

echo "  running for ${DURATION}s..."
wait

# ---------------------------------------------------------------- summary

echo
echo "=== result ==="

pub_fail=0
for log in "$WORK"/logs/pub-*.log; do
  [ -f "$log" ] || continue
  if grep -qiE "error|failed|broken pipe|connection refused" "$log"; then
    pub_fail=$((pub_fail + 1))
    echo "  publisher problem in $(basename "$log"):"
    grep -iE "error|failed|broken pipe" "$log" | head -2 | sed 's/^/    /'
  fi
done
[ "$pub_fail" -eq 0 ] && echo "  publishers : all $PUBLISHERS ran clean"

fails=$(cat "$WORK"/logs/viewer-*.err 2>/dev/null | wc -l | tr -d ' ')
affected=$(ls "$WORK"/logs/viewer-*.err 2>/dev/null | wc -l | tr -d ' ')

echo "  viewers    : $VIEWERS started, $affected saw at least one failure, $fails failures total"
if [ "$fails" -gt 0 ]; then
  echo "  breakdown  :"
  cat "$WORK"/logs/viewer-*.err 2>/dev/null | sort | uniq -c | sort -rn | head -5 | sed 's/^/    /'
fi

echo
echo "Check on the origin while a run is in flight, which is where the answer is:"
echo "  docker stats --no-stream origin-ffmpeg-hls"
echo "  docker logs --tail 20 origin-ffmpeg-hls   # 'stalled' or restarts mean it is not keeping up"
echo "  docker logs --tail 5 archive-uploader     # a climbing 'pending' means uploads are losing"
