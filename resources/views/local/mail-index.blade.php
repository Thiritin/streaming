@php($p = $brand['palette'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mail previews</title>
    <style>
        body { margin:0; background:{{ $p['page'] }}; color:{{ $p['text'] }}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; }
        .wrap { max-width:560px; margin:0 auto; padding:56px 20px; }
        h1 { font-size:20px; color:{{ $p['heading'] }}; margin:0 0 20px; }
        a { display:block; padding:14px 16px; margin-bottom:10px; background:{{ $p['card'] }}; border:1px solid {{ $p['border'] }}; border-radius:10px; color:{{ $p['heading'] }}; text-decoration:none; font-size:15px; }
        a:hover { border-color:{{ $p['accent'] }}; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Mail previews</h1>
        @foreach ($templates as $template)
            <a href="{{ url('/debug/mail/'.$template['key']) }}">{{ $template['label'] }}</a>
        @endforeach
    </div>
</body>
</html>
