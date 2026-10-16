<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Services\Identity;

use App\Services\UserManagementService\Application\Commands\Identity\AcceptIdentityInvitation\AcceptIdentityInvitationCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityAccess\ExecuteIdentityAccessCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityAccountSecurity\ExecuteIdentityAccountSecurityCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityHandoff\ExecuteIdentityHandoffCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityPasswordRecovery\ExecuteIdentityPasswordRecoveryCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentitySession\ExecuteIdentitySessionCommand;
use App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityVerification\ExecuteIdentityVerificationCommand;
use App\Services\UserManagementService\Application\Commands\Identity\SyncIdentityClientManifest\SyncIdentityClientManifestCommand;
use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityAccessOperation;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityHandoffOperation;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityPasswordRecoveryOperation;
use App\Services\UserManagementService\Application\Enums\Identity\IdentitySessionOperation;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityVerificationOperation;
use InvalidArgumentException;
use Zolta\Cqrs\Services\Pipeline\ApplicationService;
use Zolta\Support\Application\Attributes\AsApplicationService;

#[AsApplicationService]
final readonly class ExecuteIdentityAuthenticationService
{
    public function __construct(private ApplicationService $applicationService) {}

    public function __invoke(IdentityOperationDTO $dto): mixed
    {
        if (in_array($dto->operation, ['auth.reauthenticate', 'auth.account.password'], true)) {
            return $this->execute(ExecuteIdentityAccountSecurityCommand::class, [
                'operation' => $dto->operation,
                'accessToken' => $dto->accessToken ?? throw new InvalidArgumentException('An Identity access token is required.'),
                'input' => $dto->input,
            ]);
        }

        if (($operation = IdentityAccessOperation::tryFrom($dto->operation)) !== null) {
            return $this->execute(ExecuteIdentityAccessCommand::class, [
                'operation' => $operation,
                'input' => $dto->input,
                'ipAddress' => $dto->ipAddress,
                'userAgent' => $dto->userAgent,
            ]);
        }

        if (($operation = IdentityVerificationOperation::tryFrom($dto->operation)) !== null) {
            return $this->execute(ExecuteIdentityVerificationCommand::class, [
                'operation' => $operation,
                'input' => $dto->input,
                'actorUserId' => $dto->actorUserId
                    ?? throw new InvalidArgumentException(
                        'An authenticated Identity actor is required.',
                    ),
                'accessToken' => $dto->accessToken ?? throw new InvalidArgumentException('An Identity access token is required.'),
            ]);
        }

        if (($operation = IdentityHandoffOperation::tryFrom($dto->operation)) !== null) {
            return $this->execute(ExecuteIdentityHandoffCommand::class, [
                'operation' => $operation,
                'input' => $dto->input,
                'actorUserId' => $dto->actorUserId,
                'accessToken' => $dto->accessToken,
                'ipAddress' => $dto->ipAddress,
                'userAgent' => $dto->userAgent,
            ]);
        }

        if (($operation = IdentityPasswordRecoveryOperation::tryFrom($dto->operation)) !== null) {
            return $this->execute(ExecuteIdentityPasswordRecoveryCommand::class, [
                'operation' => $operation,
                'input' => $dto->input,
            ]);
        }

        if (($operation = IdentitySessionOperation::tryFrom($dto->operation)) !== null) {
            return $this->execute(ExecuteIdentitySessionCommand::class, [
                'operation' => $operation,
                'input' => $dto->input,
                'actorUserId' => $dto->actorUserId,
                'accessToken' => $dto->accessToken,
                'ipAddress' => $dto->ipAddress,
                'userAgent' => $dto->userAgent,
            ]);
        }

        if ($dto->operation === 'auth.invitation.accept') {
            return $this->execute(AcceptIdentityInvitationCommand::class, [
                'input' => $dto->input,
            ]);
        }

        if ($dto->operation === 'auth.manifest.sync') {
            return $this->execute(SyncIdentityClientManifestCommand::class, [
                'clientId' => (string) $dto->input['client_id'],
                'clientSecret' => (string) $dto->input['client_secret'],
                'permissions' => (array) $dto->input['permissions'],
            ]);
        }

        throw new InvalidArgumentException(
            "Unsupported Identity authentication operation [{$dto->operation}].",
        );
    }

    /** @param class-string $command @param array<string, mixed> $arguments */
    private function execute(string $command, array $arguments): mixed
    {
        ['result' => $result] = $this->applicationService
            ->runAndCapture($command, $arguments)
            ->getOrFail();

        return $result;
    }
}
