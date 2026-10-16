<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Identity\IssuePlatformOnboardingInvitationRequest;
use App\Services\UserManagementService\Application\DTOs\Input\IssuePlatformOnboardingInvitationDTO;
use App\Services\UserManagementService\Application\Services\Identity\IssuePlatformOnboardingInvitationService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Service;

#[Route('v1/identity/platform/onboarding/invitations', methods: ['POST'], middleware: ['api', 'throttle:20,1'], name: 'identity.platform.onboarding.invitations.store')]
#[Request(IssuePlatformOnboardingInvitationRequest::class, IssuePlatformOnboardingInvitationDTO::class)]
#[Service(IssuePlatformOnboardingInvitationService::class, 'Platform login invitation issued.', 201)]
final class IssuePlatformOnboardingInvitationController extends Controller {}
