{{-- CTA button + visible fallback URL. Params: $url, $label --}}
<table role="presentation" class="btn" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto;">
    <tr>
        <td align="center" bgcolor="#1e8665" style="border-radius:6px;background-color:#1e8665;">
            <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;background-color:#1e8665;">{{ $label }}</a>
        </td>
    </tr>
</table>
<p style="margin:0 0 16px 0;font-size:13px;line-height:20px;color:#6b7280;">
    Si el botón no funciona, copiá y pegá este enlace en tu navegador:<br>
    <a href="{{ $url }}" target="_blank" style="color:#1e8665;word-break:break-all;">{{ $url }}</a>
</p>
