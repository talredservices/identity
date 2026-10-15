<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts\Identity\Authentication;

interface ManageIdentityGoogleAuthorization
{
    /** @param array<string, mixed> $input @return array{redirect_url: string} */
    public function start(array $input): array;

    /** @param array<string, mixed> $input @return array{redirect_url: string} */
    public function complete(array $input): array;
}
