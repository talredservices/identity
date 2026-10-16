<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityAccountSecurity;

use Zolta\Cqrs\Commands\Command;

final class ExecuteIdentityAccountSecurityCommand extends Command
{
    /** @param array<string, mixed> $input */
    public function __construct(public readonly string $operation, public readonly string $accessToken, public readonly array $input) {}
}
