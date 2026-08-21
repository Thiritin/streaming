#!/bin/bash

# Dynamic FFmpeg HLS Manager for SRS
# Monitors SRS API and starts/stops FFmpeg processes for each stream

SRS_API_URL="${SRS_API_URL:-http://localhost:1985/api/v1}"
SRS_RTMP_URL="${SRS_RTMP_URL:-rtmp://localhost:1935}"
OUTPUT_BASE_DIR="${OUTPUT_BASE_DIR:-/var/www/html/hls/live}"
CHECK_INTERVAL="${CHECK_INTERVAL:-5}"

# The live rewind window. hls_time is 2s, so 900 segments is 30 minutes of seekable
# playlist. It is also the uploader's catch-up window: the indexer builds hour indexes
# by parsing this playlist, so a segment that falls out of it is never indexed even
# though the file is still on disk.
#
# hls_delete_threshold keeps a further margin of segments on disk after they leave the
# playlist. It is a backstop, not the normal path: the S3 uploader owns deletion and
# only unlinks a segment it has confirmed in the bucket (see docker/archive-uploader).
# The margin is what stops the disk filling when the uploader cannot keep up, so it is
# sized as outage tolerance - 1500 segments is 50 minutes - rather than as the couple
# of minutes an upload actually takes. See docs/dvr-archive-plan.md.
DVR_WINDOW_SEGMENTS="${DVR_WINDOW_SEGMENTS:-900}"
HLS_DELETE_THRESHOLD="${HLS_DELETE_THRESHOLD:-1500}"

# A publisher reconnect starts a new FFmpeg session under a new timestamp prefix, and
# FFmpeg only ever deletes segments it wrote itself, so the previous session's files
# are left behind. This reaper clears them. Retention is derived from the window plus
# a margin, so it can never reach a segment the current playlist still references.
# Set to 0 to disable, which is what the S3 uploader wants once it owns deletion.
ORPHAN_RETENTION_MINUTES="${ORPHAN_RETENTION_MINUTES:-$(( (DVR_WINDOW_SEGMENTS + HLS_DELETE_THRESHOLD) * 2 / 60 + 5 ))}"
REAP_INTERVAL_SECONDS="${REAP_INTERVAL_SECONDS:-300}"

# FFmpeg can sit alive while producing nothing, which the PID check in check_streams
# cannot see. Playlists are rewritten on every completed segment, so their mtime is
# the liveness signal. Zero disables the watchdog.
SEGMENT_STALL_SECONDS="${SEGMENT_STALL_SECONDS:-15}"
STARTUP_GRACE_SECONDS="${STARTUP_GRACE_SECONDS:-30}"

# transcode: the real ABR ladder (480p/720p/1080p), three x264 encodes per stream.
# copy:      remux only. The publisher's bitstream is written to all three
#            renditions unchanged, so the master playlist, the variant names and
#            the segment layout are identical to production while the CPU cost
#            is roughly zero. Quality switching in the player is a no-op picture
#            wise; everything around it still behaves the same.
ABR_MODE="${ABR_MODE:-transcode}"

# Mirror of the publisher's own bitstream, remuxed rather than re-encoded, so the
# archive keeps whatever quality was actually sent instead of topping out at the
# fhd rung. A 17 Mbps contribution feed stays 17 Mbps here while viewers still get
# the 6 Mbps ladder.
#
# It is written OUTSIDE $OUTPUT_BASE_DIR on purpose. Both nginx configs serve
# `root /var/www/hls` under `location ~ ^/live/`, and the edge proxies any
# /live/*.ts, so anything dropped next to the ladder is reachable by a viewer who
# guesses the filename. A sibling directory is not matched by either location
# block, which is what keeps "archive it, never serve it live" true by
# construction rather than by a deny rule someone has to remember.
#
# Segment boundaries here land on the *publisher's* keyframes, not on the ladder's
# forced 2s marks, so this rendition is indexed separately by the archive uploader
# (index-source.m3u8) rather than sharing the ladder's index entries.
ARCHIVE_SOURCE="${ARCHIVE_SOURCE:-1}"
SOURCE_OUTPUT_DIR="${SOURCE_OUTPUT_DIR:-$(dirname "$OUTPUT_BASE_DIR")/source}"

