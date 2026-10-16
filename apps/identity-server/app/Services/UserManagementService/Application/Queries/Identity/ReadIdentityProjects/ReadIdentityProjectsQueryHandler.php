<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Queries\Identity\ReadIdentityProjects;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ReadIdentityProjects;
use App\Services\UserManagementService\Application\Enums\Identity\IdentityProjectReadOperation;
use App\Services\UserManagementService\Application\Payloads\Identity\IdentityOperationPayload;
use Zolta\Cqrs\Attributes\HandlesQuery;
use Zolta\Cqrs\Services\Option;

#[HandlesQuery(ReadIdentityProjectsQuery::class)]
final readonly class ReadIdentityProjectsQueryHandler
{
    public function __construct(private ReadIdentityProjects $projects) {}

    public function __invoke(ReadIdentityProjectsQuery $query): Option
    {
        $result = match ($query->operation) {
            IdentityProjectReadOperation::ApplicationDirectory => $this->projects->listApplications($query->actorUserId),
            IdentityProjectReadOperation::Applications => $this->projects->projectDetails($query->actorUserId, (string) $query->input['project'])['hosted_applications'],
            IdentityProjectReadOperation::Index => $this->projects->listProjects($query->actorUserId),
            IdentityProjectReadOperation::Show => $this->projects->projectDetails(
                $query->actorUserId,
                (string) $query->input['project'],
            ),
            IdentityProjectReadOperation::Audit => $this->projects->listAuditEvents(
                $query->actorUserId,
                (string) $query->input['project'],
                (int) ($query->input['limit'] ?? 100),
            ),
        };

        return Option::some(new IdentityOperationPayload($result));
    }
}
