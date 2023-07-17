version: '3.3'
services:
    stream:
        image: ossrs/srs:4
        restart: unless-stopped
        ports:
            - '1935:1935'
            - '1985:1985'
        networks:
            - default
        volumes:
            - $PWD/custom.conf:/usr/local/srs/conf/custom.conf
        command: ./objs/srs -c /usr/local/srs/conf/custom.conf
@if($isEdge)
    caddy:
        image: caddy:2.7
        restart: unless-stopped
        networks:
            - default
        ports:
            - "80:80"
            - "443:443"
            - "443:443/udp"
        volumes:
            - $PWD/Caddyfile:/etc/caddy/Caddyfile
            - caddy_config:/config
volumes:
    caddy_config:
@endif
networks:
    default:
