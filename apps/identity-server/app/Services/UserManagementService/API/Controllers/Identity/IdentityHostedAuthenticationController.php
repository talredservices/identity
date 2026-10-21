<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Identity\IdentityHostedAuthenticationOperationRequest;
use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityAuthenticationService;
use App\Services\UserManagementService\Application\Services\Identity\ReadIdentityAuthenticationService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Service;

final class IdentityHostedAuthenticationController extends Controller
{
    private const MIDDLEWARE = ['api', 'identity.hosted-internal'];

    #[Route('v1/identity/hosted-applications/{application}/auth/context', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:120,1'], name: 'identity.hosted_applications.context')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityAuthenticationService::class, 'Authentication context resolved.')]
    public function context(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/login', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:identity-hosted-login'], name: 'identity.hosted_applications.login')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Login successful.')]
    public function login(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/register', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:identity-hosted-register'], name: 'identity.hosted_applications.register')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Registration successful.', 201)]
    public function register(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/social', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:identity-hosted-login'], name: 'identity.hosted_applications.social')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Social login successful.')]
    public function social(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/sandbox-session', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:identity-sandbox-session'], name: 'identity.hosted_applications.sandbox-session')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Sandbox session created.', 201)]
    public function sandboxSession(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/refresh', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:60,1'], name: 'identity.hosted_applications.refresh')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Session refreshed.')]
    public function refresh(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/password/forgot', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:identity-hosted-password-recovery'], name: 'identity.hosted_applications.password.forgot')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Password reset requested.')]
    public function forgotPassword(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/password/reset', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:10,1'], name: 'identity.hosted_applications.password.reset')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Password reset completed.')]
    public function resetPassword(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/handoff', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'auth:sanctum', 'identity.token', 'throttle:30,1'], name: 'identity.hosted_applications.handoff.create')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Authorization handoff created.', 201)]
    public function createHandoff(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/authorization/intent/consume', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:60,1'], name: 'identity.hosted_applications.authorization.intent.consume')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Hosted authorization intent consumed.')]
    public function consumeAuthorizationIntent(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/account/intent', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'auth:sanctum', 'identity.token', 'throttle:30,1'], name: 'identity.hosted_applications.account.intent.create')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Account portal intent created.', 201)]
    public function createAccountIntent(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/account/intent/consume', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:60,1'], name: 'identity.hosted_applications.account.intent.consume')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Account portal intent consumed.')]
    public function consumeAccountIntent(): void {}

    #[Route('v1/identity/hosted-applications/{application}/auth/logout/intent/consume', methods: ['POST'], middleware: [...self::MIDDLEWARE, 'throttle:60,1'], name: 'identity.hosted_applications.logout.intent.consume')]
    #[Request(IdentityHostedAuthenticationOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityAuthenticationService::class, 'Logout intent consumed.')]
    public function consumeLogoutIntent(): void {}
}
