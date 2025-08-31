FROM alpine:latest

# Install ffmpeg, bash, curl for API calls, and jq for JSON parsing
RUN apk add --no-cache ffmpeg bash curl jq

# Create directories
RUN mkdir -p /var/www/html/hls/live

# Copy the stream manager script
COPY stream-manager.sh /stream-manager.sh
RUN chmod +x /stream-manager.sh

WORKDIR /var/www/html/hls

ENTRYPOINT ["/stream-manager.sh"]