#!/bin/bash

# DVR Extraction Script - Downloads only segments within time range
# Usage: ./dvr-extract.sh <stream> <start> <end> [output] [s3_alias]

set -e

# Configuration
S3_ALIAS="${5:-dvr}"  # MinIO/S3 alias
S3_BUCKET="recording/dvr"
CACHE_DIR="/tmp/dvr-cache"  # Persistent cache for segments
TEMP_DIR="/tmp/dvr-extract-$$"
STREAM="$1"
START_TIME="$2"
END_TIME="$3"
OUTPUT="${4:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Functions
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Validate arguments
if [ -z "$STREAM" ] || [ -z "$START_TIME" ] || [ -z "$END_TIME" ]; then
    echo "Usage: $0 <stream> <start_time> <end_time> [output_file] [s3_alias]"
    echo "Example: $0 summerboat \"2025-09-02 20:00:00\" \"2025-09-02 20:30:00\" output.mp4 dvr"
    echo ""
    echo "Time format: YYYY-MM-DD HH:MM:SS (in Europe/Berlin timezone)"
    echo "Output file: Optional, defaults to stream_YYYYMMDD_HHMMSS_HHMMSS.mp4"
    echo "S3 alias: Optional, defaults to 'dvr'"
    exit 1
fi

# Check for required tools
for tool in ffmpeg date mc; do
    if ! command -v "$tool" &> /dev/null; then
        error "$tool is required but not installed"
        exit 1
    fi
done

# Convert times to epoch milliseconds (assuming Europe/Berlin timezone)
START_EPOCH_MS=$(TZ="Europe/Berlin" date -d "$START_TIME" +%s%3N 2>/dev/null || date -d "$START_TIME" +%s000)
END_EPOCH_MS=$(TZ="Europe/Berlin" date -d "$END_TIME" +%s%3N 2>/dev/null || date -d "$END_TIME" +%s000)

if [ -z "$START_EPOCH_MS" ] || [ -z "$END_EPOCH_MS" ]; then
    error "Invalid date format. Please use: YYYY-MM-DD HH:MM:SS"
    exit 1
fi

if [ "$END_EPOCH_MS" -le "$START_EPOCH_MS" ]; then
    error "End time must be after start time"
    exit 1
fi

# Calculate duration in seconds
DURATION_SEC=$(( (END_EPOCH_MS - START_EPOCH_MS) / 1000 ))
HOURS=$(( DURATION_SEC / 3600 ))
MINUTES=$(( (DURATION_SEC % 3600) / 60 ))
SECONDS=$(( DURATION_SEC % 60 ))

log "DVR Extraction for stream: $STREAM"
log "Time range: $START_TIME to $END_TIME (Europe/Berlin)"
log "Duration: $(printf "%02d:%02d:%02d" $HOURS $MINUTES $SECONDS)"
log "S3 Source: $S3_ALIAS/$S3_BUCKET/ingress/$STREAM/"

# Generate output filename if not provided
if [ -z "$OUTPUT" ]; then
    START_FILE=$(TZ="Europe/Berlin" date -d "$START_TIME" +%Y%m%d_%H%M%S 2>/dev/null || date -d "$START_TIME" +%Y%m%d_%H%M%S)
    END_FILE=$(TZ="Europe/Berlin" date -d "$END_TIME" +%H%M%S 2>/dev/null || date -d "$END_TIME" +%H%M%S)
    OUTPUT="${STREAM}_${START_FILE}_${END_FILE}.mp4"
fi

# Create directories
mkdir -p "$TEMP_DIR"
mkdir -p "$CACHE_DIR/$STREAM"
trap "rm -rf $TEMP_DIR" EXIT

# Find segments in S3 within time range
log "Finding segments in S3..."
SEGMENT_LIST="$TEMP_DIR/segments.txt"
CONCAT_LIST="$TEMP_DIR/concat.txt"
> "$SEGMENT_LIST"
> "$CONCAT_LIST"

# Calculate date range
START_DATE=$(TZ="Europe/Berlin" date -d "$START_TIME" +%Y-%m-%d 2>/dev/null || date -d "$START_TIME" +%Y-%m-%d)
END_DATE=$(TZ="Europe/Berlin" date -d "$END_TIME" +%Y-%m-%d 2>/dev/null || date -d "$END_TIME" +%Y-%m-%d)

