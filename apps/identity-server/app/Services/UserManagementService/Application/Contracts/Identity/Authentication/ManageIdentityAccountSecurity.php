<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts\Identity\Authentication;

interface ManageIdentityAccountSecurity
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function reauthenticate(string $accessToken, array $input): array;

    /** Called only after Identity has verified the provider identity. @return array<string, mixed> */
    public function reauthenticateWithGoogle(string $accessToken, string $clientId, string $providerUserId, string $operation): array;

    public function consumeGrant(string $accessToken, string $grant, string $operation): void;

    public function changePassword(string $accessToken, string $password): void;
}
