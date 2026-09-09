<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $systemName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; -webkit-font-smoothing:antialiased; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="padding:20px 28px; border-bottom:1px solid #eef0f2;">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $systemName }}" height="28" style="height:28px; display:block; border:0;">
                            @else
                                <span style="font-size:16px; font-weight:600; color:#111827;">{{ $systemName }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px; color:#374151; font-size:15px; line-height:1.6;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 28px; border-top:1px solid #eef0f2; color:#9ca3af; font-size:12px; line-height:1.6;">
                            {{ $systemName }}<br>
                            Automatische Nachricht. Bitte nicht auf diese E-Mail antworten.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
