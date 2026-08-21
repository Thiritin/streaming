#!/usr/bin/env bash
#
# Turn an edited master file into archive segments the site can cut a recording from.
#
# The site never plays a standalone VOD file: a recording is a range of segments in the
# S3 archive, selected by wall clock. So an offline edit has to be encoded into the same
# ladder the live transcoder produces (docker/ffmpeg-hls/stream-manager.sh) and laid out
# under the same prefix the archive uploader writes (docker/archive-uploader).
#
#   archive/<source>/<YYYYMMDD>/<HH>/index.m3u8      hour index, %v in place of rendition
#   archive/<source>/<YYYYMMDD>/<HH>/<source>_<rendition>_<session>_<n>.ts
#
# Usage:
#   scripts/vod-to-archive.sh -i cut.mov -s vod -t 2026-08-15T18:00:00Z [-o build/vod] [-u]
#
#   -i  input master file
#   -s  source slug the segments are archived under (a Source with that slug must exist)
#   -t  archive start, UTC, ISO 8601. The wall clock the recording is later cut at.
#   -o  output directory (default: build/vod-<source>-<session>)
#   -r  renditions to encode (default: sd,hd,fhd)
#   -p  x264 preset for every rung (default: veryfast for sd/hd, slow for fhd)
#   -u  upload to the archive bucket when done ($DVR_AWS_BUCKET / $ARCHIVE_BUCKET)
#
set -euo pipefail

INPUT=""
SOURCE=""
START=""
OUTDIR=""
RENDITIONS="sd,hd,fhd"
PRESET=""
UPLOAD=0
SEGMENT_SECONDS=2

while getopts "i:s:t:o:r:p:uh" opt; do
    case "$opt" in
        i) INPUT="$OPTARG" ;;
        s) SOURCE="$OPTARG" ;;
        t) START="$OPTARG" ;;
        o) OUTDIR="$OPTARG" ;;
        r) RENDITIONS="$OPTARG" ;;
        p) PRESET="$OPTARG" ;;
        u) UPLOAD=1 ;;
        h) sed -n '2,25p' "$0"; exit 0 ;;
        *) exit 1 ;;
    esac
done

[[ -f "$INPUT" ]] || { echo "need -i <input file>" >&2; exit 1; }
[[ -n "$SOURCE" ]] || { echo "need -s <source slug>" >&2; exit 1; }
[[ -n "$START" ]] || { echo "need -t <UTC start, e.g. 2026-08-15T18:00:00Z>" >&2; exit 1; }
[[ "$SOURCE" =~ ^[a-z0-9-]+$ ]] || { echo "source slug must be [a-z0-9-]" >&2; exit 1; }

command -v ffmpeg >/dev/null || { echo "ffmpeg not found" >&2; exit 1; }
command -v ffprobe >/dev/null || { echo "ffprobe not found" >&2; exit 1; }
command -v python3 >/dev/null || { echo "python3 not found" >&2; exit 1; }

# Segment names carry a session id so two imports into the same source can never collide
# on a filename, exactly as a publisher reconnect gets a new prefix live.
SESSION=$(date +%s)
OUTDIR="${OUTDIR:-build/vod-${SOURCE}-${SESSION}}"
ENCDIR="$OUTDIR/enc"
TREE="$OUTDIR/tree"

mkdir -p "$ENCDIR" "$TREE"

SRC_HEIGHT=$(ffprobe -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 "$INPUT")
SRC_FPS=$(ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate -of csv=p=0 "$INPUT")
SRC_FPS_NUM=${SRC_FPS%%/*}
SRC_FPS_DEN=${SRC_FPS##*/}
echo "Input: $INPUT (${SRC_HEIGHT}p, ${SRC_FPS} fps)"
echo "Source slug: $SOURCE"
echo "Archive start: $START"
echo "Session: $SESSION"
echo "Work dir: $OUTDIR"

# The ladder, matching ArchivePlaylistService::RENDITIONS and the live transcoder. A rung
# above the master's own height is dropped rather than upscaled: the site advertises
# BANDWIDTH and RESOLUTION per rung from its own constants, and an upscaled 1080p rung is
# a bigger file that carries no more picture.
filters=()
maps=()
var_stream_map=()
idx=0
split_labels=""

