<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UserManagementService\Domain\Aggregates\IdentityWebhook;
use App\Services\UserManagementService\Domain\Enums\IdentityWebhookStatus;
use App\Services\UserManagementService\Domain\Exceptions\InvalidIdentityWebhookConfigurationException;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use PHPUnit\Framework\TestCase;

final class IdentityWebhookTest extends TestCase
{
    public function test_webhook_configuration_and_secret_change_through_domain_behavior(): void
    {
        $webhook = IdentityWebhook::create(
            new IdentityProjectId,
            'https://example-app.example.com/identity',
            ['identity.user.expired', 'identity.user.expired'],
            'initial-secret',
            'initial-',
        );

        $webhook->configure(
            'https://example-app.example.com/webhooks/identity',
            ['identity.user.deletion_requested'],
            IdentityWebhookStatus::Disabled,
        );
        $webhook->rotateSecret('rotated-secret', 'rotated-');

        $this->assertSame(
            'https://example-app.example.com/webhooks/identity',
            $webhook->url(),
        );
        $this->assertSame(['identity.user.deletion_requested'], $webhook->events());
        $this->assertSame(IdentityWebhookStatus::Disabled, $webhook->status());
        $this->assertSame('rotated-secret', $webhook->secret());
        $this->assertSame('rotated-', $webhook->secretPrefix());
    }

    public function test_webhook_requires_at_least_one_subscribed_event(): void
    {
        $this->expectException(InvalidIdentityWebhookConfigurationException::class);

        IdentityWebhook::create(
            new IdentityProjectId,
            'https://example-app.example.com/identity',
            [],
            'secret',
            'prefix',
        );
    }
}
