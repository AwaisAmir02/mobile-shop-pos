<!DOCTYPE html>
<html lang="en">
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;padding:32px;">
                    <tr>
                        <td>
                            <p style="margin:0 0 8px;font-size:14px;color:#64748b;">{{ config('branding.name') }}</p>
                            <h1 style="margin:0 0 16px;font-size:20px;">Your verification code</h1>
                            <p style="margin:0 0 24px;font-size:15px;line-height:22px;color:#334155;">Use this code to {{ $action }}. It expires in {{ $minutes }} minutes.</p>
                            <p style="margin:0 0 24px;font-size:32px;font-weight:bold;letter-spacing:8px;color:#0033a9;">{{ $code }}</p>
                            <p style="margin:0;font-size:13px;line-height:20px;color:#64748b;">If you didn’t ask for this code, you can ignore this email. Never share this code with anyone.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
