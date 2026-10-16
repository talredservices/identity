<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Services\Identity;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityGoogleAuthorization;
use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use Zolta\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
final readonly class ExecuteIdentityGoogleAuthorizationService
{
    public function __construct(private ManageIdentityGoogleAuthorization $authorization) {}

    public function __invoke(IdentityOperationDTO $dto): array
    {
        return $dto->operation === 'auth.google.start' ? $this->authorization->start($dto->input + ['source_access_token' => $dto->accessToken]) : $this->authorization->complete($dto->input);
    }
}
