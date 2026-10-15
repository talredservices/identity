<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Identity\RevokePlatformOnboardingInvitationRequest;
use App\Services\UserManagementService\Application\DTOs\Input\RevokePlatformOnboardingInvitationDTO;
use App\Services\UserManagementService\Application\Services\Identity\RevokePlatformOnboardingInvitationService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Doc;
use Zolta\Http\Service\Attributes\Service;

#[Route('v1/identity/platform/onboarding/invitations/revoke', methods: ['POST'], middleware: ['api', 'throttle:20,1'], name: 'identity.platform.onboarding.invitations.revoke')]
#[Request(RevokePlatformOnboardingInvitationRequest::class, RevokePlatformOnboardingInvitationDTO::class)]
#[Service(RevokePlatformOnboardingInvitationService::class, 'Platform login invitation revoked.')]
#[Doc(summary: 'Revoke a pending invitation to the fixed Platform login Identity Project', tags: ['Identity Platform Onboarding'])]
final class RevokePlatformOnboardingInvitationController extends Controller {}
