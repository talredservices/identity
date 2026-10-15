<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts\Identity\Projects;

interface ManageIdentityClients
{
    /** @return array<string, mixed> */
    public function createClient(string $actorUserId, string $projectId, string $name): array;

    /** @return array<string, mixed> */
    public function rotateClientSecret(string $actorUserId, string $projectId, string $clientId): array;

    /** @return array<string, mixed> */
    public function renameClient(string $actorUserId, string $projectId, string $clientId, string $name): array;

    public function setClientStatus(string $actorUserId, string $projectId, string $clientId, string $status): void;

    public function deleteClient(string $actorUserId, string $projectId, string $clientId, string $confirmation): void;

    /** @param list<array{key: string, name?: string, description?: string}> $manifest @return list<array<string, mixed>> */
    public function syncPermissionManifest(string $actorUserId, string $projectId, string $clientId, array $manifest): array;
}
