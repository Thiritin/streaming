# Edge nginx with njs.
#
# njs is what lets a playback token be verified on the edge with a local HMAC
# instead of an auth subrequest into Laravel, which is the whole point of the
# redesign: PHP stays out of the media hot path. See
# docs/streaming-auth-redesign.md.
#
# nginx publishes nginx-module-njs for the exact nginx version in each base
# image, so the dynamic module always matches the running binary. Pinning the
# base image tag therefore pins the module too.
FROM nginx:alpine

RUN apk add --no-cache nginx-module-njs

COPY hls-auth.js /etc/nginx/njs/hls-auth.js

# nginx.conf is mounted at runtime so it can be regenerated per edge without a
# rebuild.