# Function to find and list segments for a specific date
find_segments_for_date() {
    local date_str="$1"
    local s3_path="$S3_ALIAS/$S3_BUCKET/ingress/$STREAM/$date_str/"
    
    log "Checking S3 path: $s3_path"
    
    # List all mp4 files in the S3 path
    local files=$(mc ls "$s3_path" 2>/dev/null | grep '\.mp4$' | awk '{print $NF}' || true)
    
    if [ -z "$files" ]; then
        warning "No files found in: $s3_path"
        return
    fi
    
    local file_count=$(echo "$files" | wc -l)
    log "Found $file_count mp4 files in $date_str"
    
    # Process each file
    while IFS= read -r filename; do
        if [ -z "$filename" ]; then
            continue
        fi
        
        # Extract timestamp from filename (format: HH-MM-SS_timestampMs.mp4)
        if [[ "$filename" =~ ([0-9]{2}-[0-9]{2}-[0-9]{2})_([0-9]+)\.mp4$ ]]; then
            timestamp_ms="${BASH_REMATCH[2]}"
            
            # Add 30-second buffer for segment overlap
            if [ "$timestamp_ms" -ge $((START_EPOCH_MS - 30000)) ] && [ "$timestamp_ms" -le $((END_EPOCH_MS + 30000)) ]; then
                echo "$timestamp_ms|$date_str|$filename" >> "$SEGMENT_LIST"
            fi
        fi
    done <<< "$files"
}

# Iterate through dates
current_date="$START_DATE"
while [ "$current_date" != "$(date -d "$END_DATE + 1 day" +%Y-%m-%d 2>/dev/null || echo "")" ]; do
    find_segments_for_date "$current_date"
    
    # Move to next date
    current_date=$(date -d "$current_date + 1 day" +%Y-%m-%d 2>/dev/null || break)
    
    # Prevent infinite loop
    if [ "$current_date" \> "$(date -d "$END_DATE + 7 days" +%Y-%m-%d 2>/dev/null || date +%Y-%m-%d)" ]; then
        break
    fi
done

# Sort segments by timestamp
sort -t'|' -k1 -n "$SEGMENT_LIST" > "$SEGMENT_LIST.sorted"
mv "$SEGMENT_LIST.sorted" "$SEGMENT_LIST"

# Check if we found any segments
SEGMENT_COUNT=$(wc -l < "$SEGMENT_LIST")
if [ "$SEGMENT_COUNT" -eq 0 ]; then
    error "No segments found in the specified time range"
    error "Searched in: $S3_ALIAS/$S3_BUCKET/ingress/$STREAM/"
    error "Time range: $START_TIME to $END_TIME"
    error "Epoch range: $START_EPOCH_MS to $END_EPOCH_MS"
    
    # List what's available for debugging
    log "Available dates in S3:"
    mc ls "$S3_ALIAS/$S3_BUCKET/ingress/$STREAM/" 2>/dev/null | head -10 || true
    
    exit 1
fi

log "Found $SEGMENT_COUNT segments to download"

# Download only the segments we need
SEGMENT_NUM=0
DOWNLOADED_COUNT=0
FIRST_TIMESTAMP=""

while IFS='|' read -r timestamp_ms date_str filename; do
    SEGMENT_NUM=$((SEGMENT_NUM + 1))
    
    if [ -z "$FIRST_TIMESTAMP" ]; then
        FIRST_TIMESTAMP="$timestamp_ms"
    fi
    
    # Use cache directory structure
    cache_dir="$CACHE_DIR/$STREAM/$date_str"
    mkdir -p "$cache_dir"
    
    cached_file="$cache_dir/$filename"
    s3_file="$S3_ALIAS/$S3_BUCKET/ingress/$STREAM/$date_str/$filename"
    
    # Download if not cached or incomplete
    if [ ! -f "$cached_file" ] || [ ! -s "$cached_file" ]; then
        log "Downloading segment $SEGMENT_NUM/$SEGMENT_COUNT: $filename"
        if ! mc cp "$s3_file" "$cached_file"; then
            warning "Failed to download: $filename"
            continue
        fi
    else
        log "Using cached segment $SEGMENT_NUM/$SEGMENT_COUNT: $filename"
    fi
    
    # Create numbered symlink for ffmpeg concat
    target_file="$TEMP_DIR/segment_$(printf "%04d" $SEGMENT_NUM).mp4"
    if cp "$cached_file" "$target_file"; then
        echo "file '$target_file'" >> "$CONCAT_LIST"
        DOWNLOADED_COUNT=$((DOWNLOADED_COUNT + 1))
    else
        warning "Failed to copy cached file: $filename"
    fi
done < "$SEGMENT_LIST"

# Check if we have segments to concat
if [ "$DOWNLOADED_COUNT" -eq 0 ]; then
    error "No segments were successfully downloaded"
    exit 1