# Associative array to track running FFmpeg processes
declare -A FFMPEG_PIDS
declare -A STREAM_APPS
declare -A FFMPEG_STARTED

# Rewrite the master playlist so the three copy-mode renditions look distinct.
#
# In copy mode every rendition carries the same bitstream, so FFmpeg writes the
# same BANDWIDTH and RESOLUTION for all three. Players treat indistinguishable
# levels as a single quality: hls.js collapses them and the quality menu
# disappears, which makes the picker impossible to work on locally.
#
# Substituting the production ladder's numbers restores a three-rung menu that
# behaves like the real thing. The picture genuinely does not change when you
# switch - only the advertised metadata differs. CODECS is left as FFmpeg wrote
# it, since that part is accurate.
#
# Only ever called for ABR_MODE=copy.
rewrite_copy_mode_master() {
    local master=$1

    [[ -f "$master" ]] || return 1

    awk '
        /^#EXT-X-STREAM-INF:/ { stream_inf = $0; next }
        stream_inf != "" && /^[^#]/ {
            bandwidth = "4500000"; resolution = "1280x720"
            if ($0 ~ /_sd\.m3u8/)  { bandwidth = "1500000"; resolution = "854x480"   }
            if ($0 ~ /_hd\.m3u8/)  { bandwidth = "3500000"; resolution = "1280x720"  }
            if ($0 ~ /_fhd\.m3u8/) { bandwidth = "6000000"; resolution = "1920x1080" }

            sub(/BANDWIDTH=[0-9]+/, "BANDWIDTH=" bandwidth, stream_inf)
            sub(/RESOLUTION=[0-9]+x[0-9]+/, "RESOLUTION=" resolution, stream_inf)

            print stream_inf
            stream_inf = ""
        }
        { if (stream_inf == "") print }
    ' "$master" > "${master}.tmp" || return 1

    # Rename so a player never reads a half-written master.
    mv "${master}.tmp" "$master"
}

# FFmpeg writes the master once at startup, so wait for it to appear and then
# patch it. Runs in the background so it never delays stream startup.
watch_copy_mode_master() {
    local master=$1
    local pid=$2
    local waited=0

    while [[ ! -f "$master" ]] && kill -0 "$pid" 2>/dev/null && [ $waited -lt 30 ]; do
        sleep 1
        waited=$((waited + 1))
    done

    kill -0 "$pid" 2>/dev/null || return 0

    if rewrite_copy_mode_master "$master"; then
        echo "[$(date)] Advertised a distinct ladder in $(basename "$master") (copy mode)"
    fi

    # Cheap guard in case FFmpeg rewrites the master mid-session, e.g. on a
    # discontinuity. Costs one grep per interval for as long as the stream runs.
    while kill -0 "$pid" 2>/dev/null; do
        sleep "$CHECK_INTERVAL"
        if [[ -f "$master" ]] && ! grep -q 'BANDWIDTH=1500000' "$master" 2>/dev/null; then
            rewrite_copy_mode_master "$master"
        fi
    done
}

echo "Starting Dynamic FFmpeg HLS Manager"
echo "SRS API: $SRS_API_URL"
echo "Output directory: $OUTPUT_BASE_DIR"
echo "Check interval: ${CHECK_INTERVAL}s"
echo "DVR window: ${DVR_WINDOW_SEGMENTS} segments (+${HLS_DELETE_THRESHOLD} retained)"
echo "Orphan retention: ${ORPHAN_RETENTION_MINUTES}m"
echo "Stall watchdog: ${SEGMENT_STALL_SECONDS}s (grace ${STARTUP_GRACE_SECONDS}s)"
if [[ "$ARCHIVE_SOURCE" == "1" ]]; then
    echo "Source archive: on, writing to $SOURCE_OUTPUT_DIR (never served under /live)"
else
    echo "Source archive: off"
fi