IFS=',' read -ra WANTED <<< "$RENDITIONS"
for rendition in "${WANTED[@]}"; do
    case "$rendition" in
        sd)  height=480;  width=854;  vb=1500k; maxrate=2000k; bufsize=3000k; profile=baseline; preset=veryfast; ab=128k ;;
        hd)  height=720;  width=1280; vb=3500k; maxrate=4000k; bufsize=8000k; profile=main;     preset=veryfast; ab=160k ;;
        fhd) height=1080; width=1920; vb=6000k; maxrate=6500k; bufsize=13000k; profile=main;    preset=slow;     ab=192k ;;
        *) echo "unknown rendition [$rendition]" >&2; exit 1 ;;
    esac

    if [[ "$SRC_HEIGHT" -lt "$height" ]]; then
        echo "Skipping $rendition: master is only ${SRC_HEIGHT}p"
        continue
    fi

    [[ -n "$PRESET" ]] && preset="$PRESET"

    # High frame rate masters get the bottom rung halved. 50p inside 1500 kbps spends the
    # budget on temporal resolution nobody watching a 480p rung is short of, and the
    # segment boundaries are unaffected because force_key_frames is on time, not frames.
    rate_filter=""
    if [[ "$rendition" == "sd" ]] && [[ $((SRC_FPS_NUM / SRC_FPS_DEN)) -gt 30 ]]; then
        rate_filter=",fps=${SRC_FPS_NUM}/$((SRC_FPS_DEN * 2))"
    fi

    split_labels+="[v${idx}]"
    # format=yuv420p is not cosmetic: a 10-bit master (HEVC Main 10 out of Resolve)
    # otherwise carries its pixel format into libx264, which then encodes High 10 - a
    # profile no browser decodes, and one that contradicts the -profile:v below, so the
    # encode dies rather than producing something unplayable. Either way, force 8-bit.
    filters+=("[v${idx}]scale=w=${width}:h=${height}${rate_filter},format=yuv420p[v${idx}out]")
    maps+=(
        -map "[v${idx}out]" -c:v:${idx} libx264 -b:v:${idx} "$vb" -maxrate:v:${idx} "$maxrate"
        -bufsize:v:${idx} "$bufsize" -preset:v:${idx} "$preset" -profile:v:${idx} "$profile"
        -sc_threshold:v:${idx} 0
        -force_key_frames:v:${idx} "expr:gte(t,n_forced*${SEGMENT_SECONDS})"
    )
    var_stream_map+=("v:${idx},a:${idx},name:${rendition}")
    idx=$((idx + 1))
done

[[ "$idx" -gt 0 ]] || { echo "no renditions to encode" >&2; exit 1; }

audio_maps=()
for ((i = 0; i < idx; i++)); do
    case "${var_stream_map[$i]}" in
        *name:sd)  ab=128k ;;
        *name:hd)  ab=160k ;;
        *name:fhd) ab=192k ;;
    esac
    audio_maps+=(-map 0:a:0 -c:a:${i} aac -b:a:${i} "$ab" -ac 2 -ar 48000)
done

filter_complex="[0:v]split=${idx}${split_labels}; $(IFS='; '; echo "${filters[*]}")"

echo
echo "Encoding ${idx} rendition(s)..."
ffmpeg -hide_banner -y -i "$INPUT" \
    -filter_complex "$filter_complex" \
    "${maps[@]}" \
    "${audio_maps[@]}" \
    -f hls \
    -hls_time "$SEGMENT_SECONDS" \
    -hls_playlist_type vod \
    -hls_list_size 0 \
    -hls_flags independent_segments \
    -hls_segment_type mpegts \
    -start_number 0 \
    -hls_segment_filename "$ENCDIR/${SOURCE}_%v_${SESSION}_%06d.ts" \
    -master_pl_name "${SOURCE}_master.m3u8" \
    -var_stream_map "$(IFS=' '; echo "${var_stream_map[*]}")" \
    "$ENCDIR/${SOURCE}_%v.m3u8"

# Lay the segments out the way the uploader would have, and write the hour indexes the
# app reads. Segment durations come from the encoder's own playlist rather than being
# assumed to be exactly 2s: the last one is short, and a keyframe can land late.
python3 - "$ENCDIR" "$TREE" "$SOURCE" "$SESSION" "$START" "$(IFS=,; echo "${WANTED[*]}")" <<'PY'
import os, shutil, sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