fi

log "Downloaded $DOWNLOADED_COUNT of $SEGMENT_COUNT segments successfully"

# Combine segments using ffmpeg
log "Combining segments with ffmpeg..."
COMBINED_FILE="$TEMP_DIR/combined.mp4"

# First try with codec copy (fastest)
if ! ffmpeg -f concat -safe 0 -i "$CONCAT_LIST" \
            -c copy -movflags +faststart \
            -loglevel error \
            -y "$COMBINED_FILE" 2>/dev/null; then
    
    warning "Direct concat failed, trying with re-encoding..."
    
    # If concat fails, try re-encoding to ensure compatibility
    if ! ffmpeg -f concat -safe 0 -i "$CONCAT_LIST" \
                -c:v libx264 -preset fast -crf 23 \
                -c:a aac -b:a 192k \
                -movflags +faststart \
                -loglevel error \
                -y "$COMBINED_FILE"; then
        
        # Last resort: concatenate using filter_complex
        warning "Re-encoding failed, trying filter_complex method..."
        
        # Build input list for filter_complex
        INPUT_LIST=""
        FILTER_COMPLEX=""
        INDEX=0
        
        while IFS= read -r line; do
            if [[ "$line" =~ file\ \'(.+)\' ]]; then
                INPUT_LIST="$INPUT_LIST -i '${BASH_REMATCH[1]}'"
                FILTER_COMPLEX="${FILTER_COMPLEX}[${INDEX}:v][${INDEX}:a]"
                INDEX=$((INDEX + 1))
            fi
        done < "$CONCAT_LIST"
        
        if [ $INDEX -gt 0 ]; then
            eval "ffmpeg $INPUT_LIST \
                  -filter_complex \"${FILTER_COMPLEX}concat=n=${INDEX}:v=1:a=1[outv][outa]\" \
                  -map '[outv]' -map '[outa]' \
                  -c:v libx264 -preset fast -crf 23 \
                  -c:a aac -b:a 192k \
                  -movflags +faststart \
                  -loglevel error \
                  -y '$COMBINED_FILE'"
        else
            error "No valid input files found"
            exit 1
        fi
    fi
fi

if [ ! -f "$COMBINED_FILE" ] || [ ! -s "$COMBINED_FILE" ]; then
    error "Failed to combine segments"
    exit 1
fi

# Calculate trim offsets if needed
if [ ! -z "$FIRST_TIMESTAMP" ]; then
    START_OFFSET_MS=$((START_EPOCH_MS - FIRST_TIMESTAMP))
    if [ "$START_OFFSET_MS" -lt 0 ]; then
        START_OFFSET_MS=0
    fi
    START_OFFSET_SEC=$(echo "scale=3; $START_OFFSET_MS / 1000" | bc 2>/dev/null || echo $((START_OFFSET_MS / 1000)))
else
    START_OFFSET_SEC=0
fi

# Trim to exact time range
log "Trimming to exact time range..."
log "Start offset: ${START_OFFSET_SEC}s, Duration: ${DURATION_SEC}s"

# Try with codec copy first (faster)
ffmpeg -i "$COMBINED_FILE" \
       -ss "$START_OFFSET_SEC" \
       -t "$DURATION_SEC" \
       -c copy \
       -avoid_negative_ts make_zero \
       -movflags +faststart \
       -loglevel error \
       -y "$OUTPUT"

# If copy codec fails or output is empty, retry with re-encoding
if [ ! -f "$OUTPUT" ] || [ ! -s "$OUTPUT" ]; then
    warning "Copy codec failed, re-encoding video..."
    
    ffmpeg -i "$COMBINED_FILE" \
           -ss "$START_OFFSET_SEC" \
           -t "$DURATION_SEC" \
           -c:v libx264 -preset fast \
           -c:a copy \
           -movflags +faststart \
           -loglevel error \
           -y "$OUTPUT"
    
    if [ ! -f "$OUTPUT" ] || [ ! -s "$OUTPUT" ]; then
        error "Failed to create output file"
        exit 1
    fi
fi

# Get file size
if [ -f "$OUTPUT" ]; then
    FILE_SIZE=$(ls -lh "$OUTPUT" | awk '{print $5}')
    log "✅ Extraction complete!"
    log "Output file: $OUTPUT"
    log "File size: $FILE_SIZE"
else
    error "Output file was not created"
    exit 1
fi

# Clean up old cache files (older than 7 days)
find "$CACHE_DIR" -type f -mtime +7 -delete 2>/dev/null || true

# Cleanup is handled by trap
exit 0