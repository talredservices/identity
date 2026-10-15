<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ResetIdentityPassword extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly ?string $clientId = null,
        private readonly ?string $authPageSet = null,
        private readonly ?string $applicationName = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = method_exists($notifiable, 'getEmailForPasswordReset')
            ? (string) $notifiable->getEmailForPasswordReset()
            : (string) ($notifiable->routeNotificationFor('mail') ?? '');
        $resetUrl = (string) config('zolta.identity.password_reset_url');
        if ($resetUrl !== '') {
            $resetUrl = $this->withHostedAuthPageSet($resetUrl);
            $separator = str_contains($resetUrl, '?') ? '&' : '?';
            $resetUrl .= $separator.http_build_query(array_filter([
                'email' => $email,
                'token' => $this->token,
                'client_id' => $this->clientId,
            ]));
        }

        return (new MailMessage)
            ->from((string) config('mail.from.address'), $this->applicationName ?: 'Identity')
            ->subject('Reset your password')
            ->view('emails.identity-password-reset', [
                'applicationName' => $this->applicationName ?: 'your account',
                'resetUrl' => $resetUrl,
            ])
            ->text('emails.identity-password-reset-text', [
                'applicationName' => $this->applicationName ?: 'your account',
                'resetUrl' => $resetUrl,
                'token' => $this->token,
            ]);
    }

    private function withHostedAuthPageSet(string $resetUrl): string
    {
        $pageSet = $this->authPageSet ?? 'default';
        $enabledPageSets = config('zolta.identity.hosted_applications.page_sets', ['default']);

        if ($pageSet === 'default'
            || ! is_array($enabledPageSets)
            || ! in_array($pageSet, $enabledPageSets, true)
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $pageSet) !== 1) {
            return $resetUrl;
        }

        return (string) preg_replace(
            '~\/auth\/reset-password(?=$|[?#])~',
            '/auth/'.rawurlencode($pageSet).'/reset-password',
            $resetUrl,
            1,
        );
    }
}
