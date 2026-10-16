<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\DTOs\Input;

use Zolta\Support\Application\DTO\Input\InputDTO;

final class RevokePlatformOnboardingInvitationDTO extends InputDTO
{
    public function __construct(
        public readonly string $client_id,
        public readonly string $client_secret,
        public readonly string $invitation_token,
    ) {}
}
