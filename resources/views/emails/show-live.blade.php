<x-mail.layout
    :brand="$brand"
    :heading="$heading"
    eyebrow="Live now"
    :preheader="$show['title'].' has started'"
    :unsubscribe="$unsubscribe"
>
    @php($p = $brand['palette'])

    <p style="margin:0 0 20px 0;">{{ $intro }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $p['raised'] }}; border:1px solid {{ $p['border'] }}; border-radius:12px;">
        <tr>
            <td style="padding:18px 20px 20px 20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

                <p style="margin:0 0 6px 0; font-size:18px; line-height:1.3; font-weight:700; color:{{ $p['heading'] }};">
                    <a href="{{ $show['url'] }}" style="color:{{ $p['heading'] }}; text-decoration:none;">{{ $show['title'] }}</a>
                </p>

                @if ($show['meta'])
                    <p style="margin:0 0 12px 0; font-size:13px; color:{{ $p['muted'] }};">{{ $show['meta'] }}</p>
                @endif

                @if ($show['description'])
                    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $p['text'] }};">{{ $show['description'] }}</p>
                @endif

                <x-mail.button :url="$show['url']" :palette="$p">Watch now</x-mail.button>

            </td>
        </tr>
    </table>
</x-mail.layout>
