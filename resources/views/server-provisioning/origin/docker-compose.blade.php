version: '3.8'

services:
  # Origin SRS - RTMP ingestion
  origin-srs:
    image: ossrs/srs:6
    container_name: origin-srs
    ports:
      - "1935:1935"  # RTMP
      - "1985:1985"  # SRS API
      - "8082:8082"  # SRS HTTP
    environment:
      SRS_HTTP_PORT: 8082
    volumes:
      - ./srs.conf:/usr/local/srs/conf/custom.conf:ro
    command: ./objs/srs -c /usr/local/srs/conf/custom.conf
    restart: unless-stopped
    networks:
      - streaming

  # Origin FFmpeg HLS Transcoder
  origin-ffmpeg-hls:
    image: {{ config('stream.images.ffmpeg_hls') }}
    container_name: origin-ffmpeg-hls
    environment:
      SRS_API_URL: http://origin-srs:1985/api/v1
      SRS_RTMP_URL: rtmp://origin-srs:1935
      OUTPUT_BASE_DIR: /var/www/hls/live
      CHECK_INTERVAL: 5
      # 1800 segments at hls_time 2 is the 60 minute live rewind window; the extra
      # 60 retained segments are the grace the S3 uploader gets before a segment is
      # deleted. See docs/dvr-archive-plan.md.
      DVR_WINDOW_SEGMENTS: 1800
      HLS_DELETE_THRESHOLD: 60
      # Restart FFmpeg if it stops advancing its playlists while SRS still reports
      # the stream as publishing.
      SEGMENT_STALL_SECONDS: 15
    volumes:
      - hls-content:/var/www/hls
    restart: unless-stopped
    depends_on:
      - origin-srs
    networks:
      - streaming

  # Origin Nginx - Serves HLS content
  origin-nginx:
    image: nginx:alpine
    container_name: origin-nginx
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - hls-content:/var/www/hls:ro
    restart: unless-stopped
    depends_on:
      - origin-ffmpeg-hls
    networks:
      - streaming

  # Origin Caddy - SSL termination
  origin-caddy:
    image: caddy:alpine
    container_name: origin-caddy
    ports:
      - "80:80"
      - "443:443"
    environment:
      DOMAIN: {{ $server->hostname }}
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
    restart: unless-stopped
    depends_on:
      - origin-nginx
      - origin-srs
    networks:
      - streaming
  
  # HLS Segment Archive Uploader
  #
  # Mirrors the transcoder's segments to S3 and maintains the per-hour index
  # playlists that recordings are cut from. This is the only recording path:
  # SRS DVR is off and the MP4 uploader is gone. See docs/dvr-archive-plan.md.
  archive-uploader:
    image: {{ config('stream.images.archive_uploader') }}
    container_name: archive-uploader
    command: ["python", "-u", "archive_uploader.py"]
    environment:
      S3_BUCKET: ${DVR_AWS_BUCKET:-streaming-recordings}
      S3_REGION: ${DVR_AWS_DEFAULT_REGION:-eu-central-1}
      S3_ACCESS_KEY: ${DVR_AWS_ACCESS_KEY_ID}
      S3_SECRET_KEY: ${DVR_AWS_SECRET_ACCESS_KEY}
      S3_ENDPOINT: ${DVR_AWS_ENDPOINT}
      HLS_PATH: /var/www/hls/live
      # Must match DVR_WINDOW_SEGMENTS on origin-ffmpeg-hls (1800 x 2s). The reaper
      # never deletes inside the window a viewer can still seek back into.
      DVR_WINDOW_SECONDS: '3600'
      # Ceiling on archive upload bandwidth, so it cannot starve origin->edge
      # egress on the same uplink. 20% of a 1 Gbps link.
      #
      # This must stay ABOVE total ingest or uploads fall permanently behind and
      # the origin disk fills: the floor is sources x 11.5 Mbps (the ladder total),
      # so 200 Mbps carries 8 sources at 2x headroom. Raise it alongside the source
      # count, never lower it to "save bandwidth" - the uploader logs an error if
      # the backlog grows, but by then it is already losing. 0 disables the cap.
      MAX_UPLOAD_RATE_MBPS: '200'
    volumes:
      - hls-content:/var/www/hls
      - archive-state:/var/lib/dvr-archive
    restart: unless-stopped
    depends_on:
      - origin-ffmpeg-hls
    networks:
      - streaming

networks:
  streaming:
    driver: bridge

volumes:
  hls-content:
  archive-state:
  caddy-data:
  caddy-config: