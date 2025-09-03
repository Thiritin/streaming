#!/bin/bash

# DVR Extraction Script - Run from host
# Usage: ./dvr-extract.sh <stream> <start> <end> [output] [storage]

STREAM=$1
START=$2
END=$3
OUTPUT=${4:-""}
STORAGE=${5:-"local"}

if [ -z "$STREAM" ] || [ -z "$START" ] || [ -z "$END" ]; then
    echo "Usage: $0 <stream> <start_time> <end_time> [output_file] [storage]"
    echo "Example: $0 summerboat \"2025-09-02 20:14:00\" \"2025-09-02 20:15:00\" output.mp4 local"
    exit 1
fi

# Build the artisan command
CMD="php artisan dvr:extract --stream=$STREAM --start=\"$START\" --end=\"$END\""

if [ ! -z "$OUTPUT" ]; then
    CMD="$CMD --output=$OUTPUT"
fi

CMD="$CMD --storage=$STORAGE"

echo "Running: sail $CMD"
sail $CMD