{{--
    The shell every notification is drawn in.

    Tables and inline styles rather than the stylesheet the site uses: a mail client
    strips <style> often enough that a layout depending on it is a layout that arrives
    broken. Every colour comes from App\Support\MailBranding, so an installation that
    picks an accent in /manage > Settings > Look repaints its mail with it.

    One card, not two. The heading and the line under it sit on the page; the card is
    the thing being announced.
--}}
@props([
    'brand',
    'preheader' => null,
    'eyebrow' => null,
    'heading',
    'unsubscribe' => null,
])

@php($p = $brand['palette'])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0; padding:0; background-color:{{ $p['page'] }}; -webkit-font-smoothing:antialiased;">

    {{-- The line a client shows next to the subject. Hidden in the body itself. --}}
    @if ($preheader)
        <div style="display:none; font-size:1px; color:{{ $p['page'] }}; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
            {{ $preheader }}
        </div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $p['page'] }};">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    <tr>
                        <td style="padding:0 0 24px 0;" align="left">
                            @if ($brand['logoUrl'])
                                <img src="{{ $brand['logoUrl'] }}" alt="{{ $brand['siteName'] }}" height="36" style="display:block; height:36px; width:auto; border:0; outline:none;">
                            @else
                                <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:-0.01em; color:{{ $p['heading'] }};">{{ $brand['siteName'] }}</span>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 0 6px 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            @if ($eyebrow)
                                <p style="margin:0 0 8px 0; font-size:11px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:{{ $p['accent'] }};">{{ $eyebrow }}</p>
                            @endif
                            <h1 style="margin:0; font-size:23px; line-height:1.25; font-weight:700; letter-spacing:-0.02em; color:{{ $p['heading'] }};">{{ $heading }}</h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 0 0 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.6; color:{{ $p['text'] }};">
                            {{ $slot }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:24px 0 0 0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.7; color:{{ $p['muted'] }};">

                            @if ($unsubscribe)
                                <p style="margin:0 0 10px 0;">
                                    You are getting this email because you subscribed to notifications from {{ $brand['siteName'] }}. You can
                                    <a href="{{ $unsubscribe['category'] }}" style="color:{{ $p['muted'] }}; text-decoration:underline;">unsubscribe from emails like these</a>
                                    or
                                    <a href="{{ $unsubscribe['all'] }}" style="color:{{ $p['muted'] }}; text-decoration:underline;">unsubscribe from everything</a>.
                                </p>
                            @endif

                            @if (count($brand['links']))
                                <p style="margin:0 0 10px 0;">
                                    @foreach ($brand['links'] as $index => $link)
                                        @if ($index > 0)<span style="color:{{ $p['border'] }};"> &middot; </span>@endif
                                        <a href="{{ $link['url'] }}" style="color:{{ $p['muted'] }}; text-decoration:underline;">{{ $link['label'] }}</a>
                                    @endforeach
                                </p>
                            @endif

                            <p style="margin:0; color:{{ $p['muted'] }};">
                                {{ $brand['siteName'] }}
                                @if ($brand['source'])
                                    <span style="color:{{ $p['border'] }};"> &middot; </span>
                                    <a href="{{ $brand['source']['url'] }}" style="color:{{ $p['muted'] }}; text-decoration:underline;">{{ $brand['source']['licence'] }}</a>
                                @endif
                            </p>

                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
