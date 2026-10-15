<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Services\Identity;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\IssuePlatformOnboardingInvitation;
use App\Services\UserManagementService\Application\DTOs\Input\IssuePlatformOnboardingInvitationDTO;
use App\Services\UserManagementService\Application\DTOs\Output\PlatformOnboardingInvitationResponseDTO;
use Zolta\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
final readonly class IssuePlatformOnboardingInvitationService
{
    public function __construct(private IssuePlatformOnboardingInvitation $invitations) {}

    public function __invoke(IssuePlatformOnboardingInvitationDTO $input): PlatformOnboardingInvitationResponseDTO
    {
        $invitation = $this->invitations->issue($input->client_id, $input->client_secret, $input->email);

        return new PlatformOnboardingInvitationResponseDTO(
            $invitation['email'],
            $invitation['already_a_member'],
            $invitation['invitation_token'],
            $invitation['expires_at'],
        );
    }
}
