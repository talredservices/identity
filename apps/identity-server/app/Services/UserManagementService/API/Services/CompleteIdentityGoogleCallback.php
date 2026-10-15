<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Services;

use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityGoogleAuthorizationService;
use Illuminate\Http\RedirectResponse;

/** HTTP adapter: the application use case determines the approved destination. */
final readonly class CompleteIdentityGoogleCallback
{
    public function __construct(private ExecuteIdentityGoogleAuthorizationService $authorization) {}

    public function __invoke(IdentityOperationDTO $dto): RedirectResponse
    {
        $result = ($this->authorization)($dto);

        return redirect()->away($result['redirect_url'])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
