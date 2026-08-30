@props(['url', 'palette'])

{{-- A table rather than a padded anchor: Outlook collapses the padding on an <a>. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 0 0;">
    <tr>
        <td align="center" style="border-radius:9px; background-color:{{ $palette['accent'] }};">
            <a href="{{ $url }}" style="display:inline-block; padding:12px 22px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; font-weight:600; line-height:1; color:{{ $palette['accentText'] }}; text-decoration:none; border-radius:9px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
