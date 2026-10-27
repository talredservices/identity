<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts;

interface TemporaryAccountManagerInterface
{
    /**
     * @return array{email: string, password: string, expires_at: string}
     */
    public function provision(): array;

    public function deleteOnLogout(string $userId): void;

    public function purgeExpired(): int;
}
