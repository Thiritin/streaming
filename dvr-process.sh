#!/bin/bash

# DVR Recording Processing Script
# This script fetches shows to process, extracts recordings, converts to HLS, and uploads to S3

# Get the directory of this script
SCRIPT_DIR="$(dirname "$0")"

# Load environment variables from .env file if it exists
if [ -f "$SCRIPT_DIR/.env" ]; then
    # Export all variables from .env file
    set -a
    source "$SCRIPT_DIR/.env"
    set +a
    echo "Loaded configuration from .env file"
elif [ -f ".env" ]; then
    # Try current directory as fallback
    set -a
    source .env
    set +a
    echo "Loaded configuration from .env file"
fi

# Configuration (with defaults if not set in .env)
API_BASE_URL="${API_BASE_URL:-http://localhost:8000/api}"
API_KEY="${RECORDING_API_KEY}"
S3_ALIAS="${S3_ALIAS:-dvr}"  # mc alias for DVR S3 bucket
S3_BUCKET="${S3_BUCKET:-recording}"
S3_BASE_PATH="${S3_BASE_PATH:-on-demand}"
EVENT_SLUG="${EVENT_SLUG:-ef29}"  # Event identifier (e.g., ef29 for Eurofurence 29)
TEMP_DIR="${TEMP_DIR:-/tmp/dvr-processing}"
DVR_SOURCE_DIR="${DVR_SOURCE_DIR:-/var/dvr}"  # Directory containing DVR m3u8/ts files

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
        error "Please set it in your .env file or export it:"
        error "  export RECORDING_API_KEY='your-api-key-here'"
        exit 1
    fi
    
    if [ ! -f "$SCRIPT_DIR/dvr-convert.sh" ]; then
        error "dvr-convert.sh not found in script directory"
        exit 1
    fi
    
    if [ ! -f "$SCRIPT_DIR/dvr-extract.sh" ]; then
        error "dvr-extract.sh not found in script directory"
        exit 1
    fi
}

# Fetch shows to process from API
fetch_shows() {
    # Log to stderr so it doesn't corrupt the output
    log "Fetching shows to process from API..." >&2
    log "API URL: $API_BASE_URL/recording/shows" >&2
    
    # Add verbose output for debugging
    if [ "${DEBUG:-0}" = "1" ]; then
        log "API Key: ${API_KEY:0:10}..." >&2 # Show first 10 chars for debugging
    fi
    
    local response
    local http_code
    
    # Use -w to get HTTP status code
    response=$(curl -s -w "\n__HTTP_CODE__:%{http_code}" \
                    -H "X-Recording-Api-Key: $API_KEY" \
                    "$API_BASE_URL/recording/shows")
    
    # Extract HTTP code from response
    http_code=$(echo "$response" | grep "__HTTP_CODE__:" | cut -d: -f2)
    response=$(echo "$response" | grep -v "__HTTP_CODE__:")
    
    if [ "$http_code" != "200" ]; then
        error "API returned HTTP $http_code"
        if [ "${DEBUG:-0}" = "1" ]; then
            error "Response: $response"
        fi
        return 1
    fi
    
    # Check if response is valid JSON
    if ! echo "$response" | jq empty 2>/dev/null; then
        error "Invalid JSON response from API"
        if [ "${DEBUG:-0}" = "1" ]; then
            error "Response: $response"
        fi
        return 1
    fi
    
    # Check if response has success flag
    local success=$(echo "$response" | jq -r '.success // false')
    if [ "$success" != "true" ]; then
        local error_msg=$(echo "$response" | jq -r '.message // "Unknown error"')
        error "API error: $error_msg"
        return 1
    fi
    
    # Return the data array
    echo "$response" | jq -r '.data[] | @json'
}

