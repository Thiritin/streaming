version: '3.8'

services:
  # Edge Nginx - Caching proxy for HLS content
  #
  # Built locally rather than pulled, because the image needs the njs module to
  # verify playback tokens on the edge. See docs/streaming-auth-redesign.md.
  # Rebuild with: docker compose up -d --build edge-nginx
  edge-nginx:
    build:
      context: .
      dockerfile: Dockerfile.edge-nginx
    container_name: edge-nginx
    environment:
      # Shared with the app so tokens minted there verify here. The leeway must
      # match stream.token.leeway so both ends allow the same grace past expiry.
      HLS_VIEWER_SECRET: {{ $hlsViewerSecret }}
      HLS_EMBED_SECRET: {{ $hlsEmbedSecret }}
      HLS_TOKEN_LEEWAY: {{ $hlsTokenLeeway }}
      STREAM_SYSTEM_STREAMKEY: {{ $systemStreamkey }}
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      # Mounted rather than baked in, so the verifier can be updated without a
      # rebuild of the image.
      - ./hls-auth.js:/etc/nginx/njs/hls-auth.js:ro
      - /var/cache/nginx:/var/cache/nginx
    restart: unless-stopped
    networks:
      - streaming

  # Edge Caddy - SSL termination for edge
  edge-caddy:
    image: caddy:alpine
    container_name: edge-caddy
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
      - edge-nginx
    networks:
      - streaming

networks:
  streaming:
    driver: bridge

volumes:
  caddy-data:
  caddy-config:
