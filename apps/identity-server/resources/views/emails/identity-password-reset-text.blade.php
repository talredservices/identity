{{ $applicationName }}

Reset your password

We received a request to reset your password for {{ $applicationName }}.

@if ($resetUrl !== '')
Open this secure link to choose a new password:
{{ $resetUrl }}
@else
Use this reset token in the application that requested the reset:
{{ $token }}
@endif

If you did not request a password reset, you can safely ignore this email.

For your security, never share your reset link or token with anyone.
