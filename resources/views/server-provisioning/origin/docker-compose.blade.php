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
      - dvr-recordings:/dvr/recordings
    command: ./objs/srs -c /usr/local/srs/conf/custom.conf
    restart: unless-stopped
    networks:
      - streaming

  # Origin FFmpeg HLS Transcoder
  origin-ffmpeg-hls:
    image: eurofurence/ffmpeg-hls:latest
    container_name: origin-ffmpeg-hls
    environment:
      SRS_API_URL: http://origin-srs:1985/api/v1
      SRS_RTMP_URL: rtmp://origin-srs:1935
      OUTPUT_BASE_DIR: /var/www/hls/live
      CHECK_INTERVAL: 5
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
  
  # DVR S3 Uploader Service
  dvr-uploader:
    image: eurofurence/dvr-uploader:latest
    container_name: dvr-uploader
    environment:
      S3_BUCKET: ${DVR_AWS_BUCKET:-ef-streaming-recordings}
      S3_REGION: ${DVR_AWS_DEFAULT_REGION:-eu-central-1}
      S3_ACCESS_KEY: ${DVR_AWS_ACCESS_KEY_ID}
      S3_SECRET_KEY: ${DVR_AWS_SECRET_ACCESS_KEY}
      S3_ENDPOINT: ${DVR_AWS_ENDPOINT}
      RECORDINGS_PATH: /dvr/recordings
      DELETE_AFTER_UPLOAD: 'true'
      WEBHOOK_URL: '{{ $serverUrl }}/api/dvr/upload-webhook'
      FILE_AGE_SECONDS: '30'
      UPLOAD_DELAY_SECONDS: '5'
      MAX_UPLOAD_RATE_MBPS: '3'
    volumes:
      - dvr-recordings:/dvr/recordings
    restart: unless-stopped
    depends_on:
      - origin-srs
    networks:
      - streaming

networks:
  streaming:
    driver: bridge

volumes:
  hls-content:
  dvr-recordings:
  caddy-data:
  caddy-config: