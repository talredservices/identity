<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Contracts\Identity\Projects;

interface ReadIdentityProjects
{
    /** @return list<array<string, mixed>> */
    public function listProjects(string $actorUserId): array;

    /** @return list<array<string, mixed>> */
    public function listApplications(string $actorUserId): array;

    /** @return array<string, mixed> */
    public function projectDetails(string $actorUserId, string $projectId): array;

    /** @return list<array<string, mixed>> */
    public function listAuditEvents(string $actorUserId, string $projectId, int $limit = 100): array;
}
