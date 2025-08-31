{{ $server->hostname }} {
    reverse_proxy edge-nginx:80
}