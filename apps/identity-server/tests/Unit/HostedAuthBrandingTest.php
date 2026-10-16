<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Notifications\ResetIdentityPassword;
use Tests\TestCase;

final class HostedAuthBrandingTest extends TestCase
{
    public function test_hosted_password_reset_links_use_an_enabled_page_set(): void
    {
        config()->set('zolta.identity.password_reset_url', 'https://identity.example.test/auth/reset-password');
        config()->set('zolta.identity.hosted_applications.page_sets', ['default', 'example-product']);

        $message = (new ResetIdentityPassword(
            str_repeat('t', 64),
            'client-id',
            'example-product',
        ))->toMail(new User(['email' => 'person@example.test']));

        self::assertStringStartsWith(
            'https://identity.example.test/auth/example-product/reset-password?',
            (string) $message->viewData['resetUrl'],
        );
        self::assertStringContainsString('client_id=client-id', (string) $message->viewData['resetUrl']);
    }

    public function test_disabled_page_set_uses_the_generic_identity_reset_page(): void
    {
        config()->set('zolta.identity.password_reset_url', 'https://identity.example.test/auth/reset-password');
        config()->set('zolta.identity.hosted_applications.page_sets', ['default']);

        $message = (new ResetIdentityPassword(str_repeat('t', 64), 'client-id', 'example-product'))
            ->toMail(new User(['email' => 'person@example.test']));

        self::assertStringStartsWith('https://identity.example.test/auth/reset-password?', (string) $message->viewData['resetUrl']);
    }

    public function test_default_page_set_uses_the_generic_identity_reset_page(): void
    {
        config()->set('zolta.identity.password_reset_url', 'https://identity.example.test/auth/reset-password');

        $message = (new ResetIdentityPassword(
            str_repeat('t', 64),
            'client-id',
            'default',
        ))->toMail(new User(['email' => 'person@example.test']));

        self::assertStringStartsWith(
            'https://identity.example.test/auth/reset-password?',
            (string) $message->viewData['resetUrl'],
        );
    }
}
