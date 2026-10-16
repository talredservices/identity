<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityClient;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityClients;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityClientOperation;
use App\Services\UserManagementService\Application\Payloads\Identity\IdentityOperationPayload;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Services\Result;

#[HandlesCommand(ExecuteIdentityClientCommand::class)]
final readonly class ExecuteIdentityClientCommandHandler
{
    public function __construct(private ManageIdentityClients $clients) {}

    public function __invoke(ExecuteIdentityClientCommand $command): Result
    {
        $result = match ($command->operation) {
            IdentityClientOperation::Create => $this->clients->createClient(
                $command->actorUserId,
                $command->projectId,
                (string) $command->input['name'],
            ),
            IdentityClientOperation::RotateSecret => $this->clients->rotateClientSecret(
                $command->actorUserId,
                $command->projectId,
                (string) $command->input['client'],
            ),
            IdentityClientOperation::Rename => $this->clients->renameClient(
                $command->actorUserId,
                $command->projectId,
                (string) $command->input['client'],
                (string) $command->input['name'],
            ),
            IdentityClientOperation::SetStatus => $this->setStatus($command),
            IdentityClientOperation::Delete => $this->delete($command),
            IdentityClientOperation::SyncPermissionManifest => $this->clients->syncPermissionManifest(
                $command->actorUserId,
                $command->projectId,
                (string) $command->input['client'],
                (array) $command->input['permissions'],
            ),
        };

        return Result::success(new IdentityOperationPayload($result));
    }

    /** @return array{message: string} */
    private function setStatus(ExecuteIdentityClientCommand $command): array
    {
        $this->clients->setClientStatus(
            $command->actorUserId,
            $command->projectId,
            (string) $command->input['client'],
            (string) $command->input['status'],
        );

        return ['message' => 'Client status updated.'];
    }

    /** @return array{message: string} */
    private function delete(ExecuteIdentityClientCommand $command): array
    {
        $this->clients->deleteClient(
            $command->actorUserId,
            $command->projectId,
            (string) $command->input['client'],
            (string) $command->input['confirmation'],
        );

        return ['message' => 'Client deleted.'];
    }
}
