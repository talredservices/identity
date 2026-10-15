<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\DTOs\Output;

use Zolta\Support\Application\DTO\Output\ResponseDTO;

final class PlatformOnboardingInvitationResponseDTO extends ResponseDTO
{
    public function __construct(
        public readonly string $email,
        public readonly bool $already_a_member,
        public readonly ?string $invitation_token,
        public readonly ?string $expires_at,
    ) {}
}
