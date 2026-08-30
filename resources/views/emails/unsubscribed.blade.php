{{--
    The page a signed unsubscribe link lands on. Deliberately not an Inertia page: it
    is opened from an inbox by somebody who may not be signed in, and the whole point
    is that it works without an account being involved.

    The link itself only ever renders this page. Nothing is switched off until the
    button is pressed, because link scanners and mail previewers fetch every URL in a
    message and would otherwise unsubscribe people who never opened it.
--}}
@php($p = $brand['palette'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} &middot; {{ $brand['siteName'] }}</title>
    <style>
        body { margin:0; background:{{ $p['page'] }}; color:{{ $p['text'] }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width:520px; margin:0 auto; padding:56px 20px; }
        .card { background:{{ $p['card'] }}; border:1px solid {{ $p['border'] }}; border-radius:14px; padding:28px; }
        h1 { margin:0 0 10px; font-size:22px; line-height:1.3; letter-spacing:-0.02em; color:{{ $p['heading'] }}; }
        p { margin:0 0 16px; font-size:15px; line-height:1.6; }
        .muted { color:{{ $p['muted'] }}; font-size:13px; }
        button { appearance:none; border:0; border-radius:9px; background:{{ $p['accent'] }}; color:{{ $p['accentText'] }}; font-size:14px; font-weight:600; padding:12px 22px; cursor:pointer; }
        a { color:{{ $p['muted'] }}; }
        .logo { height:36px; width:auto; display:block; margin-bottom:22px; }
    </style>
</head>
<body>
    <div class="wrap">
        @if ($brand['logoUrl'])
            <img class="logo" src="{{ $brand['logoUrl'] }}" alt="{{ $brand['siteName'] }}">
        @endif

        <div class="card">
            <h1>{{ $title }}</h1>
            <p>{{ $body }}</p>

            @if ($confirmUrl)
                <form method="POST" action="{{ $confirmUrl }}">
                    @csrf
                    <button type="submit">{{ $confirmLabel }}</button>
                </form>
            @endif
        </div>

        <p class="muted" style="margin-top:18px;">
            {{ $brand['siteName'] }}
            @foreach ($brand['links'] as $link)
                &middot; <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach
        </p>
    </div>
</body>
</html>
