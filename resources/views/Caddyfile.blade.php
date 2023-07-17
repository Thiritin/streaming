{!! $hostname !!} {
    tls me+letsencrypt@thiritin.com
    reverse_proxy /* stream:8080
    reverse_proxy /api/* stream:1985
    reverse_proxy /ready stream:1985

    basicauth /console/* {
        {!! $username !!} {!! $password !!}
    }

    basicauth /api/* {
        {!! $username !!} {!! $password !!}
    }
}
