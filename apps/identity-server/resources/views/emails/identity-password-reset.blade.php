<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset your password</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f7fb; color:#172033; font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">A secure link is ready to reset your password.</div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; background-color:#f4f7fb;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%; max-width:600px;">
                    <tr>
                        <td style="padding:0 0 20px 0; font-size:18px; line-height:24px; font-weight:bold; color:#263a8b;">{{ $applicationName }}</td>
                    </tr>
                    <tr>
                        <td style="background-color:#ffffff; border:1px solid #dfe5ef; padding:36px 32px;">
                            <h1 style="margin:0 0 16px 0; font-size:25px; line-height:32px; font-weight:bold; color:#172033;">Reset your password</h1>
                            <p style="margin:0 0 24px 0; font-size:16px; line-height:25px; color:#4b5870;">We received a request to reset your password for {{ $applicationName }}.</p>
                            @if ($resetUrl !== '')
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
                                    <tr>
                                        <td bgcolor="#263a8b" style="background-color:#263a8b;">
                                            <a href="{{ $resetUrl }}" style="display:inline-block; padding:13px 22px; font-size:16px; line-height:20px; font-weight:bold; color:#ffffff; text-decoration:none;">Reset password</a>
                                        </td>
                                    </tr>
                                </table>
                                <p style="margin:0 0 24px 0; font-size:14px; line-height:22px; color:#65728a;">This secure link expires soon. If you did not request a password reset, you can safely ignore this email.</p>
                            @else
                                <p style="margin:0 0 24px 0; font-size:14px; line-height:22px; color:#65728a;">Use the reset token in the application that requested this reset. The token is included in the plain-text version of this email.</p>
                            @endif
                            <p style="margin:0; padding-top:20px; border-top:1px solid #e7ebf2; font-size:13px; line-height:20px; color:#7a879c;">For your security, never share your reset link or token with anyone.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:20px 16px 0 16px; font-size:12px; line-height:18px; color:#8290a6;">This password reset was requested for {{ $applicationName }}.</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
