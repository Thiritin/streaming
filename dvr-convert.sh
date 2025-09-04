#!/bin/bash

# Input and output configuration
INPUT_FILE="$1"
OUTPUT_DIR="${2:-./hls_output}"
FILENAME=$(basename "$INPUT_FILE")

# Create output directory if it doesn't exist
mkdir -p "$OUTPUT_DIR"

echo "Converting video to HLS format..."
echo "Input: $INPUT_FILE"
echo "Output directory: $OUTPUT_DIR"

# FFmpeg command for VOD HLS conversion using libx264
ffmpeg -i "$INPUT_FILE" \
    -filter_complex \
        "[0:v]split=4[v1][v2][v3][v4]; \
         [v1]scale=w=640:h=360:force_original_aspect_ratio=decrease:force_divisible_by=2[v1out]; \
         [v2]scale=w=854:h=480:force_original_aspect_ratio=decrease:force_divisible_by=2[v2out]; \
         [v3]scale=w=1280:h=720:force_original_aspect_ratio=decrease:force_divisible_by=2[v3out]; \
         [v4]scale=w=1920:h=1080:force_original_aspect_ratio=decrease:force_divisible_by=2[v4out]" \
    \
    -map "[v1out]" -c:v:0 libx264 -b:v:0 800k -maxrate:v:0 1000k -bufsize:v:0 1500k \
        -preset fast -profile:v baseline \
        -g 48 -keyint_min 48 -sc_threshold 0 \
        -force_key_frames "expr:gte(t,n_forced*2)" \
    \
    -map "[v2out]" -c:v:1 libx264 -b:v:1 1500k -maxrate:v:1 2000k -bufsize:v:1 3000k \
        -preset fast -profile:v main \
        -g 48 -keyint_min 48 -sc_threshold 0 \
        -force_key_frames "expr:gte(t,n_forced*2)" \
    \
    -map "[v3out]" -c:v:2 libx264 -b:v:2 3000k -maxrate:v:2 3500k -bufsize:v:2 7000k \
        -preset fast -profile:v main \
        -g 48 -keyint_min 48 -sc_threshold 0 \
        -force_key_frames "expr:gte(t,n_forced*2)" \
    \
    -map "[v4out]" -c:v:3 libx264 -b:v:3 5000k -maxrate:v:3 5500k -bufsize:v:3 11000k \
        -preset fast -profile:v high \
        -g 48 -keyint_min 48 -sc_threshold 0 \
        -force_key_frames "expr:gte(t,n_forced*2)" \
    \
    -map 0:a:0? -c:a:0 aac -b:a:0 96k -ac 2 \
    -map 0:a:0? -c:a:1 aac -b:a:1 128k -ac 2 \
    -map 0:a:0? -c:a:2 aac -b:a:2 160k -ac 2 \
    -map 0:a:0? -c:a:3 aac -b:a:3 192k -ac 2 \
    \
    -f hls \
    -hls_time 4 \
    -hls_playlist_type vod \
    -hls_segment_type mpegts \
    -hls_flags independent_segments \
    -hls_segment_filename "$OUTPUT_DIR/${FILENAME}_%v_segment_%03d.ts" \
    -master_pl_name "${FILENAME}_master.m3u8" \
    -var_stream_map "v:0,a:0,name:360p v:1,a:1,name:480p v:2,a:2,name:720p v:3,a:3,name:1080p" \
    "$OUTPUT_DIR/${FILENAME}_%v.m3u8"

if [ $? -eq 0 ]; then
    echo ""
    echo "HLS conversion complete!"
    echo "Master playlist: $OUTPUT_DIR/${FILENAME}_master.m3u8"
    echo ""
    echo "Variant playlists:"
    echo "  - 360p:  $OUTPUT_DIR/${FILENAME}_360p.m3u8"
    echo "  - 480p:  $OUTPUT_DIR/${FILENAME}_480p.m3u8"
    echo "  - 720p:  $OUTPUT_DIR/${FILENAME}_720p.m3u8"
    echo "  - 1080p: $OUTPUT_DIR/${FILENAME}_1080p.m3u8"
else
    echo "Error: Conversion failed!"
    exit 1
fi