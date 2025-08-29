#!/bin/bash

# Script to create a master HLS playlist for adaptive bitrate streaming
# This should be run on the SRS server after transcoding is set up

PLAYLIST_DIR="/usr/local/srs/objs/nginx/html/live"
MASTER_PLAYLIST="$PLAYLIST_DIR/livestream.m3u8"

# Create the master playlist
cat > "$MASTER_PLAYLIST" << EOF
#EXTM3U
#EXT-X-VERSION:3

# Original Quality (source)
#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="avc1.640028,mp4a.40.2"
livestream.m3u8

# Full HD
#EXT-X-STREAM-INF:BANDWIDTH=3000000,RESOLUTION=1920x1080,CODECS="avc1.640028,mp4a.40.2"
livestream_fhd.m3u8

# HD
#EXT-X-STREAM-INF:BANDWIDTH=1500000,RESOLUTION=1280x720,CODECS="avc1.64001f,mp4a.40.2"
livestream_hd.m3u8

# SD
#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=854x480,CODECS="avc1.64001e,mp4a.40.2"
livestream_sd.m3u8

# LD
#EXT-X-STREAM-INF:BANDWIDTH=400000,RESOLUTION=640x360,CODECS="avc1.64001e,mp4a.40.2"
livestream_ld.m3u8

# Audio Only HD
#EXT-X-STREAM-INF:BANDWIDTH=128000,CODECS="mp4a.40.2"
livestream_audio_hd.m3u8

# Audio Only SD
#EXT-X-STREAM-INF:BANDWIDTH=64000,CODECS="mp4a.40.2"
livestream_audio_sd.m3u8
EOF

echo "Master playlist created at: $MASTER_PLAYLIST"