encdir, tree, source, session, start, renditions = sys.argv[1:7]
encdir, tree = Path(encdir), Path(tree)
renditions = [r for r in renditions.split(',') if (encdir / f'{source}_{r}.m3u8').exists()]

canonical = 'hd' if 'hd' in renditions else renditions[0]

def durations(rendition):
    out = []
    for line in (encdir / f'{source}_{rendition}.m3u8').read_text().splitlines():
        if line.startswith('#EXTINF:'):
            out.append(float(line[8:].rstrip(',')))
    return out

reference = durations(canonical)
for rendition in renditions:
    count = len(durations(rendition))
    if count != len(reference):
        raise SystemExit(
            f'{rendition} has {count} segments, {canonical} has {len(reference)}. '
            'The renditions are not cut at the same instants, so one index cannot '
            'describe them all.'
        )

pdt = datetime.fromisoformat(start.replace('Z', '+00:00')).astimezone(timezone.utc)
HEADER = '#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:2\n#EXT-X-INDEPENDENT-SEGMENTS\n'

def stamp(value):
    return value.strftime('%Y-%m-%dT%H:%M:%S.') + f'{value.microsecond // 1000:03d}+0000'

first = pdt
for n, duration in enumerate(reference):
    hour = pdt.strftime('%Y%m%d/%H')
    target = tree / 'archive' / source / hour
    target.mkdir(parents=True, exist_ok=True)

    index = target / 'index.m3u8'
    if not index.exists():
        index.write_text(HEADER)

    with index.open('a') as fh:
        if n == 0:
            fh.write('#EXT-X-DISCONTINUITY\n')
            fh.write(f'#EXT-X-ARCHIVE-SESSION:{session}\n')
        fh.write(f'#EXT-X-ARCHIVE-SEQ:{n}\n')
        fh.write(f'#EXT-X-ARCHIVE-OBSERVED:{stamp(pdt)}\n')
        fh.write(f'#EXTINF:{duration:.6f},\n')
        fh.write(f'#EXT-X-PROGRAM-DATE-TIME:{stamp(pdt)}\n')
        fh.write(f'{source}_%v_{session}_{n:06d}.ts\n')

    for rendition in renditions:
        name = f'{source}_{rendition}_{session}_{n:06d}.ts'
        destination = target / name
        if destination.exists():
            destination.unlink()
        try:
            # Same filesystem, and a ladder of a long master is tens of gigabytes.
            os.link(encdir / name, destination)
        except OSError:
            shutil.copy2(encdir / name, destination)

    pdt += timedelta(seconds=duration)

total = sum(reference)
(tree / 'CUT.txt').write_text(
    f'starts_at (UTC): {stamp(first)}\n'
    f'ends_at   (UTC): {stamp(pdt)}\n'
    f'duration       : {total:.3f}s\n'
    f'segments       : {len(reference)}\n'
    f'renditions     : {",".join(renditions)}\n'
)
print()
print(f'{len(reference)} segments, {total:.1f}s, renditions: {", ".join(renditions)}')
print(f'starts_at (UTC): {stamp(first)}')
print(f'ends_at   (UTC): {stamp(pdt)}')
PY

BUCKET="${ARCHIVE_BUCKET:-${DVR_AWS_BUCKET:-}}"
ENDPOINT_ARG=()
[[ -n "${DVR_AWS_ENDPOINT:-}" ]] && ENDPOINT_ARG=(--endpoint-url "$DVR_AWS_ENDPOINT")

echo
if [[ "$UPLOAD" -eq 1 ]]; then
    [[ -n "$BUCKET" ]] || { echo "set ARCHIVE_BUCKET or DVR_AWS_BUCKET to upload" >&2; exit 1; }
    echo "Uploading to s3://$BUCKET/archive/$SOURCE ..."
    aws s3 sync "$TREE/archive/$SOURCE" "s3://$BUCKET/archive/$SOURCE" "${ENDPOINT_ARG[@]}"
    echo "Uploaded."
else
    echo "Not uploaded. Push the tree with:"
    echo "  aws s3 sync $TREE/archive/$SOURCE s3://${BUCKET:-<archive-bucket>}/archive/$SOURCE"
fi

echo
echo "Then create the cut (php artisan tinker):"
cat "$TREE/CUT.txt"
