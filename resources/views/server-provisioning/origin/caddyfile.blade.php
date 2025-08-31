{{ $server->hostname }} {
    reverse_proxy origin-nginx:80
}