# Function to start FFmpeg for a stream
start_ffmpeg() {
    local app=$1
    local stream=$2
    local stream_key="${app}/${stream}"
    
    # Skip if already running - check both PID tracking and actual running processes
    if [[ -n "${FFMPEG_PIDS[$stream_key]}" ]]; then
        if kill -0 "${FFMPEG_PIDS[$stream_key]}" 2>/dev/null; then
            echo "[$(date)] FFmpeg already running for $stream_key (PID: ${FFMPEG_PIDS[$stream_key]})"
            return 0
        else
            # PID is dead, clean it up
            echo "[$(date)] Cleaning up dead PID ${FFMPEG_PIDS[$stream_key]} for $stream_key"
            unset FFMPEG_PIDS[$stream_key]
            unset STREAM_APPS[$stream_key]
        fi
    fi
    
    # Double-check: look for any running FFmpeg process for this stream
    local existing_pid=$(pgrep -f "ffmpeg.*$SRS_RTMP_URL/$app/$stream" | head -1)
    if [[ -n "$existing_pid" ]]; then
        echo "[$(date)] Found existing FFmpeg process for $stream_key (PID: $existing_pid), killing it to start fresh"
        kill -TERM "$existing_pid" 2>/dev/null
        sleep 1
        # Force kill if still running
        if kill -0 "$existing_pid" 2>/dev/null; then
            kill -KILL "$existing_pid" 2>/dev/null
        fi
    fi
    
    echo "[$(date)] Starting FFmpeg for stream: $stream_key"
    
    # Create output directory
    local output_dir="$OUTPUT_BASE_DIR"
    mkdir -p "$output_dir"

    if [[ "$ARCHIVE_SOURCE" == "1" ]]; then
        mkdir -p "$SOURCE_OUTPUT_DIR"
    fi

    # No cleanup here on purpose. Segments from a previous session are the archive
    # until the S3 uploader has them, so they are left for the orphan reaper to age
    # out rather than deleted on sight. The playlists are overwritten by FFmpeg
    # anyway, since the filenames do not carry the session prefix.
    #
    # (The three `rm -f "$output_dir/${stream}_*.ts"` lines that used to sit here were
    # inert regardless: the glob was inside the quotes, so they only ever tried to
    # remove a file literally named `${stream}_*.ts`.)

    # Session id, used to keep one FFmpeg run's segments distinct from the next.
    #
    # Clock-derived so it stays readable, but `date +%s` only has second resolution:
    # two starts inside the same second reuse the prefix, and the new session then
    # writes ${stream}_hd_<same>_000000.ts straight over the previous session's
    # segments. A fast crash-restart loop through check_streams (5s interval) can
    # reach that, and the result is silent archive loss rather than a visible error.
    #
    # Bump until the prefix is unused. Previous sessions' segments are still on disk
    # until the orphan reaper takes them, which is exactly what makes this check work.
    #
    # The source archive lives in its own directory but shares the prefix, so both
    # trees have to be clear before the prefix can be reused.
    local timestamp_prefix=$(date +%s)
    while compgen -G "$output_dir/${stream}_*_${timestamp_prefix}_*.ts" >/dev/null \
       || compgen -G "$SOURCE_OUTPUT_DIR/${stream}_source_${timestamp_prefix}_*.ts" >/dev/null; do
        timestamp_prefix=$((timestamp_prefix + 1))
    done

    # Second HLS output on the same process: the publisher's bitstream, remuxed.
    # One process rather than a second RTMP pull, so SRS sees one player and the
    # two outputs cannot disagree about where the stream started.
    #
    # -hls_time is advisory here because -c copy can only split on an existing
    # keyframe; segments come out at the publisher's GOP length. That is exactly
    # why this rendition gets its own index rather than sharing the ladder's.
    local source_args=()
    if [[ "$ARCHIVE_SOURCE" == "1" ]]; then
        source_args=(
            -map 0:v -map 0:a
            -c copy
            -f hls
            -hls_time 2
            -hls_list_size "$DVR_WINDOW_SEGMENTS"
            -hls_delete_threshold "$HLS_DELETE_THRESHOLD"
            -hls_flags independent_segments+delete_segments+program_date_time+discont_start
            -hls_segment_type mpegts
            -start_number 0
            -hls_segment_filename "$SOURCE_OUTPUT_DIR/${stream}_source_${timestamp_prefix}_%06d.ts"
            "$SOURCE_OUTPUT_DIR/${stream}_source.m3u8"
        )
    fi

    # The ladder itself: either three real encodes, or the same bitstream copied
    # into three renditions. Everything after this point is identical, so the
    # output layout does not depend on the mode.
    local ladder_args=()

    if [[ "$ABR_MODE" == "copy" ]]; then
        echo "[$(date)] ABR_MODE=copy - remuxing $stream_key without transcoding"

        # Segment boundaries land on the publisher's keyframes, so the incoming
        # GOP must already match hls_time (the dev publisher sends a 2s GOP).
        ladder_args=(
            -map 0:v -map 0:a
            -map 0:v -map 0:a
            -map 0:v -map 0:a
            -c copy
            -avoid_negative_ts make_zero -fflags +genpts
        )
    else
        # Every -bufsize matches its -maxrate rather than doubling it. A VBV buffer
        # two seconds wide at hls_time 2 lets a whole segment sit above maxrate and
        # be paid back by the next one, and measured on air that is what it did:
        # consecutive fhd segments ran 4.7 to 7.9 Mbps against a 6.5 Mbps ceiling,
        # with every rung peaking 15-17% over the BANDWIDTH its own master playlist
        # advertises. A player picks a rung by that number, so the overshoot lands as
        # a segment that takes longer to fetch than it takes to play. One second of
        # VBV holds the overshoot inside a segment, which is what the advertised
        # bitrate has to mean for ABR to work.
        ladder_args=(
            -filter_complex
                "[0:v]split=3[v1][v2][v3]; \
                 [v1]scale=w=854:h=480[v1out]; \
                 [v2]scale=w=1280:h=720[v2out]; \
                 [v3]scale=w=1920:h=1080[v3out]"
            -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 2000k
                -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0
                -force_key_frames "expr:gte(t,n_forced*2)"
            -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 4000k
                -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0
                -force_key_frames "expr:gte(t,n_forced*2)"
            -map "[v3out]" -c:v:2 libx264 -b:v:2 6000k -maxrate:v:2 6500k -bufsize:v:2 6500k
                -preset:v:2 faster -profile:v:2 main -g 60 -keyint_min 60 -sc_threshold 0
                -force_key_frames "expr:gte(t,n_forced*2)"
            -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0"
            -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0"
            -map 0:a -c:a:2 aac -b:a:2 192k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0"
            -avoid_negative_ts make_zero -fflags +genpts
        )
    fi

    # Start FFmpeg for synchronized multi-bitrate HLS
    ffmpeg -f flv -i "$SRS_RTMP_URL/$app/$stream" \
        "${ladder_args[@]}" \
        -f hls \
        -hls_time 2 \
        -hls_list_size "$DVR_WINDOW_SEGMENTS" \
        -hls_delete_threshold "$HLS_DELETE_THRESHOLD" \
        -hls_flags independent_segments+delete_segments+program_date_time+discont_start \
        -hls_segment_type mpegts \
        -start_number 0 \
        -hls_segment_filename "$output_dir/${stream}_%v_${timestamp_prefix}_%06d.ts" \
        -master_pl_name "${stream}_master.m3u8" \
        -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" \
        "$output_dir/${stream}_%v.m3u8" \
        "${source_args[@]}" \
        > >(sed "s/^/[FFmpeg $stream] /") 2>&1 &

    # Process substitution rather than `| sed`, because for a backgrounded pipeline
    # `$!` is the PID of the *last* stage. It used to be sed's, so stop_ffmpeg killed
    # the log prefixer and left FFmpeg encoding forever; only the pgrep fallback above
    # ever cleaned those up, and then only if the same stream came back. This way $!
    # is FFmpeg itself and the sed exits on its own when FFmpeg closes the pipe.
    local pid=$!
    FFMPEG_PIDS[$stream_key]=$pid
    STREAM_APPS[$stream_key]="$app"
    FFMPEG_STARTED[$stream_key]=$(date +%s)

    # Copy mode advertises one indistinguishable rendition three times, which
    # hides the quality menu. Patch the master once FFmpeg has written it.
    if [[ "$ABR_MODE" == "copy" ]]; then
        watch_copy_mode_master "$output_dir/${stream}_master.m3u8" "$pid" &
    fi

    echo "[$(date)] Started FFmpeg for $stream_key with PID $pid"
}

