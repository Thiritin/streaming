listen              1935;
max_connections     {!! $maxConnections !!};
server_id           {!! $serverId !!};
srs_log_tank        console;
daemon              off;

http_api {
    enabled         on;
    listen          1985;
}

http_server {
    enabled         on;
    listen          8080;
    dir             ./objs/nginx/html;
}

rtc_server {
    enabled off;
    listen 8000;
    candidate $CANDIDATE;
}

vhost __defaultVhost__ {
    cluster {
        mode            remote;
        origin          {!! $origin !!}:1935;
    }

    hls {
        enabled         off;
    }

    play {
        gop_cache       off;
        queue_length    10;
        mw_latency      350;
    }

    http_hooks {
        enabled         on;
        on_publish      {!! route('api.stream.play',['shared_secret' => $sharedSecret]) !!};
        on_unpublish    {!! route('api.stream.stop',['shared_secret' => $sharedSecret]) !!};
        on_play         {!! route('api.client.play',['shared_secret' => $sharedSecret]) !!};
        on_stop         {!! route('api.client.stop',['shared_secret' => $sharedSecret]) !!};
    }

    http_remux {
        enabled     on;
        mount       [vhost]/[app]/[stream].flv;
    }

    rtc {
        enabled     off;
    }
}
