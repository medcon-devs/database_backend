<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $emailTitle ?? 'Medcon Database' }}</title>
    <style>
        body { margin: 0; padding: 0; background: #f2f6fa; color: #17233d; font-family: Arial, Helvetica, sans-serif; }
        table { border-collapse: collapse; }
        .wrapper { width: 100%; background: #f2f6fa; padding: 34px 14px; }
        .container { width: 100%; max-width: 640px; background: #ffffff; border: 1px solid #dce5ef; border-radius: 16px; overflow: hidden; }
        .topbar { height: 6px; background: linear-gradient(90deg, #1aabe3, #030372); }
        .header { padding: 28px 34px 22px; border-bottom: 1px solid #e5ebf2; }
        .brand { color: #030372; font-size: 18px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        .brand-mark { display: inline-block; margin-right: 10px; padding: 8px 11px; border-radius: 9px; background: #1aabe3; color: #ffffff; letter-spacing: 0; }
        .content { padding: 32px 34px 36px; }
        .eyebrow { margin: 0 0 10px; color: #168ac6; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0 0 14px; color: #030372; font-size: 27px; line-height: 1.25; }
        p { margin: 0 0 16px; color: #475569; font-size: 15px; line-height: 1.65; }
        .panel { margin: 22px 0; padding: 18px 20px; border: 1px solid #dfe7f0; border-radius: 11px; background: #f8fafc; }
        .panel p { margin: 7px 0; }
        .label { color: #64748b; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .value { color: #17233d; font-weight: 700; }
        .button { display: inline-block; margin: 8px 0 20px; padding: 13px 22px; border-radius: 9px; background: #1aabe3; color: #ffffff !important; font-size: 15px; font-weight: 800; text-decoration: none; }
        .badge { display: inline-block; padding: 5px 9px; border-radius: 999px; background: #e6f6fc; color: #0578a7; font-size: 12px; font-weight: 800; }
        .badge-danger { background: #feeceb; color: #b42318; }
        .badge-success { background: #eaf8ef; color: #16763a; }
        .comparison { width: 100%; margin: 20px 0; }
        .comparison td { width: 50%; padding: 16px; border: 1px solid #dfe7f0; vertical-align: top; }
        .comparison-title { margin: 0 0 10px; color: #030372; font-size: 14px; font-weight: 800; text-transform: uppercase; }
        .notice { margin: 20px 0; padding: 14px 16px; border-left: 4px solid #1aabe3; background: #f0f9fd; color: #335267; font-size: 14px; line-height: 1.6; }
        .hint { color: #718096; font-size: 12px; }
        .footer { padding: 22px 34px; border-top: 1px solid #e5ebf2; background: #f8fafc; text-align: center; }
        .footer p { margin: 3px 0; color: #718096; font-size: 12px; line-height: 1.5; }
        .footer a { color: #087fad; text-decoration: none; }
        .preheader { display: none !important; max-height: 0; overflow: hidden; opacity: 0; }
        @media only screen and (max-width: 560px) {
            .wrapper { padding: 12px 8px !important; }
            .header, .content, .footer { padding-left: 20px !important; padding-right: 20px !important; }
            h1 { font-size: 23px !important; }
            .comparison td { display: block; width: auto !important; }
        }
    </style>
</head>
<body>
    <span class="preheader">{{ $preheader ?? '' }}</span>
    <table role="presentation" class="wrapper" width="100%">
        <tr><td align="center">
            <table role="presentation" class="container" width="640">
                <tr><td class="topbar"></td></tr>
                <tr><td class="header"><div class="brand"><span class="brand-mark">M</span>Medcon Database</div></td></tr>
                <tr><td class="content">@yield('content')</td></tr>
                <tr><td class="footer">
                    <p>This is an automated message from Medcon Database.</p>
                    @if ($show_notification_preference ?? false)
                        <p>Administrator activity emails can be disabled from account settings.</p>
                    @endif
                    <p>Need help? <a href="mailto:{{ config('mail.footer_contact.address') }}">Contact Medcon Support</a></p>
                    <p>&copy; {{ now()->year }} Medcon. All rights reserved.</p>
                </td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
