version: '3.8'

services:
  # Edge Nginx - Caching proxy for HLS content
  edge-nginx:
    image: nginx:alpine
    container_name: edge-nginx
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
    tmpfs:
      - /var/cache/nginx:rw,noexec,nosuid,size=512m
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