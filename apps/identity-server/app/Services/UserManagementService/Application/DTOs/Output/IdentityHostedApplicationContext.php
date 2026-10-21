<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\DTOs\Output;

final readonly class IdentityHostedApplicationContext
{
    /** @param array<string, mixed> $authentication */
    public function __construct(
        public string $applicationId,
        public string $applicationKey,
        public string $clientId,
        public string $callbackUrl,
        public ?string $policyRevision,
        public array $authentication,
    ) {}
}