# Extract recording using dvr-extract.sh
extract_recording() {
    local source="$1"
    local start="$2"
    local end="$3"
    local output_file="$4"
    
    log "Extracting recording for source: $source from $start to $end" >&2
    
    # Convert ISO8601 to the format expected by the extraction script
    # The script expects: "YYYY-MM-DD HH:MM:SS" in Europe/Berlin timezone
    # The input is already in Europe/Berlin timezone (with +02:00 offset)
    # We need to preserve the local time, not convert it
    local formatted_start=$(echo "$start" | sed 's/T/ /' | sed 's/+.*//')
    local formatted_end=$(echo "$end" | sed 's/T/ /' | sed 's/+.*//')
    
    # Use the extraction script
    if [ -f "$SCRIPT_DIR/dvr-extract.sh" ]; then
        log "Running: $SCRIPT_DIR/dvr-extract.sh '$source' '$formatted_start' '$formatted_end' '$output_file' '$S3_ALIAS'" >&2
        "$SCRIPT_DIR/dvr-extract.sh" "$source" "$formatted_start" "$formatted_end" "$output_file" "$S3_ALIAS"
    else
        error "dvr-extract.sh not found in script directory"
        return 1
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
    
    # Validate input
    if [ -z "$show_json" ] || [ "$show_json" = "null" ]; then
        error "Invalid show data received"
        return 1
    fi
    
    log "Processing show JSON: $show_json" >&2
    
    # Parse show data
    local show_id=$(echo "$show_json" | jq -r '.show_id // ""')
    local source=$(echo "$show_json" | jq -r '.source // ""')
    local show_slug=$(echo "$show_json" | jq -r '.show // ""')
    local start=$(echo "$show_json" | jq -r '.start // ""')
    local end=$(echo "$show_json" | jq -r '.end // ""')
    local title=$(echo "$show_json" | jq -r '.title // ""')
    local description=$(echo "$show_json" | jq -r '.description // ""')
    
    # Validate required fields
    if [ -z "$show_id" ] || [ -z "$source" ] || [ -z "$start" ] || [ -z "$end" ]; then
        error "Missing required fields in show data"
        error "Show data: $show_json"
        return 1
    fi
    
    log "Processing show: $title (ID: $show_id)"
    
    # Create temporary directory for this show
    local work_dir="$TEMP_DIR/$show_slug"
    log "Creating work directory: $work_dir" >&2
    mkdir -p "$work_dir"
    
    # Extract recording
    local extracted_file="$work_dir/extracted.mp4"
    log "Starting extraction for show $show_id" >&2
    log "Calling: extract_recording '$source' '$start' '$end' '$extracted_file'" >&2
    if ! extract_recording "$source" "$start" "$end" "$extracted_file"; then
        error "Failed to extract recording for show $show_id"
        rm -rf "$work_dir"
        return 1
    fi
    
    # Check if extracted file exists
    if [ ! -f "$extracted_file" ]; then
        error "Extracted file does not exist: $extracted_file"
        rm -rf "$work_dir"
        return 1
    fi
    
    local extracted_size=$(ls -lh "$extracted_file" 2>/dev/null | awk '{print $5}')
    log "Extraction complete. File size: $extracted_size" >&2
    
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
    set +e  # Don't exit on error
    shows=$(fetch_shows)
    fetch_result=$?
    set -e  # Re-enable exit on error
    
    # Check if fetch_shows failed
    if [ $fetch_result -ne 0 ]; then
        error "Failed to fetch shows from API. Check your API key and URL."
        error "API_BASE_URL: $API_BASE_URL"
        error "To debug, run: DEBUG=1 $0"
        exit 1
    fi
    
    if [ -z "$shows" ]; then
        log "No shows to process"
        exit 0
    fi
    
    # Debug: log the shows we got
    log "Found $(echo "$shows" | wc -l) show(s) to process"
    
    # Process each show
    local total=0
    local successful=0
    local failed=0
    
    while IFS= read -r show_json; do
        # Skip empty lines
        if [ -z "$show_json" ] || [ "$show_json" = "null" ]; then
            continue
        fi
        
        total=$((total + 1))
        
        # Always show what we're processing
        log "Processing show $total" >&2
        
        # Parse JSON
        if ! show_data=$(echo "$show_json" | jq -r '.' 2>/dev/null); then
            error "Failed to parse JSON: $show_json"
            failed=$((failed + 1))
            continue
        fi
        
        if process_show "$show_data"; then
            successful=$((successful + 1))
        else
            failed=$((failed + 1))
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