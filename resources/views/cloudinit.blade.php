#cloud-config
package_upgrade: true
packages:
  - apt-transport-https
  - ca-certificates
  - curl
  - software-properties-common
runcmd:
  - curl -fsSL https://get.docker.com -o get-docker.sh
  - sh get-docker.sh
  - mkdir -p /root/stream
  - curl -o /root/stream/Caddyfile "{!! $serverUrl !!}/api/file/Caddyfile?shared_secret={!! $sharedSecret !!}"
  - curl -o /root/stream/docker-compose.yml "{!! $serverUrl !!}/api/file/docker-compose.yml?shared_secret={!! $sharedSecret !!}"
  - curl -o /root/stream/custom.conf "{!! $serverUrl !!}/api/file/{!! $type !!}.conf?shared_secret={!! $sharedSecret !!}"
  - cd /root/stream
  - docker compose up -d
