{{-- Shared transactional layout: table-based, inline CSS, 600px max, mobile friendly. --}}
@php
    $appName = config('app.name');
    $logoUrl = rtrim(config('app.url'), '/') . '/images/logo.png';
@endphp
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <title>@yield('title', $appName)</title>
    <style>
        @media only screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 20px !important; padding-right: 20px !important; }
            .btn a { display: block !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f3f5f4;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">@yield('preheader')</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f5f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;">
                    <tr>
                        <td align="center" style="background-color:#ffffff;padding:24px;border-radius:8px 8px 0 0;border-bottom:4px solid #1e8665;">
                            <img src="{{ $logoUrl }}" alt="{{ $appName }}" width="120" style="display:block;border:0;outline:none;text-decoration:none;width:120px;height:auto;">
                        </td>
                    </tr>
                    <tr>
                        <td class="px" style="background-color:#ffffff;padding:32px 40px;font-size:16px;line-height:24px;color:#1f2937;">
                            @yield('content')
                            <p style="margin:24px 0 0 0;">Saludos,<br>El equipo de {{ $appName }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="background-color:#104637;padding:16px 24px;border-radius:0 0 8px 8px;font-size:12px;line-height:18px;color:#d1e7de;">
                            Este es un mensaje automático de {{ $appName }}. Por favor no respondas a este correo.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
