#!/bin/bash

# Dynamic FFmpeg HLS Manager for SRS
# Monitors SRS API and starts/stops FFmpeg processes for each stream

SRS_API_URL="${SRS_API_URL:-http://localhost:1985/api/v1}"
SRS_RTMP_URL="${SRS_RTMP_URL:-rtmp://localhost:1935}"
OUTPUT_BASE_DIR="${OUTPUT_BASE_DIR:-/var/www/html/hls/live}"
CHECK_INTERVAL="${CHECK_INTERVAL:-5}"

# Associative array to track running FFmpeg processes
declare -A FFMPEG_PIDS
declare -A STREAM_APPS

echo "Starting Dynamic FFmpeg HLS Manager"
echo "SRS API: $SRS_API_URL"
echo "Output directory: $OUTPUT_BASE_DIR"
echo "Check interval: ${CHECK_INTERVAL}s"

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
        echo "[$(date)] Found existing FFmpeg process for $stream_key (PID: $existing_pid), tracking it"
        FFMPEG_PIDS[$stream_key]=$existing_pid
        STREAM_APPS[$stream_key]="$app"
        return 0
    fi
    
    echo "[$(date)] Starting FFmpeg for stream: $stream_key"
    
    # Create output directory
    local output_dir="$OUTPUT_BASE_DIR"
    mkdir -p "$output_dir"
    
    # Generate a unique timestamp prefix for this session
    local timestamp_prefix=$(date +%s)
    
    # Start FFmpeg with filter_complex for synchronized multi-bitrate HLS
    ffmpeg -f flv -i "$SRS_RTMP_URL/$app/$stream" \
        -filter_complex \
            "[0:v]split=3[v1][v2][v3]; \
             [v1]scale=w=854:h=480[v1out]; \
             [v2]scale=w=1280:h=720[v2out]; \
             [v3]scale=w=1920:h=1080[v3out]" \
        -map "[v1out]" -c:v:0 libx264 -b:v:0 1500k -maxrate:v:0 2000k -bufsize:v:0 3000k \
            -preset:v:0 veryfast -profile:v:0 baseline -g 60 -keyint_min 60 -sc_threshold 0 \
            -force_key_frames "expr:gte(t,n_forced*2)" \
        -map "[v2out]" -c:v:1 libx264 -b:v:1 3500k -maxrate:v:1 4000k -bufsize:v:1 8000k \
            -preset:v:1 veryfast -profile:v:1 main -g 60 -keyint_min 60 -sc_threshold 0 \
            -force_key_frames "expr:gte(t,n_forced*2)" \
        -map "[v3out]" -c:v:2 libx264 -b:v:2 6000k -maxrate:v:2 6500k -bufsize:v:2 13000k \
            -preset:v:2 faster -profile:v:2 main -g 60 -keyint_min 60 -sc_threshold 0 \
            -force_key_frames "expr:gte(t,n_forced*2)" \
        -map 0:a -c:a:0 aac -b:a:0 128k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0" \
        -map 0:a -c:a:1 aac -b:a:1 160k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0" \
        -map 0:a -c:a:2 aac -b:a:2 192k -ac 2 -af "aresample=async=1:min_hard_comp=0.100000:first_pts=0" \
        -avoid_negative_ts make_zero -fflags +genpts \
        -f hls \
        -hls_time 2 \
        -hls_list_size 30 \
        -hls_flags independent_segments+delete_segments+program_date_time+discont_start \
        -hls_segment_type mpegts \
        -start_number 0 \
        -hls_segment_filename "$output_dir/${stream}_%v_${timestamp_prefix}_%05d.ts" \
        -master_pl_name "${stream}_master.m3u8" \
        -var_stream_map "v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fhd" \
        "$output_dir/${stream}_%v.m3u8" \
        2>&1 | sed "s/^/[FFmpeg $stream] /" &
    
    local pid=$!
    FFMPEG_PIDS[$stream_key]=$pid
    STREAM_APPS[$stream_key]="$app"
    
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
        
        # Clean up HLS files
        local stream="${stream_key#*/}"
        rm -f "$OUTPUT_BASE_DIR/${stream}"*
        
        unset FFMPEG_PIDS[$stream_key]
        unset STREAM_APPS[$stream_key]
        
        echo "[$(date)] Stopped FFmpeg for $stream_key"
    fi
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
    
    sleep "$CHECK_INTERVAL"
done