version: '3.8'

services:
  # Origin SRS - RTMP ingestion
  origin-srs:
    image: ossrs/srs:6
    container_name: origin-srs
    ports:
      - "1935:1935"  # RTMP, public: encoders publish here
      # Loopback only. The transcoder reaches SRS as origin-srs:1985 over the compose
      # network, and the install script probes /api/v1/versions from the host, so
      # neither needs the port published to 0.0.0.0. Docker writes its own DOCKER
      # chain rules, so a published port is reachable the moment the Hetzner firewall
      # is detached or misapplied; binding here does not depend on it.
      - "127.0.0.1:1985:1985"  # SRS API
      - "127.0.0.1:8082:8082"  # SRS HTTP stats
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
      # 900 segments at hls_time 2 is the 30 minute live rewind window, and also how
      # far behind the archive uploader may fall: the indexer can only index what the
      # playlist still lists.
      #
      # The extra 1500 retained segments are a disk backstop, not the upload grace.
      # Deletion belongs to the uploader, which unlinks only what S3 has confirmed;
      # this is what stops the disk filling if the bucket is unreachable, and it buys
      # another 50 minutes before anything is dropped unread.
      DVR_WINDOW_SEGMENTS: 900
      HLS_DELETE_THRESHOLD: 1500
      # Restart FFmpeg if it stops advancing its playlists while SRS still reports
      # the stream as publishing.
      SEGMENT_STALL_SECONDS: 15
      # Mirror the publisher's own bitstream, remuxed rather than re-encoded, so the
      # archive keeps the contribution quality instead of topping out at the 6 Mbps
      # fhd rung. Written to /var/www/hls/source, which is a sibling of the ladder's
      # directory and therefore outside nginx's `location ~ ^/live/` - viewers cannot
      # reach it even by guessing a filename. Set to 0 to turn it off.
      ARCHIVE_SOURCE: 1
      SOURCE_OUTPUT_DIR: /var/www/hls/source
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
      # The archive-only source rendition. Same hour prefix in S3 as the ladder, but
      # its own hour index (index-source.m3u8): it is cut on the publisher's
      # keyframes rather than the ladder's forced 2s marks, so one index entry
      # cannot describe both.
      ARCHIVE_SOURCE: 1
      SOURCE_HLS_PATH: /var/www/hls/source
      # Must match DVR_WINDOW_SEGMENTS on origin-ffmpeg-hls (900 x 2s). The reaper
      # never deletes inside the window a viewer can still seek back into, and never
      # deletes a segment S3 has not confirmed, so this is a floor on age and not a
      # retention policy in itself.
      DVR_WINDOW_SECONDS: '1800'
      # Ceiling on archive upload bandwidth, so it cannot starve origin->edge
      # egress on the same uplink. 20% of a 1 Gbps link.
      #
      # This must stay ABOVE total ingest or uploads fall permanently behind and
      # the origin disk fills. The floor is sources x (11.5 Mbps ladder + whatever
      # the publisher actually sends, since ARCHIVE_SOURCE mirrors it verbatim), so
      # a 17 Mbps contribution feed makes it ~28.5 Mbps per source and 200 Mbps
      # carries 7 sources at no headroom at all. Raise it alongside the source count
      # and the contribution bitrate, never lower it to "save bandwidth" - the
      # uploader logs an error if the backlog grows, but by then it is already
      # losing. 0 disables the cap.
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