# Function to stop FFmpeg for a stream
stop_ffmpeg() {
    local stream_key=$1
    
    if [[ -n "${FFMPEG_PIDS[$stream_key]}" ]]; then
        local pid="${FFMPEG_PIDS[$stream_key]}"
        
        if kill -0 "$pid" 2>/dev/null; then
            echo "[$(date)] Stopping FFmpeg for stream: $stream_key (PID: $pid)"
            kill -TERM "$pid"
            
            # Wait for process to terminate gracefully
            local count=0
            while kill -0 "$pid" 2>/dev/null && [ $count -lt 10 ]; do
                sleep 1
                count=$((count + 1))
            done
            
            # Force kill if still running
            if kill -0 "$pid" 2>/dev/null; then
                echo "[$(date)] Force killing FFmpeg for $stream_key"
                kill -KILL "$pid"
            fi
        fi
        
        # Retire the playlists so a player gets a 404 rather than a frozen window.
        #
        # Deliberately not `rm -f "$OUTPUT_BASE_DIR/${stream}"*` as before: that glob
        # took the segments with it, and the segments are the archive until the S3
        # uploader has confirmed copies. The orphan reaper ages them out instead.
        local stream="${stream_key#*/}"
        rm -f "$OUTPUT_BASE_DIR/${stream}_"*.m3u8 "$OUTPUT_BASE_DIR/${stream}.m3u8" 2>/dev/null

        # Same reasoning for the source archive playlist. Its segments stay for the
        # reaper; only the playlist is retired.
        rm -f "$SOURCE_OUTPUT_DIR/${stream}_source.m3u8" 2>/dev/null

        unset FFMPEG_PIDS[$stream_key]
        unset STREAM_APPS[$stream_key]
        unset FFMPEG_STARTED[$stream_key]

        echo "[$(date)] Stopped FFmpeg for $stream_key"
    fi
}

