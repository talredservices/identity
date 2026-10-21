<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Identity\IdentityAuthenticationOperationRequest;
use App\Services\UserManagementService\API\Services\CompleteIdentityGoogleCallback;
use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityAuthenticationService;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityGoogleAuthorizationService;
use App\Services\UserManagementService\Application\Services\Identity\ReadIdentityAuthenticationService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request as RequestAttribute;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Service;

final class IdentityAuthenticationController extends Controller
{
    #[Route('v1/identity/auth/reauthenticate', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:5,1'], name: 'identity.auth.reauthenticate')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Fresh authentication completed.')]
    public function reauthenticate(): void {}

    #[Route('v1/identity/auth/account/password', methods: ['PUT'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:5,1', 'identity.reauthenticate:password'], name: 'identity.auth.account.password')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Password changed.')]
    public function changePassword(): void {}

    #[Route('v1/identity/auth/google/authorize', methods: ['POST'], middleware: ['api', 'throttle:20,1'], name: 'identity.auth.google.start')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityGoogleAuthorizationService::class, 'Google authorization started.')]
    public function googleStart(): void {}

    #[Route('v1/identity/auth/google/callback', methods: ['GET'], middleware: ['api', 'throttle:60,1'], name: 'identity.auth.google.callback')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(CompleteIdentityGoogleCallback::class)]
    public function googleCallback(): void {}

    #[Route('v1/identity/auth/context', methods: ['POST'], middleware: ['api', 'throttle:120,1'], name: 'identity.auth.context')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityAuthenticationService::class, 'Authentication context resolved.')]
    public function context(): void {}

    #[Route('v1/identity/auth/login', methods: ['POST'], middleware: ['api', 'throttle:20,1'], name: 'identity.auth.login')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Login successful.')]
    public function login(): void {}

    #[Route('v1/identity/auth/register', methods: ['POST'], middleware: ['api', 'throttle:10,1'], name: 'identity.auth.register')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Registration successful.', 201)]
    public function register(): void {}

    #[Route('v1/identity/auth/sandbox-session', methods: ['POST'], middleware: ['api', 'throttle:identity-sandbox-session'], name: 'identity.auth.sandbox-session')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Sandbox session created.', 201)]
    public function sandboxSession(): void {}

    #[Route('v1/identity/auth/refresh', methods: ['POST'], middleware: ['api', 'throttle:60,1'], name: 'identity.auth.refresh')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Session refreshed.')]
    public function refresh(): void {}

    #[Route('v1/identity/auth/password/forgot', methods: ['POST'], middleware: ['api', 'throttle:5,1'], name: 'identity.auth.password.forgot')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Password reset requested.')]
    public function forgotPassword(): void {}

    #[Route('v1/identity/auth/password/reset', methods: ['POST'], middleware: ['api', 'throttle:10,1'], name: 'identity.auth.password.reset')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Password reset completed.')]
    public function resetPassword(): void {}

    #[Route('v1/identity/auth/handoff', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:30,1'], name: 'identity.auth.handoff.create')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Authorization handoff created.', 201)]
    public function createHandoff(): void {}

    #[Route('v1/identity/auth/authorization/intent', methods: ['POST'], middleware: ['api', 'throttle:identity-authorization-intent'], name: 'identity.auth.authorization.intent')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Hosted authorization intent created.', 201)]
    public function createAuthorizationIntent(): void {}

    #[Route('v1/identity/auth/account/intent', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:30,1'], name: 'identity.auth.account.intent.create')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Account portal intent created.', 201)]
    public function createAccountIntent(): void {}

    #[Route('v1/identity/auth/logout/intent', methods: ['POST'], middleware: ['api', 'throttle:identity-logout-intent'], name: 'identity.auth.logout.intent')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Logout intent created.', 201)]
    public function createLogoutIntent(): void {}

    #[Route('v1/identity/auth/handoff/exchange', methods: ['POST'], middleware: ['api', 'throttle:60,1'], name: 'identity.auth.handoff.exchange')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Authorization handoff exchanged.')]
    public function exchangeHandoff(): void {}

    #[Route('v1/identity/clients/permission-manifest', methods: ['PUT'], middleware: ['api', 'throttle:60,1'], name: 'identity.auth.manifest.sync')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Permission manifest synchronized.')]
    public function syncPermissionManifest(): void {}

    #[Route('v1/identity/invitations/accept', methods: ['POST'], middleware: ['api', 'throttle:20,1'], name: 'identity.auth.invitation.accept')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Invitation accepted.', 201)]
    public function acceptInvitation(): void {}

    #[Route('v1/identity/auth/me', methods: ['GET'], middleware: ['api', 'auth:sanctum', 'identity.token'], name: 'identity.auth.me')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityAuthenticationService::class, 'Current identity retrieved.')]
    public function me(): void {}

    #[Route('v1/identity/auth/sessions', methods: ['GET'], middleware: ['api', 'auth:sanctum', 'identity.token'], name: 'identity.auth.sessions.index')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityAuthenticationService::class, 'Sessions retrieved.')]
    public function sessions(): void {}

    #[Route('v1/identity/auth/sessions/{session}', methods: ['DELETE'], middleware: ['api', 'auth:sanctum', 'identity.token'], name: 'identity.auth.sessions.revoke')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Session revoked.')]
    public function revokeSession(): void {}

    #[Route('v1/identity/auth/logout', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token'], name: 'identity.auth.logout')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Session revoked.')]
    public function logout(): void {}

    #[Route('v1/identity/auth/email/verification/resend', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:5,1'], name: 'identity.auth.verification.resend')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Verification code sent.')]
    public function resendEmailVerification(): void {}

    #[Route('v1/identity/auth/email/verification', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:10,1'], name: 'identity.auth.verification.verify')]
    #[RequestAttribute(IdentityAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Email address verified.')]
    public function verifyEmail(): void {}
}
