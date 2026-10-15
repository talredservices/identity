<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts\Identity\Projects;

interface IssuePlatformOnboardingInvitation
{
    /** @return array{email: string, already_a_member: bool, invitation_token: ?string, expires_at: ?string} */
    public function issue(string $clientId, string $clientSecret, string $email): array;

    public function revoke(string $clientId, string $clientSecret, string $invitationToken): void;
}