# Newest mtime across a stream's playlists, as an age in seconds. -1 when none exist.
#
# The master playlist is written once and then left alone, so the variant playlists
# are what actually move; taking the maximum lets them dominate.
playlist_age() {
    local stream=$1
    local newest=0
    local mtime

    for playlist in "$OUTPUT_BASE_DIR/${stream}_"*.m3u8; do
        [[ -f "$playlist" ]] || continue
        mtime=$(stat -c %Y "$playlist" 2>/dev/null) || continue
        [[ "$mtime" -gt "$newest" ]] && newest=$mtime
    done

    if [[ "$newest" -eq 0 ]]; then
        echo -1
        return
    fi

    echo $(( $(date +%s) - newest ))
}

# FFmpeg can hold its PID while its input has gone away, which check_streams cannot
# detect because it only compares PIDs against the SRS stream list. Restart a stream
# whose playlists have stopped advancing while SRS still reports it as publishing.
check_stalled() {
    local stream_key=$1
    local stream="${stream_key#*/}"
    # Captured before stop_ffmpeg, which unsets it.
    local app="${STREAM_APPS[$stream_key]}"

    [[ "$SEGMENT_STALL_SECONDS" -gt 0 ]] || return 0
    [[ -n "$app" ]] || return 0

    local started="${FFMPEG_STARTED[$stream_key]:-0}"
    if [[ $(( $(date +%s) - started )) -lt "$STARTUP_GRACE_SECONDS" ]]; then
        return 0
    fi

    local age
    age=$(playlist_age "$stream")

    if [[ "$age" -lt 0 ]]; then
        echo "[$(date)] $stream_key wrote no playlist within ${STARTUP_GRACE_SECONDS}s, restarting FFmpeg"
        stop_ffmpeg "$stream_key"
        start_ffmpeg "$app" "$stream"
        return
    fi

    if [[ "$age" -gt "$SEGMENT_STALL_SECONDS" ]]; then
        echo "[$(date)] $stream_key stalled: no playlist update for ${age}s, restarting FFmpeg"
        stop_ffmpeg "$stream_key"
        start_ffmpeg "$app" "$stream"
    fi
}

