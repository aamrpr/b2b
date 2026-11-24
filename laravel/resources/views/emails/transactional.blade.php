@php
    $home = rtrim((string) config('app.frontend_url'), '/');
    $logo = $home.'/brand/logo-dark.png';
    $tagline = $lang === 'ar' ? 'بضاعتك تبدأ من هون' : 'Your goods start here';
    $footer = $lang === 'ar' ? 'هذه رسالة آلية من المستورد.' : 'This is an automatic message from Al Mustawred.';
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>المستورد</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;color:#142d4e;font-family:'IBM Plex Sans Arabic','Segoe UI',Tahoma,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;border-collapse:collapse;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dde3ec;border-radius:16px;border-collapse:separate;overflow:hidden;">
                    <tr>
                        <td align="{{ $dir === 'rtl' ? 'right' : 'left' }}" style="background:#142d4e;padding:26px 32px 22px;">
                            <a href="{{ $home }}" style="text-decoration:none;">
                                <img src="{{ $logo }}" alt="المستورد" height="40" style="display:block;height:40px;width:auto;max-width:220px;border:0;">
                            </a>
                            <p style="margin:10px 0 0;font-size:13px;line-height:1.5;color:#96dcd9;">{{ $tagline }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="height:4px;background:#16a6a1;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td dir="{{ $dir }}" style="padding:32px;font-size:15px;line-height:1.85;color:#142d4e;">
                            {!! $htmlBody !!}
                        </td>
                    </tr>
                    <tr>
                        <td dir="{{ $dir }}" style="padding:16px 32px 22px;border-top:1px solid #dde3ec;font-size:12px;line-height:1.7;color:#5b708d;">
                            {{ $footer }}
                            <br>
                            <a href="{{ $home }}" style="color:#087f7b;text-decoration:none;">{{ $home }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
