<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityAccess;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\IssueIdentityAccess;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityAccessOperation;
use App\Services\UserManagementService\Application\Payloads\Identity\IdentityOperationPayload;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Services\Result;

#[HandlesCommand(ExecuteIdentityAccessCommand::class)]
final readonly class ExecuteIdentityAccessCommandHandler
{
    public function __construct(private IssueIdentityAccess $access) {}

    public function __invoke(ExecuteIdentityAccessCommand $command): Result
    {
        $result = match ($command->operation) {
            IdentityAccessOperation::Login => $this->access->login(
                $command->input,
                $command->ipAddress,
                $command->userAgent,
            ),
            IdentityAccessOperation::Register => $this->access->register(
                $command->input,
                $command->ipAddress,
                $command->userAgent,
            ),
            IdentityAccessOperation::SocialLogin => $this->access->socialLogin(
                $command->input,
                $command->ipAddress,
                $command->userAgent,
            ),
            IdentityAccessOperation::SandboxSession => $this->access->createSandboxSession(
                (string) $command->input['client_id'],
                (string) $command->input['client_secret'],
                $command->ipAddress,
                $command->userAgent,
                $command->input['hosted_application_consent'] ?? null,
            ),
        };

        return Result::success(new IdentityOperationPayload($result));
    }
}
