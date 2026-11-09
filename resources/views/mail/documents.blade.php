<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        .message p { margin: 0 0 16px; }
        .message ul, .message ol { margin: 0 0 16px; padding-left: 20px; }
        .message a { color: #1C4BB0; }
        .message h2, .message h3 { font-size: 15px; margin: 24px 0 8px; }
    </style>
</head>
<body style="margin:0; padding:0; background:#ffffff;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td style="padding:40px 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; font-size:14px; line-height:22px; color:#151515;">
                    <tr>
                        <td class="message">
                            {!! $html !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top:24px; font-size:12px; line-height:18px; color:#a1a1aa;">
                            {{ \App\Support\Brand::signOff() }}{{ \App\Support\Brand::address() !== null ? ' · '.\App\Support\Brand::address() : '' }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
