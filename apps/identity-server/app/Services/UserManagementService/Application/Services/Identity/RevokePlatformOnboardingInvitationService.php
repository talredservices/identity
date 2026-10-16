<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Services\Identity;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\IssuePlatformOnboardingInvitation;
use App\Services\UserManagementService\Application\DTOs\Input\RevokePlatformOnboardingInvitationDTO;
use App\Services\UserManagementService\Application\DTOs\Output\PlatformOnboardingInvitationRevocationResponseDTO;
use Zolta\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
final readonly class RevokePlatformOnboardingInvitationService
{
    public function __construct(private IssuePlatformOnboardingInvitation $invitations) {}

    public function __invoke(RevokePlatformOnboardingInvitationDTO $input): PlatformOnboardingInvitationRevocationResponseDTO
    {
        $this->invitations->revoke($input->client_id, $input->client_secret, $input->invitation_token);

        return new PlatformOnboardingInvitationRevocationResponseDTO;
    }
}
