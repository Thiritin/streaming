<x-mail.layout
    :brand="$brand"
    :heading="$heading"
    :eyebrow="$eyebrow"
    :preheader="$recording['title']"
    :unsubscribe="$unsubscribe"
>
    @php($p = $brand['palette'])

    <p style="margin:0 0 20px 0;">{{ $intro }}</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $p['raised'] }}; border:1px solid {{ $p['border'] }}; border-radius:12px; overflow:hidden;">
        @if ($recording['thumbnail'])
            <tr>
                <td style="padding:0; line-height:0;">
                    <a href="{{ $recording['url'] }}" style="display:block;">
                        <img src="{{ $recording['thumbnail'] }}" alt="" width="600" style="display:block; width:100%; max-width:100%; height:auto; border:0; outline:none;">
                    </a>
                </td>
            </tr>
        @endif
        <tr>
            <td style="padding:18px 20px 20px 20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

                @if ($recording['category'])
                    <p style="margin:0 0 6px 0; font-size:11px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:{{ $p['accent'] }};">{{ $recording['category'] }}</p>
                @endif

                <p style="margin:0 0 6px 0; font-size:18px; line-height:1.3; font-weight:700; color:{{ $p['heading'] }};">
                    <a href="{{ $recording['url'] }}" style="color:{{ $p['heading'] }}; text-decoration:none;">{{ $recording['title'] }}</a>
                </p>

                @if ($recording['meta'])
                    <p style="margin:0 0 12px 0; font-size:13px; color:{{ $p['muted'] }};">{{ $recording['meta'] }}</p>
                @endif

                @if ($recording['description'])
                    <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:{{ $p['text'] }};">{{ $recording['description'] }}</p>
                @endif

                <x-mail.button :url="$recording['url']" :palette="$p">Watch it</x-mail.button>

            </td>
        </tr>
    </table>
</x-mail.layout>