# Segments left behind by a previous FFmpeg session, which FFmpeg itself will never
# delete. Retention sits above the playlist window, so a segment the current playlist
# still references can never be caught here.
reap_orphan_segments() {
    [[ "$ORPHAN_RETENTION_MINUTES" -gt 0 ]] || return 0

    local dir
    for dir in "$OUTPUT_BASE_DIR" "$SOURCE_OUTPUT_DIR"; do
        [[ -d "$dir" ]] || continue

        local orphans
        orphans=$(find "$dir" -maxdepth 1 -name '*.ts' -mmin +"$ORPHAN_RETENTION_MINUTES" | wc -l)

        [[ "$orphans" -gt 0 ]] || continue

        find "$dir" -maxdepth 1 -name '*.ts' -mmin +"$ORPHAN_RETENTION_MINUTES" -delete
        echo "[$(date)] Reaped $orphans orphaned segment(s) in $dir older than ${ORPHAN_RETENTION_MINUTES}m"
    done
}

# Function to check SRS API for active streams
check_streams() {
    # Get current streams from SRS API
    local api_response=$(curl -s "$SRS_API_URL/streams/" 2>/dev/null)
    
    if [[ -z "$api_response" ]]; then
        echo "[$(date)] Warning: Failed to fetch streams from SRS API"
        return 1
    fi
    
    echo "[$(date)] API Response received, parsing streams..."
    
    # Parse active streams from JSON response using jq
    local active_streams=()
    
    # Use jq to properly parse JSON and get active streams
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            active_streams+=("$line")
            echo "[$(date)] Detected active stream: $line"
        fi
    done < <(echo "$api_response" | jq -r '.streams[]? | select(.publish.active == true) | "\(.app)/\(.name)"' 2>/dev/null)
    
    echo "[$(date)] Found ${#active_streams[@]} active publishing streams"
    
    # Start FFmpeg for new streams
    for stream_key in "${active_streams[@]}"; do
        echo "[$(date)] Processing stream: $stream_key"
        if [[ "$stream_key" =~ ^([^/]+)/(.+)$ ]]; then
            local app="${BASH_REMATCH[1]}"
            local stream="${BASH_REMATCH[2]}"
            
            # Process streams from ingress or live app
            # Now that SRS doesn't transcode, we just look for any stream
            if [[ "$app" == "ingress" ]] || [[ "$app" == "live" ]]; then
                # Skip if already processing or if it's a quality variant from old setup
                if [[ ! "$stream" =~ _(fhd|hd|sd|ld)$ ]]; then
                    start_ffmpeg "$app" "$stream"
                    # No-op for a process that was just started; the startup grace
                    # covers it.
                    check_stalled "$stream_key"
                fi
            fi
        fi
    done
    
    # Stop FFmpeg for streams that are no longer active
    for stream_key in "${!FFMPEG_PIDS[@]}"; do
        local found=0
        for active_key in "${active_streams[@]}"; do
            if [[ "$stream_key" == "$active_key" ]]; then
                found=1
                break
            fi
        done
        
        if [[ $found -eq 0 ]]; then
            echo "[$(date)] Stream $stream_key is no longer active"
            stop_ffmpeg "$stream_key"
        fi
    done
}

# Cleanup function
cleanup() {
    echo "[$(date)] Shutting down FFmpeg manager..."
    
    # Stop all running FFmpeg processes
    for stream_key in "${!FFMPEG_PIDS[@]}"; do
        stop_ffmpeg "$stream_key"
    done
    
    exit 0
}

# Set up signal handlers
trap cleanup SIGINT SIGTERM

# Main monitoring loop
echo "[$(date)] Starting monitoring loop..."

# Create signal file for immediate checks
SIGNAL_FILE="/tmp/check_streams"
touch "$SIGNAL_FILE"

last_reap=$(date +%s)

# Monitor both timer and signal file
while true; do
    # Check streams immediately if signal file was modified
    if [[ -f "$SIGNAL_FILE" ]]; then
        # Get file modification time
        if [[ $(find "$SIGNAL_FILE" -mmin -0.05 2>/dev/null) ]]; then
            echo "[$(date)] Signal received, checking streams immediately"
            check_streams
            # Reset signal file timestamp to avoid repeated triggers
            touch -t $(date -d '1 minute ago' +%Y%m%d%H%M.%S) "$SIGNAL_FILE" 2>/dev/null || true
        fi
    fi
    
    # Regular interval check
    check_streams

    now=$(date +%s)
    if [[ $(( now - last_reap )) -ge "$REAP_INTERVAL_SECONDS" ]]; then
        reap_orphan_segments
        last_reap=$now
    fi

    sleep "$CHECK_INTERVAL"
done