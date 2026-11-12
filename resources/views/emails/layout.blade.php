<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;padding:32px;">
                    <tr>
                        <td style="font-size:15px;line-height:22px;color:#334155;">
                            <p style="margin:0 0 8px;font-size:14px;color:#64748b;">{{ config('branding.name') }}</p>
                            <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">@yield('heading')</h1>
                            @yield('body')
                            @hasSection('action')
                                <p style="margin:24px 0 0;"><a href="@yield('action_url')" style="display:inline-block;background:#0033a9;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;padding:10px 18px;border-radius:999px;">@yield('action')</a></p>
                            @endif
                            <p style="margin:24px 0 0;font-size:12px;line-height:18px;color:#94a3b8;">@yield('footer', 'You’re receiving this because you own a shop on '.config('branding.name').'.')</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
