#!/bin/bash

# DVR Recording Processing Script
# This script fetches shows to process, extracts recordings, converts to HLS, and uploads to S3

# Configuration
API_BASE_URL="${API_BASE_URL:-http://localhost:8000/api}"
API_KEY="${RECORDING_API_KEY}"
S3_ALIAS="${S3_ALIAS:-dvr}"  # mc alias for DVR S3 bucket
S3_BUCKET="${S3_BUCKET:-recording}"
S3_BASE_PATH="${S3_BASE_PATH:-on-demand}"
EVENT_SLUG="${EVENT_SLUG:-ef29}"  # Event identifier (e.g., ef29 for Eurofurence 29)
TEMP_DIR="${TEMP_DIR:-/tmp/dvr-processing}"
DVR_SOURCE_DIR="${DVR_SOURCE_DIR:-/var/dvr}"  # Directory containing DVR m3u8/ts files
SCRIPT_DIR="$(dirname "$0")"

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

# Check required tools
check_requirements() {
    local missing_tools=()
    
    for tool in ffmpeg mc curl jq; do
        if ! command -v "$tool" &> /dev/null; then
            missing_tools+=("$tool")
        fi
    done
    
    if [ ${#missing_tools[@]} -gt 0 ]; then
        error "Missing required tools: ${missing_tools[*]}"
        error "Please install them before running this script"
        exit 1
    fi
    
    if [ -z "$API_KEY" ]; then
        error "RECORDING_API_KEY environment variable is not set"
        exit 1
    fi
    
    if [ ! -f "$SCRIPT_DIR/dvr-convert.sh" ]; then
        error "dvr-convert.sh not found in script directory"
        exit 1
    fi
}

# Fetch shows to process from API
fetch_shows() {
    log "Fetching shows to process from API..."
    
    local response
    response=$(curl -s -H "X-Recording-Api-Key: $API_KEY" \
                    "$API_BASE_URL/recording/shows")
    
    if [ $? -ne 0 ]; then
        error "Failed to fetch shows from API"
        return 1
    fi
    
    echo "$response" | jq -r '.data[] | @json'
}

# Extract recording using dvr-extract
extract_recording() {
    local source="$1"
    local start="$2"
    local end="$3"
    local output_file="$4"
    
    log "Extracting recording for source: $source from $start to $end"
    
    # Find the DVR directory for this source
    local dvr_path="$DVR_SOURCE_DIR/$source"
    if [ ! -d "$dvr_path" ]; then
        error "DVR directory not found: $dvr_path"
        return 1
    fi
    
    # Find the m3u8 file
    local m3u8_file="$dvr_path/index.m3u8"
    if [ ! -f "$m3u8_file" ]; then
        error "M3U8 file not found: $m3u8_file"
        return 1
    fi
    
    # Use dvr-extract to extract the time range
    # Note: dvr-extract should be available in the system
    if command -v dvr-extract &> /dev/null; then
        dvr-extract -i "$m3u8_file" -s "$start" -e "$end" -o "$output_file"
    else
        # Fallback to ffmpeg if dvr-extract is not available
        warning "dvr-extract not found, using ffmpeg instead"
        ffmpeg -i "$m3u8_file" \
               -ss "$(date -d "$start" '+%H:%M:%S')" \
               -to "$(date -d "$end" '+%H:%M:%S')" \
               -c copy \
               "$output_file"
    fi
    
    return $?
}

# Convert to HLS using dvr-convert.sh
convert_to_hls() {
    local input_file="$1"
    local output_dir="$2"
    
    log "Converting to HLS format..."
    
    "$SCRIPT_DIR/dvr-convert.sh" "$input_file" "$output_dir"
    
    return $?
}

# Upload to S3
upload_to_s3() {
    local local_dir="$1"
    local s3_path="$2"
    
    log "Uploading to S3: $s3_path"
    
    # Use mc mirror to upload
    mc mirror --overwrite "$local_dir/" "$S3_ALIAS/$S3_BUCKET/$s3_path/"
    
    if [ $? -eq 0 ]; then
        # Get the URL of the master playlist
        local master_playlist="$(basename "$local_dir")_master.m3u8"
        local s3_url="https://${S3_BUCKET}.s3.amazonaws.com/${s3_path}/${master_playlist}"
        echo "$s3_url"
        return 0
    else
        error "Failed to upload to S3"
        return 1
    fi
}

# Create recording via API
create_recording() {
    local show_id="$1"
    local title="$2"
    local m3u8_url="$3"
    local description="$4"
    
    log "Creating recording in database..."
    
    local response
    response=$(curl -s -X POST \
                    -H "X-Recording-Api-Key: $API_KEY" \
                    -H "Content-Type: application/json" \
                    -d "{
                        \"show_id\": $show_id,
                        \"title\": \"$title\",
                        \"m3u8_url\": \"$m3u8_url\",
                        \"description\": \"$description\"
                    }" \
                    "$API_BASE_URL/recording/create")
    
    if [ $? -eq 0 ]; then
        echo "$response" | jq -r '.success'
        return 0
    else
        error "Failed to create recording"
        return 1
    fi
}

# Process a single show
process_show() {
    local show_json="$1"
    
    # Parse show data
    local show_id=$(echo "$show_json" | jq -r '.show_id')
    local source=$(echo "$show_json" | jq -r '.source')
    local show_slug=$(echo "$show_json" | jq -r '.show')
    local start=$(echo "$show_json" | jq -r '.start')
    local end=$(echo "$show_json" | jq -r '.end')
    local title=$(echo "$show_json" | jq -r '.title')
    local description=$(echo "$show_json" | jq -r '.description // ""')
    
    log "Processing show: $title (ID: $show_id)"
    
    # Create temporary directory for this show
    local work_dir="$TEMP_DIR/$show_slug"
    mkdir -p "$work_dir"
    
    # Extract recording
    local extracted_file="$work_dir/extracted.mp4"
    if ! extract_recording "$source" "$start" "$end" "$extracted_file"; then
        error "Failed to extract recording for show $show_id"
        rm -rf "$work_dir"
        return 1
    fi
    
    # Convert to HLS
    local hls_dir="$work_dir/hls"
    if ! convert_to_hls "$extracted_file" "$hls_dir"; then
        error "Failed to convert recording to HLS for show $show_id"
        rm -rf "$work_dir"
        return 1
    fi
    
    # Upload to S3
    local s3_path="$S3_BASE_PATH/$EVENT_SLUG/$show_slug"
    local m3u8_url
    m3u8_url=$(upload_to_s3 "$hls_dir" "$s3_path")
    
    if [ $? -ne 0 ]; then
        error "Failed to upload recording to S3 for show $show_id"
        rm -rf "$work_dir"
        return 1
    fi
    
    # Create recording in database
    if create_recording "$show_id" "$title" "$m3u8_url" "$description"; then
        log "Successfully processed show: $title"
    else
        warning "Recording uploaded but failed to update database for show $show_id"
    fi
    
    # Clean up
    rm -rf "$work_dir"
    
    return 0
}

# Main execution
main() {
    log "DVR Recording Processing Script Starting..."
    
    # Check requirements
    check_requirements
    
    # Create temp directory
    mkdir -p "$TEMP_DIR"
    
    # Fetch shows to process
    shows=$(fetch_shows)
    
    if [ -z "$shows" ]; then
        log "No shows to process"
        exit 0
    fi
    
    # Process each show
    local total=0
    local successful=0
    local failed=0
    
    while IFS= read -r show_json; do
        ((total++))
        
        show_data=$(echo "$show_json" | jq -r '.')
        
        if process_show "$show_data"; then
            ((successful++))
        else
            ((failed++))
        fi
        
        # Add a small delay between processing shows
        sleep 2
    done <<< "$shows"
    
    # Summary
    log "Processing complete!"
    log "Total: $total | Successful: $successful | Failed: $failed"
    
    # Clean up temp directory
    rm -rf "$TEMP_DIR"
    
    exit 0
}

# Run main function
main "$@"