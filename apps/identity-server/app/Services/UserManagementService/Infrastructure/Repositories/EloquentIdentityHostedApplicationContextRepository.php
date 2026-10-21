<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Repositories;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ResolveIdentityHostedApplicationContext;
use App\Services\UserManagementService\Application\DTOs\Output\IdentityHostedApplicationContext;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;

final class EloquentIdentityHostedApplicationContextRepository implements ResolveIdentityHostedApplicationContext
{
    public function resolve(string $applicationKey, bool $sandbox): ?IdentityHostedApplicationContext
    {
        $application = IdentityHostedApplication::query()
            ->with(['primaryClient.project', 'sandboxClient.project'])
            ->where('key', $applicationKey)
            ->where('status', 'active')
            ->first();
        $relation = $sandbox ? 'sandboxClient' : 'primaryClient';
        $client = $application?->getRelation($relation);

        if ($application === null || ! $client instanceof IdentityProjectClient
            || $client->getAttribute('status') !== 'active'
            || $client->getRelation('project')?->getAttribute('status') !== 'active') {
            return null;
        }

        return new IdentityHostedApplicationContext(
            applicationId: (string) $application->getAttribute('id'),
            applicationKey: (string) $application->getAttribute('key'),
            clientId: (string) $client->getAttribute('id'),
            callbackUrl: (string) $application->getAttribute('callback_url'),
            policyRevision: $application->getAttribute('policy_revision') !== null
                ? (string) $application->getAttribute('policy_revision')
                : null,
            authentication: (array) $application->getAttribute('authentication'),
        );
    }
}
