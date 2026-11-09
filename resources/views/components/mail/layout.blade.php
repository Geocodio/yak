@props(['preheader' => '', 'footer' => null])
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>Yak</title>
{{--
    Every style that matters is inline on the element, which is what Gmail
    and Outlook for Windows read. This block only adds the dark palette for
    clients that honour prefers-color-scheme (Apple Mail, iOS Mail,
    Outlook.com) and the narrow-screen padding.
--}}
<style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body { margin: 0; padding: 0; }
    a { text-decoration: none; }
    @media (max-width: 600px) {
        .em-pad { padding-left: 18px !important; padding-right: 18px !important; }
    }
    @media (prefers-color-scheme: dark) {
        .em-bg { background-color: #0f1011 !important; }
        .em-panel { background-color: #171819 !important; border-color: #26272b !important; }
        .em-border { border-color: #26272b !important; }
        .em-text { color: #e6e7ea !important; }
        .em-muted { color: #9a9ea8 !important; }
        .em-faint { color: #6b6f7a !important; }
        .em-button { background-color: #8b7bd8 !important; color: #1a1620 !important; }
        .em-ghost { background-color: #171819 !important; border-color: #303137 !important; color: #e6e7ea !important; }
        .em-chip { background-color: #0f1011 !important; border-color: #26272b !important; color: #e6e7ea !important; }
        .em-note { background-color: #221f17 !important; color: #e6e7ea !important; }
        .em-ok { background-color: #1a2619 !important; color: #6fbe68 !important; }
        .em-warn { background-color: #2a2016 !important; color: #e8985a !important; }
        .em-link { color: #a99ce3 !important; }
    }
</style>
</head>
<body class="em-bg" style="margin:0;padding:0;background-color:#f5f6f8;-webkit-text-size-adjust:100%;">
@if ($preheader !== '')
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f5f6f8;opacity:0;">{{ $preheader }}&#8202;&zwnj;&nbsp;&#8202;&zwnj;&nbsp;&#8202;&zwnj;&nbsp;&#8202;&zwnj;&nbsp;&#8202;&zwnj;&nbsp;&#8202;&zwnj;&nbsp;</div>
@endif
<table role="presentation" class="em-bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f6f8;">
<tr>
<td align="center" style="padding:28px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Roboto,Helvetica,Arial,sans-serif;">
        <tr>
            <td style="padding:0 4px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="vertical-align:middle;padding-right:10px;">
                            <img src="{{ asset('mascot-avatar.png') }}" width="28" height="28" alt="" style="display:block;width:28px;height:28px;border-radius:50%;border:0;">
                        </td>
                        <td class="em-muted" style="vertical-align:middle;font-size:13px;color:#6b6f7a;">
                            <span class="em-text" style="color:#282a30;font-weight:600;">Yak</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="em-panel" style="background-color:#ffffff;border:1px solid #ebecef;border-radius:12px;">
                {{ $slot }}
            </td>
        </tr>
        @if ($footer)
        <tr>
            <td class="em-faint" align="center" style="padding:16px 12px 0;font-size:12px;line-height:1.6;color:#9a9ea8;">
                {{ $footer }}
            </td>
        </tr>
        @endif
    </table>
</td>
</tr>
</table>
</body>
</html>
