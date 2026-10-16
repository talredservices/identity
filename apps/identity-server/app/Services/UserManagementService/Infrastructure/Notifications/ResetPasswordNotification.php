<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $baseUrl = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->from((string) config('mail.from.address'), 'Identity')
            ->subject('Reset your password')
            ->view('emails.identity-password-reset', [
                'applicationName' => 'Identity',
                'resetUrl' => "{$baseUrl}/auth/reset-password?{$query}",
            ])
            ->text('emails.identity-password-reset-text', [
                'applicationName' => 'Identity',
                'resetUrl' => "{$baseUrl}/auth/reset-password?{$query}",
                'token' => $this->token,
            ]);
    }
}
