<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UserManagementService\Domain\Aggregates\IdentityClient;
use App\Services\UserManagementService\Domain\Enums\IdentityClientStatus;
use App\Services\UserManagementService\Domain\Exceptions\InvalidIdentityClientConfigurationException;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use PHPUnit\Framework\TestCase;

final class IdentityClientTest extends TestCase
{
    public function test_client_credentials_and_status_change_through_domain_behavior(): void
    {
        $client = IdentityClient::create(
            new IdentityProjectId,
            'Example Application BFF',
            hash('sha256', 'initial-secret'),
            'initial-',
        );
        $rotatedHash = hash('sha256', 'rotated-secret');

        $client->rotateCredentials($rotatedHash, 'rotated-');
        $client->changeStatus(IdentityClientStatus::Disabled);

        $this->assertSame($rotatedHash, $client->secretHash());
        $this->assertSame('rotated-', $client->secretPrefix());
        $this->assertSame(IdentityClientStatus::Disabled, $client->status());
    }

    public function test_client_rejects_malformed_credentials(): void
    {
        $this->expectException(InvalidIdentityClientConfigurationException::class);

        IdentityClient::create(new IdentityProjectId, 'Example Application BFF', 'not-a-hash', 'prefix');
    }
}
