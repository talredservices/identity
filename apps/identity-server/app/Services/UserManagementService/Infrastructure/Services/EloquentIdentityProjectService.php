<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectEnvironment;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectRegistration;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\CreateIdentityProject;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityClients;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityHostedApplications;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectCatalog;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectDeletion;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectSuspension;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityWebhooks;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ReadIdentityProjects;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ResolveIdentityHostedApplications;
use App\Services\UserManagementService\Application\DTOs\External\UploadedAsset;
use App\Services\UserManagementService\Application\Exceptions\IdentityAccessDependencyException;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthorizationException;
use App\Services\UserManagementService\Application\Exceptions\IdentityProjectLifecycleException;
use App\Services\UserManagementService\Application\Exceptions\IdentityResourceNotFoundException;
use App\Services\UserManagementService\Domain\Aggregates\IdentityClient as DomainIdentityClient;
use App\Services\UserManagementService\Domain\Aggregates\IdentityMembership as DomainIdentityMembership;
use App\Services\UserManagementService\Domain\Aggregates\IdentityPermission as DomainIdentityPermission;
use App\Services\UserManagementService\Domain\Aggregates\IdentityProject as DomainIdentityProject;
use App\Services\UserManagementService\Domain\Aggregates\IdentityRole as DomainIdentityRole;
use App\Services\UserManagementService\Domain\Aggregates\IdentityWebhook as DomainIdentityWebhook;
use App\Services\UserManagementService\Domain\Enums\IdentityClientStatus;
use App\Services\UserManagementService\Domain\Enums\IdentityMembershipStatus;
use App\Services\UserManagementService\Domain\Enums\IdentityProjectMode;
use App\Services\UserManagementService\Domain\Enums\IdentityProjectRegistrationMode;
use App\Services\UserManagementService\Domain\Enums\IdentityWebhookStatus;
use App\Services\UserManagementService\Domain\Policies\IdentityAdministrationPolicy;
use App\Services\UserManagementService\Domain\Repositories\IdentityClientRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityMembershipRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityPermissionRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityProjectRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityRoleRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityWebhookRepository;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityClientId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityMembershipId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityPermissionId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityRoleId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityWebhookId;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAccessCatalogPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAccessCatalogRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAuditEvent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectInvitation;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectMembership;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityAuditEventRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectClientRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectMembershipRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectPermissionRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectRoleRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityWebhookEndpointRepository;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityAuditRecorder;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityAuthorizationGuard;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityPayloadFactory;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityPermissionManifestSynchronizer;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityTokenManager;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityWebhookDestinationValidator;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Zolta\Domain\ValueObjects\UserId;
use Zolta\Exceptions\ValidationException as ZoltaValidationException;

final readonly class EloquentIdentityProjectService implements ConfigureIdentityProjectEnvironment, ConfigureIdentityProjectRegistration, CreateIdentityProject, ManageIdentityClients, ManageIdentityHostedApplications, ManageIdentityProjectAccess, ManageIdentityProjectCatalog, ManageIdentityProjectDeletion, ManageIdentityProjectSuspension, ManageIdentityWebhooks, ReadIdentityProjects, ResolveIdentityHostedApplications
{
    public function __construct(
        private EloquentIdentityProjectMembershipRepository $memberships,
        private EloquentIdentityProjectRoleRepository $projectRoles,
        private EloquentIdentityProjectPermissionRepository $projectPermissions,
        private EloquentIdentityProjectClientRepository $projectClients,
        private EloquentIdentityWebhookEndpointRepository $webhooks,
        private EloquentIdentityAuditEventRepository $auditEvents,
        private IdentityProjectRepository $projects,
        private IdentityClientRepository $clientAggregates,
        private IdentityMembershipRepository $membershipAggregates,
        private IdentityPermissionRepository $permissionAggregates,
        private IdentityRoleRepository $roleAggregates,
        private IdentityWebhookRepository $webhookAggregates,
        private IdentityAdministrationPolicy $administrationPolicy,
        private IdentityAuthorizationGuard $authorization,
        private IdentityPermissionManifestSynchronizer $permissionManifests,
        private IdentityWebhookDestinationValidator $webhookDestinations,
        private IdentityTokenManager $tokens,
        private IdentityPayloadFactory $payloads,
        private IdentityAuditRecorder $audit,
    ) {}

    public function listProjects(string $actorUserId): array
    {
        $user = User::query()->findOrFail($actorUserId);
        $query = IdentityProject::query()->orderBy('name');

        if (! $user->is_system_admin) {
            $query->whereHas(
                'memberships',
                fn (Builder $builder) => $builder
                    ->where('user_id', $actorUserId)
                    ->where('status', 'active'),
            );
        }

        return $query->get()
            ->map(fn (IdentityProject $project) => $this->payloads->project($project))
            ->all();
    }

    public function listApplications(string $actorUserId): array
    {
        $user = User::query()->findOrFail($actorUserId);
        $query = IdentityHostedApplication::query()
            ->with('project:id,name,slug,status,mode')
            ->orderBy('name')
            ->orderBy('id');

        if (! $user->is_system_admin) {
            $query->whereHas('project.memberships', fn (Builder $builder) => $builder
                ->where('user_id', $actorUserId)
                ->where('status', 'active'));
        }

        return $query->get()
            ->map(fn (IdentityHostedApplication $application) => $this->payloads->hostedApplicationDirectoryItem($application))
            ->all();
    }

    public function createProject(string $actorUserId, array $attributes): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $user = User::query()->findOrFail($actorUserId);

        try {
            return DB::transaction(function () use ($user, $attributes): array {
                $project = DomainIdentityProject::create(
                    (string) $attributes['name'],
                    (string) $attributes['slug'],
                    isset($attributes['description']) ? (string) $attributes['description'] : null,
                );
                $this->projects->save($project);
                $projectId = $project->id()->toString();
                $membership = DomainIdentityMembership::create(
                    $project->id(),
                    new UserId((string) $user->id),
                    true,
                );
                $this->membershipAggregates->save($membership);
                $this->audit->record(
                    'project.created',
                    $projectId,
                    null,
                    $user->id,
                    'project',
                    $projectId,
                );

                return $this->payloads->projectAggregate($project);
            });
        } catch (DomainException|QueryException $exception) {
            if ($this->isDuplicateProjectSlug($exception)) {
                throw new ZoltaValidationException([
                    'slug' => 'The slug has already been taken.',
                ]);
            }

            throw $exception;
        }
    }

    private function isDuplicateProjectSlug(\Throwable $exception): bool
    {
        do {
            if ($exception instanceof QueryException) {
                $driverError = (int) ($exception->errorInfo[1] ?? 0);
                $message = $exception->getMessage();

                if (($driverError === 1062 && str_contains($message, 'identity_projects_slug_unique'))
                    || str_contains($message, 'identity_projects.slug')) {
                    return true;
                }
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof \Throwable);

        return false;
    }

    public function scheduleProjectDeletion(string $actorUserId, string $projectId, string $confirmation): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');

        if ($confirmation !== $project->slug()) {
            throw ValidationException::withMessages(['confirmation' => ['Type the exact project slug to confirm deletion.']]);
        }
        if (in_array($project->slug(), $this->protectedProjectSlugs(), true)) {
            throw new IdentityProjectLifecycleException('This protected project cannot be scheduled for deletion.');
        }
        if ($project->status()->value === 'pending_deletion') {
            return $this->payloads->projectAggregate($project);
        }

        $scheduledAt = (new DateTimeImmutable)->modify('+'.$this->projectDeletionGraceDays().' days');
        $project->scheduleDeletion($scheduledAt);

        DB::transaction(function () use ($project, $projectId, $actorUserId): void {
            $this->projects->save($project);
            DB::table('personal_access_tokens')->where('identity_project_id', $projectId)->delete();
            DB::table('identity_refresh_tokens')->where('project_id', $projectId)->delete();
            DB::table('identity_authorization_codes')->where('project_id', $projectId)->delete();
            $this->audit->record(
                'project.deletion_scheduled',
                $projectId,
                null,
                $actorUserId,
                'project',
                $projectId,
                ['deletion_scheduled_at' => $project->deletionScheduledAt()?->format(DATE_ATOM)],
            );
        });

        return $this->payloads->projectAggregate($project);
    }

    public function cancelProjectDeletion(string $actorUserId, string $projectId): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');
        if ($project->status()->value !== 'pending_deletion') {
            throw new IdentityProjectLifecycleException('This project is not scheduled for deletion.');
        }

        $project->cancelDeletion();
        DB::transaction(function () use ($project, $projectId, $actorUserId): void {
            $this->projects->save($project);
            $this->audit->record('project.deletion_cancelled', $projectId, null, $actorUserId, 'project', $projectId);
        });

        return $this->payloads->projectAggregate($project);
    }

    public function suspendProject(string $actorUserId, string $projectId, string $confirmation): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');

        if ($confirmation !== $project->slug()) {
            throw ValidationException::withMessages(['confirmation' => ['Type the exact project slug to confirm suspension.']]);
        }
        if (in_array($project->slug(), $this->protectedProjectSlugs(), true)) {
            throw new IdentityProjectLifecycleException('This protected project cannot be suspended.');
        }
        if ($project->status()->value === 'pending_deletion') {
            throw new IdentityProjectLifecycleException(
                'Project deletion is scheduled. Cancel deletion before suspending this project.',
            );
        }
        if (! $project->suspend()) {
            return $this->payloads->projectAggregate($project);
        }

        DB::transaction(function () use ($project, $projectId, $actorUserId): void {
            $this->projects->save($project);
            $this->tokens->revokeProject($projectId);
            DB::table('identity_authorization_codes')->where('project_id', $projectId)->delete();
            $this->audit->record('project.suspended', $projectId, null, $actorUserId, 'project', $projectId);
        });

        return $this->payloads->projectAggregate($project);
    }

    public function reactivateProject(string $actorUserId, string $projectId): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');

        if ($project->status()->value === 'pending_deletion') {
            throw new IdentityProjectLifecycleException(
                'Project deletion is scheduled. Cancel deletion before reactivating this project.',
            );
        }
        if (! $project->reactivate()) {
            return $this->payloads->projectAggregate($project);
        }

        DB::transaction(function () use ($project, $projectId, $actorUserId): void {
            $this->projects->save($project);
            $this->audit->record('project.reactivated', $projectId, null, $actorUserId, 'project', $projectId);
        });

        return $this->payloads->projectAggregate($project);
    }

    public function updateProjectRegistration(
        string $actorUserId,
        string $projectId,
        string $mode,
        ?string $roleId,
        bool $emailVerificationRequired,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);

        if ($roleId !== null && ! $this->projectRoles->existsForProject($projectId, $roleId)) {
            throw new IdentityAuthorizationException(
                'The default registration role must belong to this project.',
            );
        }

        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');
        $project->configureRegistration(
            IdentityProjectRegistrationMode::from($mode),
            $roleId,
            $emailVerificationRequired,
        );
        $this->projects->save($project);
        $this->audit->record(
            'project.registration_updated',
            $projectId,
            null,
            $actorUserId,
            'project',
            $projectId,
            [
                'registration_mode' => $mode,
                'registration_role_id' => $roleId,
                'email_verification_required' => $emailVerificationRequired,
            ],
        );
    }

    public function updateProjectEnvironment(
        string $actorUserId,
        string $projectId,
        string $mode,
        int $sandboxTtlMinutes,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $project = $this->projects->find(IdentityProjectId::fromString($projectId))
            ?? throw new IdentityResourceNotFoundException('Identity project');
        $project->configureEnvironment(IdentityProjectMode::from($mode), $sandboxTtlMinutes);
        $this->projects->save($project);
        $this->audit->record(
            'project.environment_updated',
            $projectId,
            null,
            $actorUserId,
            'project',
            $projectId,
            [
                'mode' => $mode,
                'sandbox_ttl_minutes' => $sandboxTtlMinutes,
            ],
        );
    }

    public function createWebhook(
        string $actorUserId,
        string $projectId,
        string $url,
        array $events,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $this->webhookDestinations->assertValid($url);
        $secret = Str::random(64);
        $webhook = DomainIdentityWebhook::create(
            IdentityProjectId::fromString($projectId),
            $url,
            $events,
            $secret,
            Str::substr($secret, 0, 8),
        );
        $this->webhookAggregates->save($webhook);
        $webhookId = $webhook->id()->toString();
        $this->audit->record(
            'webhook.created',
            $projectId,
            null,
            $actorUserId,
            'webhook',
            $webhookId,
        );

        return $this->payloads->webhookAggregate($webhook) + ['secret' => $secret];
    }

    public function updateWebhook(
        string $actorUserId,
        string $projectId,
        string $webhookId,
        string $url,
        array $events,
        string $status,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $this->webhookDestinations->assertValid($url);
        $webhook = $this->findWebhook($projectId, $webhookId);
        $webhook->configure($url, $events, IdentityWebhookStatus::from($status));
        $this->webhookAggregates->save($webhook);
        $this->audit->record(
            'webhook.updated',
            $projectId,
            null,
            $actorUserId,
            'webhook',
            $webhookId,
        );
    }

    public function rotateWebhookSecret(
        string $actorUserId,
        string $projectId,
        string $webhookId,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $webhook = $this->findWebhook($projectId, $webhookId);
        $secret = Str::random(64);
        $webhook->rotateSecret($secret, Str::substr($secret, 0, 8));
        $this->webhookAggregates->save($webhook);
        $this->audit->record(
            'webhook.secret_rotated',
            $projectId,
            null,
            $actorUserId,
            'webhook',
            $webhookId,
        );

        return $this->payloads->webhookAggregate($webhook) + ['secret' => $secret];
    }

    public function removeWebhook(string $actorUserId, string $projectId, string $webhookId): void
    {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $webhook = $this->findWebhook($projectId, $webhookId);
        $this->webhookAggregates->remove($webhook);
        $this->audit->record(
            'webhook.deleted',
            $projectId,
            null,
            $actorUserId,
            'webhook',
            $webhookId,
        );
    }

    public function projectDetails(string $actorUserId, string $projectId): array
    {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId, false);
        $project = IdentityProject::query()->findOrFail($projectId);

        return $this->payloads->project($project) + [
            'clients' => $this->projectClients
                ->listForProject($projectId, sort: ['name'])
                ->map(fn ($client) => $this->payloads->client($client))
                ->all(),
            'memberships' => $this->memberships
                ->listForProject($projectId, ['user', 'roles', 'permissions'])
                ->map(fn ($membership) => $this->payloads->membership($membership))
                ->all(),
            'roles' => $this->projectRoles
                ->listForProject($projectId, ['permissions'], ['name'])
                ->map(fn ($role) => $this->payloads->role($role))
                ->all(),
            'permissions' => $this->projectPermissions
                ->listForProject($projectId, sort: ['key'])
                ->map(fn ($permission) => $this->payloads->permission($permission))
                ->all(),
            'webhooks' => $this->webhooks
                ->listForProject($projectId, sort: ['url'])
                ->map(fn ($endpoint) => $this->payloads->webhook($endpoint))
                ->all(),
            'hosted_applications' => IdentityHostedApplication::query()
                ->where('project_id', $projectId)
                ->orderBy('name')
                ->get()
                ->map(fn (IdentityHostedApplication $application) => $this->payloads->hostedApplication($application))
                ->all(),
        ];
    }

    public function createHostedApplication(
        string $actorUserId,
        string $projectId,
        array $attributes,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);

        return DB::transaction(function () use ($actorUserId, $attributes, $projectId): array {
            $this->validateHostedApplicationClients($actorUserId, $projectId, $attributes);
            $application = IdentityHostedApplication::query()->create([
                'project_id' => $projectId,
                'primary_client_id' => $attributes['primary_client_id'],
                'sandbox_client_id' => $attributes['sandbox_client_id'] ?? null,
                'key' => $attributes['key'],
                'name' => $attributes['name'],
                'application_url' => $attributes['application_url'],
                'callback_url' => $attributes['callback_url'],
                'authentication_mode' => $attributes['authentication_mode'] ?? 'hosted',
                'password_reset_url' => $attributes['password_reset_url'] ?? null,
                'policy_revision' => $attributes['policy_revision'] ?? null,
                'auth_page_set' => $attributes['auth_page_set'] ?? 'default',
                'appearance' => $this->hostedApplicationAppearance($attributes),
                'authentication' => $this->hostedApplicationAuthentication($attributes),
                'status' => 'active',
            ]);
            $this->audit->record(
                'hosted_application.created',
                $projectId,
                $application->primary_client_id,
                $actorUserId,
                'hosted_application',
                $application->id,
                ['key' => $application->key],
            );

            return $this->payloads->hostedApplication($application);
        });
    }

    public function updateHostedApplication(
        string $actorUserId,
        string $projectId,
        string $applicationId,
        array $attributes,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $application = $this->findHostedApplication($projectId, $applicationId);
        $attributes = array_replace([
            'authentication_mode' => $application->authentication_mode,
            'password_reset_url' => $application->password_reset_url,
            'policy_revision' => $application->policy_revision,
        ], $attributes);
        $this->validateHostedApplicationClients(
            $actorUserId,
            $projectId,
            array_replace(['authentication' => $application->authentication ?? []], $attributes),
        );
        $application->fill([
            'name' => $attributes['name'],
            'primary_client_id' => $attributes['primary_client_id'],
            'sandbox_client_id' => $attributes['sandbox_client_id'] ?? null,
            'application_url' => $attributes['application_url'],
            'callback_url' => $attributes['callback_url'],
            'authentication_mode' => $attributes['authentication_mode'],
            'password_reset_url' => $attributes['password_reset_url'],
            'policy_revision' => $attributes['policy_revision'],
            'auth_page_set' => $attributes['auth_page_set'] ?? $application->auth_page_set,
            'appearance' => array_key_exists('appearance', $attributes) ? $this->hostedApplicationAppearance($attributes) : $application->appearance,
            'authentication' => array_key_exists('authentication', $attributes) ? $this->hostedApplicationAuthentication($attributes) : $application->authentication,
            'status' => $attributes['status'],
        ])->save();
        $this->audit->record(
            'hosted_application.updated',
            $projectId,
            $application->primary_client_id,
            $actorUserId,
            'hosted_application',
            $application->id,
            ['key' => $application->key, 'status' => $application->status],
        );
    }

    public function removeHostedApplication(
        string $actorUserId,
        string $projectId,
        string $applicationId,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $application = $this->findHostedApplication($projectId, $applicationId);
        $this->audit->record(
            'hosted_application.deleted',
            $projectId,
            $application->primary_client_id,
            $actorUserId,
            'hosted_application',
            $application->id,
            ['key' => $application->key],
        );
        if ($application->logo_path !== null) {
            Storage::disk((string) config('zolta.identity.hosted_applications.branding_disk', 'public'))
                ->delete($application->logo_path);
        }
        $application->delete();
    }

    public function uploadHostedApplicationLogo(
        string $actorUserId,
        string $projectId,
        string $applicationId,
        UploadedAsset $logo,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $application = $this->findHostedApplication($projectId, $applicationId);
        $disk = (string) config('zolta.identity.hosted_applications.branding_disk', 'public');
        $extension = $logo->extension;
        $path = "identity/hosted-applications/{$application->id}/".Str::uuid().".{$extension}";

        $stream = fopen($logo->path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The uploaded logo could not be read.');
        }

        try {
            Storage::disk($disk)->put($path, $stream, ['visibility' => 'public']);
        } finally {
            fclose($stream);
        }

        $previousPath = $application->logo_path;
        $application->forceFill(['logo_path' => $path])->save();
        if ($previousPath !== null) {
            Storage::disk($disk)->delete($previousPath);
        }

        $this->audit->record(
            'hosted_application.logo_uploaded',
            $projectId,
            $application->primary_client_id,
            $actorUserId,
            'hosted_application',
            $application->id,
            ['key' => $application->key],
        );

        return $this->payloads->hostedApplication($application);
    }

    public function removeHostedApplicationLogo(
        string $actorUserId,
        string $projectId,
        string $applicationId,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $application = $this->findHostedApplication($projectId, $applicationId);
        $path = $application->logo_path;
        $application->forceFill(['logo_path' => null])->save();

        if ($path !== null) {
            Storage::disk((string) config('zolta.identity.hosted_applications.branding_disk', 'public'))->delete($path);
        }

        $this->audit->record(
            'hosted_application.logo_removed',
            $projectId,
            $application->primary_client_id,
            $actorUserId,
            'hosted_application',
            $application->id,
            ['key' => $application->key],
        );
    }

    public function resolveHostedApplication(string $key): array
    {
        return $this->resolvedHostedApplication(
            IdentityHostedApplication::query()
                ->where('key', $key)
                ->first(),
        );
    }

    public function resolveHostedApplicationByClient(string $clientId): array
    {
        return $this->resolvedHostedApplication(
            IdentityHostedApplication::query()
                ->where('primary_client_id', $clientId)
                ->first(),
        );
    }

    public function createClient(string $actorUserId, string $projectId, string $name): array
    {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $secret = Str::random(64);
        $client = DomainIdentityClient::create(
            IdentityProjectId::fromString($projectId),
            $name,
            hash('sha256', $secret),
            Str::substr($secret, 0, 8),
        );
        $this->clientAggregates->save($client);
        $clientId = $client->id()->toString();
        $this->audit->record(
            'client.created',
            $projectId,
            $clientId,
            $actorUserId,
            'client',
            $clientId,
        );

        return $this->payloads->clientAggregate($client) + ['client_secret' => $secret];
    }

    public function rotateClientSecret(
        string $actorUserId,
        string $projectId,
        string $clientId,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $client = $this->findClient($projectId, $clientId);
        $secret = Str::random(64);
        $client->rotateCredentials(
            hash('sha256', $secret),
            Str::substr($secret, 0, 8),
        );
        $this->clientAggregates->save($client);
        $this->audit->record(
            'client.secret_rotated',
            $projectId,
            $clientId,
            $actorUserId,
            'client',
            $clientId,
        );

        return $this->payloads->clientAggregate($client) + ['client_secret' => $secret];
    }

    public function renameClient(
        string $actorUserId,
        string $projectId,
        string $clientId,
        string $name,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $client = $this->findClient($projectId, $clientId);
        $client->changeName($name);
        $this->clientAggregates->save($client);
        $this->audit->record(
            'client.renamed',
            $projectId,
            $clientId,
            $actorUserId,
            'client',
            $clientId,
            ['name' => $client->name()],
        );

        return $this->payloads->clientAggregate($client);
    }

    public function setClientStatus(
        string $actorUserId,
        string $projectId,
        string $clientId,
        string $status,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $client = $this->findClient($projectId, $clientId);
        $clientStatus = IdentityClientStatus::from($status);
        if ($clientStatus !== IdentityClientStatus::Active
            && IdentityHostedApplication::query()
                ->where('sandbox_client_id', $clientId)
                ->where('status', 'active')
                ->get(['authentication'])
                ->contains(fn (IdentityHostedApplication $application): bool => (bool) ($application->authentication['demo_only'] ?? false))) {
            throw new IdentityAuthorizationException('This sandbox client is required by an active demo-only hosted application. Disable demo-only sign-in before disabling the client.');
        }

        $client->changeStatus($clientStatus);
        $this->clientAggregates->save($client);

        if ($clientStatus !== IdentityClientStatus::Active) {
            $this->tokens->revokeClient($clientId);
        }

        $this->audit->record(
            'client.status_updated',
            $projectId,
            $clientId,
            $actorUserId,
            'client',
            $clientId,
            ['status' => $status],
        );
    }

    public function deleteClient(
        string $actorUserId,
        string $projectId,
        string $clientId,
        string $confirmation,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $client = $this->findClient($projectId, $clientId);
        if ($confirmation !== $client->name()) {
            throw ValidationException::withMessages(['confirmation' => ['Type the exact client name to confirm deletion.']]);
        }

        $hostedApplications = IdentityHostedApplication::query()
            ->where('project_id', $projectId)
            ->where(static fn (Builder $query): Builder => $query
                ->where('primary_client_id', $clientId)
                ->orWhere('sandbox_client_id', $clientId))
            ->pluck('name')
            ->all();
        if ($hostedApplications !== []) {
            throw new IdentityProjectLifecycleException(
                'This client is used by hosted application(s): '.implode(', ', $hostedApplications).'.',
            );
        }

        DB::transaction(function () use ($actorUserId, $projectId, $clientId, $client): void {
            $this->tokens->revokeClient($clientId);
            foreach ($this->permissionAggregates->findForManifestClient(
                IdentityProjectId::fromString($projectId),
                IdentityClientId::fromString($clientId),
            ) as $permission) {
                $permission->convertToManual();
                $this->permissionAggregates->save($permission);
            }
            $this->audit->record(
                'client.deleted',
                $projectId,
                $clientId,
                $actorUserId,
                'client',
                $clientId,
                ['name' => $client->name(), 'secret_prefix' => $client->secretPrefix()],
            );
            $this->clientAggregates->remove($client);
        });
    }

    public function syncPermissionManifest(
        string $actorUserId,
        string $projectId,
        string $clientId,
        array $manifest,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $this->projectClients->findForProjectOrFail($projectId, $clientId);
        $permissions = $this->permissionManifests->sync($projectId, $clientId, $manifest);
        $this->audit->record(
            'permission_manifest.synced',
            $projectId,
            $clientId,
            $actorUserId,
            'client',
            $clientId,
            ['permission_count' => count($manifest)],
        );

        return $permissions;
    }

    public function catalog(string $actorUserId): array
    {
        return [
            'permissions' => IdentityAccessCatalogPermission::query()->where('status', 'active')->orderBy('key')->get()->map(fn (IdentityAccessCatalogPermission $permission) => $this->catalogPermissionPayload($permission))->all(),
            'roles' => IdentityAccessCatalogRole::query()->with('permissions')->where('status', 'active')->orderBy('name')->get()->map(fn (IdentityAccessCatalogRole $role) => $this->catalogRolePayload($role))->all(),
        ];
    }

    public function createCatalogPermission(string $actorUserId, array $attributes): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $permission = IdentityAccessCatalogPermission::query()->create([
            'key' => $attributes['key'], 'name' => $attributes['name'] ?? $attributes['key'],
            'description' => $attributes['description'] ?? null, 'status' => 'active', 'version' => 1,
        ]);
        $this->audit->record('access_catalog.permission_created', null, null, $actorUserId, 'access_catalog_permission', $permission->id);

        return $this->catalogPermissionPayload($permission);
    }

    public function createCatalogRole(string $actorUserId, array $attributes): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $permissionIds = array_values(array_unique((array) ($attributes['permission_ids'] ?? [])));
        $permissions = IdentityAccessCatalogPermission::query()->where('status', 'active')->whereIn('id', $permissionIds)->get();
        if ($permissions->count() !== count($permissionIds)) {
            throw new IdentityAuthorizationException('Every catalog permission must be active.');
        }
        $role = DB::transaction(function () use ($attributes, $permissionIds): IdentityAccessCatalogRole {
            $role = IdentityAccessCatalogRole::query()->create([
                'slug' => $attributes['slug'], 'name' => $attributes['name'], 'description' => $attributes['description'] ?? null,
                'status' => 'active', 'version' => 1,
            ]);
            $role->permissions()->sync($permissionIds);

            return $role->load('permissions');
        });
        $this->audit->record('access_catalog.role_created', null, null, $actorUserId, 'access_catalog_role', $role->id);

        return $this->catalogRolePayload($role);
    }

    public function importCatalogItems(string $actorUserId, string $projectId, array $permissionIds, array $roleIds): array
    {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $permissionIds = array_values(array_unique($permissionIds));
        $roles = IdentityAccessCatalogRole::query()->with('permissions')->where('status', 'active')->whereIn('id', array_values(array_unique($roleIds)))->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new IdentityResourceNotFoundException('Access catalog role');
        }
        $requiredPermissionIds = array_values(array_unique([...$permissionIds, ...$roles->flatMap(fn (IdentityAccessCatalogRole $role) => $role->permissions->pluck('id'))->all()]));
        $permissions = IdentityAccessCatalogPermission::query()->where('status', 'active')->whereIn('id', $requiredPermissionIds)->get()->keyBy('id');
        if ($permissions->count() !== count($requiredPermissionIds)) {
            throw new IdentityResourceNotFoundException('Access catalog permission');
        }

        return DB::transaction(function () use ($projectId, $permissions, $roles, $actorUserId): array {
            $projectPermissions = [];
            foreach ($permissions as $catalogPermission) {
                $projectPermission = IdentityProjectPermission::query()->where('project_id', $projectId)->where('key', $catalogPermission->key)->first();
                if ($projectPermission === null) {
                    $projectPermission = IdentityProjectPermission::query()->create([
                        'project_id' => $projectId, 'catalog_permission_id' => $catalogPermission->id, 'catalog_version' => $catalogPermission->version, 'catalog_origin' => 'imported',
                        'key' => $catalogPermission->key, 'name' => $catalogPermission->name, 'description' => $catalogPermission->description,
                        'source' => 'catalog', 'status' => 'active',
                    ]);
                }
                $projectPermissions[$catalogPermission->id] = $projectPermission;
            }
            $importedRoles = [];
            foreach ($roles as $catalogRole) {
                $role = IdentityProjectRole::query()->where('project_id', $projectId)->where('slug', $catalogRole->slug)->first();
                if ($role === null) {
                    $role = IdentityProjectRole::query()->create([
                        'project_id' => $projectId, 'catalog_role_id' => $catalogRole->id, 'catalog_version' => $catalogRole->version, 'catalog_origin' => 'imported',
                        'name' => $catalogRole->name, 'slug' => $catalogRole->slug, 'description' => $catalogRole->description,
                    ]);
                    $role->permissions()->sync($catalogRole->permissions->map(fn (IdentityAccessCatalogPermission $permission) => $projectPermissions[$permission->id]->id)->all());
                }
                $importedRoles[] = $role->id;
            }
            $this->membershipAggregates->incrementAuthorizationVersionForProject(IdentityProjectId::fromString($projectId));
            $this->audit->record('access_catalog.imported', $projectId, null, $actorUserId, 'project', $projectId, ['role_ids' => $importedRoles]);

            return ['permission_ids' => array_map(fn (IdentityProjectPermission $permission) => $permission->id, $projectPermissions), 'role_ids' => $importedRoles];
        });
    }

    public function publishProjectPermission(string $actorUserId, string $projectId, string $permissionId): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $permission = IdentityProjectPermission::query()->where('project_id', $projectId)->find($permissionId) ?? throw new IdentityResourceNotFoundException('Identity project permission');
        $catalog = IdentityAccessCatalogPermission::query()->where('key', $permission->key)->first();
        if ($catalog !== null && ($catalog->name !== $permission->name || $catalog->description !== $permission->description)) {
            throw new IdentityProjectLifecycleException('A catalog permission with this key already exists with different details.');
        }
        $catalog ??= IdentityAccessCatalogPermission::query()->create(['key' => $permission->key, 'name' => $permission->name, 'description' => $permission->description, 'status' => 'active', 'version' => 1]);
        $permission->forceFill(['catalog_permission_id' => $catalog->id, 'catalog_version' => $catalog->version, 'catalog_origin' => 'published'])->save();
        $this->audit->record('access_catalog.permission_published', $projectId, null, $actorUserId, 'permission', $permissionId);

        return $this->catalogPermissionPayload($catalog);
    }

    public function publishProjectRole(string $actorUserId, string $projectId, string $roleId): array
    {
        $this->authorization->assertInstallationAdministrator($actorUserId);
        $role = IdentityProjectRole::query()->with('permissions')->where('project_id', $projectId)->find($roleId) ?? throw new IdentityResourceNotFoundException('Identity project role');
        $catalogPermissions = $role->permissions->map(fn (IdentityProjectPermission $permission) => $this->publishProjectPermission($actorUserId, $projectId, $permission->id));
        $catalogPermissionIds = $catalogPermissions->pluck('id')->all();
        $catalog = IdentityAccessCatalogRole::query()->where('slug', $role->slug)->first();
        if ($catalog !== null && ($catalog->name !== $role->name || $catalog->description !== $role->description)) {
            throw new IdentityProjectLifecycleException('A catalog role with this slug already exists with different details.');
        }
        $catalog ??= IdentityAccessCatalogRole::query()->create(['slug' => $role->slug, 'name' => $role->name, 'description' => $role->description, 'status' => 'active', 'version' => 1]);
        $catalog->permissions()->sync($catalogPermissionIds);
        $role->forceFill(['catalog_role_id' => $catalog->id, 'catalog_version' => $catalog->version, 'catalog_origin' => 'published'])->save();
        $catalog->load('permissions');
        $this->audit->record('access_catalog.role_published', $projectId, null, $actorUserId, 'role', $roleId);

        return $this->catalogRolePayload($catalog);
    }

    public function createRole(string $actorUserId, string $projectId, array $attributes): array
    {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $role = DomainIdentityRole::create(
            IdentityProjectId::fromString($projectId),
            (string) $attributes['name'],
            (string) $attributes['slug'],
            isset($attributes['description']) ? (string) $attributes['description'] : null,
        );
        $this->roleAggregates->save($role);
        $roleId = $role->id()->toString();
        $this->audit->record('role.created', $projectId, null, $actorUserId, 'role', $roleId);

        return $this->payloads->roleAggregate($role);
    }

    public function deleteRole(
        string $actorUserId,
        string $projectId,
        string $roleId,
        string $confirmation,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);

        DB::transaction(function () use ($actorUserId, $projectId, $roleId, $confirmation): void {
            $project = IdentityProject::query()->whereKey($projectId)->lockForUpdate()->first()
                ?? throw new IdentityResourceNotFoundException('Identity project');
            $roleModel = IdentityProjectRole::query()
                ->where('project_id', $projectId)
                ->whereKey($roleId)
                ->lockForUpdate()
                ->first()
                ?? throw new IdentityResourceNotFoundException('Identity project role');

            if ($confirmation !== $roleModel->slug) {
                throw new ZoltaValidationException([
                    'confirmation' => ['Type the exact role slug to confirm deletion.'],
                ]);
            }

            $memberships = $roleModel->memberships()->with('user')->orderBy('identity_project_memberships.id')->get();
            $isRegistrationDefault = $project->registration_role_id === $roleId;
            if ($memberships->isNotEmpty() || $isRegistrationDefault) {
                throw new IdentityAccessDependencyException('role', $roleId, [
                    'memberships' => $memberships
                        ->map(fn (IdentityProjectMembership $membership): array => $this->membershipDependencyPayload($membership))
                        ->all(),
                    'roles' => [],
                    'registration_default' => $isRegistrationDefault,
                    'manifest_client' => null,
                ]);
            }

            $role = $this->findRole($projectId, $roleId);
            $this->audit->record(
                'role.deleted',
                $projectId,
                null,
                $actorUserId,
                'role',
                $roleId,
                [
                    'name' => $roleModel->name,
                    'slug' => $roleModel->slug,
                    'permission_ids' => $roleModel->permissions()->pluck('identity_project_permissions.id')->all(),
                    'catalog_role_id' => $roleModel->catalog_role_id,
                    'catalog_origin' => $roleModel->catalog_origin,
                ],
            );
            $this->roleAggregates->remove($role);
        });
    }

    public function createPermission(
        string $actorUserId,
        string $projectId,
        array $attributes,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $key = (string) $attributes['key'];
        $permission = DomainIdentityPermission::createManual(
            IdentityProjectId::fromString($projectId),
            $key,
            isset($attributes['name']) ? (string) $attributes['name'] : $key,
            isset($attributes['description']) ? (string) $attributes['description'] : null,
        );
        $this->permissionAggregates->save($permission);
        $this->membershipAggregates->incrementAuthorizationVersionForProject(
            IdentityProjectId::fromString($projectId),
        );
        $permissionId = $permission->id()->toString();
        $this->audit->record(
            'permission.created',
            $projectId,
            null,
            $actorUserId,
            'permission',
            $permissionId,
        );

        return $this->payloads->permissionAggregate($permission);
    }

    public function deletePermission(
        string $actorUserId,
        string $projectId,
        string $permissionId,
        string $confirmation,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);

        DB::transaction(function () use ($actorUserId, $projectId, $permissionId, $confirmation): void {
            IdentityProject::query()->whereKey($projectId)->lockForUpdate()->first()
                ?? throw new IdentityResourceNotFoundException('Identity project');
            $permissionModel = IdentityProjectPermission::query()
                ->where('project_id', $projectId)
                ->whereKey($permissionId)
                ->lockForUpdate()
                ->first()
                ?? throw new IdentityResourceNotFoundException('Identity project permission');

            if ($confirmation !== $permissionModel->key) {
                throw new ZoltaValidationException([
                    'confirmation' => ['Type the exact permission key to confirm deletion.'],
                ]);
            }

            $roles = $permissionModel->roles()->orderBy('identity_project_roles.name')->get();
            $memberships = $permissionModel->memberships()->with('user')->orderBy('identity_project_memberships.id')->get();
            $isManifestManaged = $permissionModel->source === 'manifest' && $permissionModel->status === 'active';
            $manifestClient = $isManifestManaged
                ? IdentityProjectClient::query()->where('project_id', $projectId)->find($permissionModel->source_client_id)
                : null;
            if ($roles->isNotEmpty() || $memberships->isNotEmpty() || $isManifestManaged) {
                throw new IdentityAccessDependencyException('permission', $permissionId, [
                    'memberships' => $memberships
                        ->map(fn (IdentityProjectMembership $membership): array => $this->membershipDependencyPayload($membership))
                        ->all(),
                    'roles' => $roles->map(static fn (IdentityProjectRole $role): array => [
                        'id' => (string) $role->id,
                        'name' => (string) $role->name,
                        'slug' => (string) $role->slug,
                    ])->all(),
                    'registration_default' => false,
                    'manifest_client' => ! $isManifestManaged ? null : [
                        'id' => (string) ($manifestClient?->id ?? $permissionModel->source_client_id),
                        'name' => (string) ($manifestClient?->name ?? 'Unknown client'),
                    ],
                ]);
            }

            $permission = $this->permissionAggregates->findForProject(
                IdentityProjectId::fromString($projectId),
                IdentityPermissionId::fromString($permissionId),
            ) ?? throw new IdentityResourceNotFoundException('Identity project permission');
            $this->audit->record(
                'permission.deleted',
                $projectId,
                null,
                $actorUserId,
                'permission',
                $permissionId,
                [
                    'key' => $permissionModel->key,
                    'name' => $permissionModel->name,
                    'source' => $permissionModel->source,
                    'status' => $permissionModel->status,
                    'catalog_permission_id' => $permissionModel->catalog_permission_id,
                    'catalog_origin' => $permissionModel->catalog_origin,
                ],
            );
            $this->permissionAggregates->remove($permission);
            $this->membershipAggregates->incrementAuthorizationVersionForProject(
                IdentityProjectId::fromString($projectId),
            );
        });
    }

    public function setRolePermissions(
        string $actorUserId,
        string $projectId,
        string $roleId,
        array $permissionIds,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $role = $this->findRole($projectId, $roleId);
        $validIds = $this->projectPermissions->existingIdsForProject($projectId, $permissionIds);

        if (count($validIds) !== count(array_unique($permissionIds))) {
            throw new IdentityAuthorizationException('Every permission must belong to the project.');
        }

        $role->assignPermissions(array_map(
            static fn (string $permissionId): IdentityPermissionId => IdentityPermissionId::fromString($permissionId),
            $validIds,
        ));
        $this->roleAggregates->save($role);
        $this->membershipAggregates->incrementAuthorizationVersionForProject(
            IdentityProjectId::fromString($projectId),
        );
        $this->audit->record(
            'role.permissions_updated',
            $projectId,
            null,
            $actorUserId,
            'role',
            $roleId,
        );
    }

    public function invite(
        string $actorUserId,
        string $projectId,
        string $hostedApplicationId,
        string $email,
        bool $isAdmin,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $email = Str::lower($email);
        $hostedApplication = IdentityHostedApplication::query()
            ->where('project_id', $projectId)
            ->find($hostedApplicationId);
        if ($hostedApplication === null) {
            throw new IdentityAuthorizationException('The hosted application must belong to the project.');
        }
        $existingUser = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($existingUser !== null && IdentityProjectAccount::query()->where('project_id', $projectId)->where('user_id', $existingUser->id)->exists()) {
            throw new IdentityAuthorizationException('This email already has an account in the project.');
        }
        IdentityProjectInvitation::query()
            ->where('project_id', $projectId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();
        $plainToken = Str::random(64);
        $invitation = IdentityProjectInvitation::query()->create([
            'project_id' => $projectId,
            'hosted_application_id' => $hostedApplicationId,
            'invited_by' => $actorUserId,
            'email' => $email,
            'token_hash' => hash('sha256', $plainToken),
            'is_admin' => $isAdmin,
            'expires_at' => now()->addHours((int) config('zolta.identity.invitation_ttl_hours', 72)),
        ]);
        $this->audit->record(
            'invitation.created',
            $projectId,
            null,
            $actorUserId,
            'invitation',
            $invitation->id,
        );

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'is_admin' => $invitation->is_admin,
            'hosted_application_id' => $invitation->hosted_application_id,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            ...((bool) config('zolta.identity.expose_development_tokens', false) ? ['invitation_token' => $plainToken] : []),
        ];
    }

    public function setMembershipAccess(
        string $actorUserId,
        string $projectId,
        string $membershipId,
        array $roleIds,
        array $permissionIds,
        bool $isAdmin,
        string $status,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $membership = $this->findMembership($projectId, $membershipId);
        $actor = User::query()->findOrFail($actorUserId);

        if (! $this->administrationPolicy->canUpdateMembership(
            $actorUserId,
            $membership->userId()->toString(),
            (bool) $actor->is_system_admin,
            $isAdmin,
            $status,
        )) {
            throw new IdentityAuthorizationException(
                'Project administrators cannot suspend or demote their own membership.',
            );
        }

        $roles = $this->projectRoles->existingIdsForProject($projectId, $roleIds);
        $permissions = $this->projectPermissions->existingIdsForProject($projectId, $permissionIds);
        if (count($roles) !== count(array_unique($roleIds))
            || count($permissions) !== count(array_unique($permissionIds))) {
            throw new IdentityAuthorizationException('Roles and permissions must belong to the project.');
        }

        $membership->updateAccess(
            array_map(
                static fn (string $roleId): IdentityRoleId => IdentityRoleId::fromString($roleId),
                $roles,
            ),
            array_map(
                static fn (string $permissionId): IdentityPermissionId => IdentityPermissionId::fromString($permissionId),
                $permissions,
            ),
            $isAdmin,
            IdentityMembershipStatus::from($status),
        );
        $this->membershipAggregates->save($membership);
        $this->audit->record(
            'membership.access_updated',
            $projectId,
            null,
            $actorUserId,
            'membership',
            $membershipId,
        );
    }

    public function removeMembership(
        string $actorUserId,
        string $projectId,
        string $membershipId,
    ): void {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId);
        $membership = $this->findMembership($projectId, $membershipId);

        if (! $this->administrationPolicy->canRemoveMembership(
            $actorUserId,
            $membership->userId()->toString(),
            $membership->isAdministrator(),
        )) {
            throw new IdentityAuthorizationException(
                'Project administrators cannot remove their own membership.',
            );
        }

        $userId = $membership->userId()->toString();
        $this->membershipAggregates->remove($membership);
        $this->tokens->revokeProjectUser($projectId, $userId);
        $this->audit->record(
            'membership.removed',
            $projectId,
            null,
            $actorUserId,
            'membership',
            $membershipId,
        );
    }

    public function listAuditEvents(
        string $actorUserId,
        string $projectId,
        int $limit = 100,
    ): array {
        $this->authorization->assertProjectAdministrator($actorUserId, $projectId, false);

        return $this->auditEvents
            ->listForProject(
                $projectId,
                sort: ['-created_at'],
                limit: min(max($limit, 1), 250),
            )
            ->map(fn (IdentityAuditEvent $event) => [
                'id' => $event->id,
                'event' => $event->event,
                'actor_user_id' => $event->actor_user_id,
                'client_id' => $event->client_id,
                'target_type' => $event->target_type,
                'target_id' => $event->target_id,
                'metadata' => $event->metadata ?? [],
                'ip_address' => $event->ip_address,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->all();
    }

    private function projectDeletionGraceDays(): int
    {
        $days = (int) config('zolta.identity.project_deletion_grace_days', 30);
        if ($days < 1 || $days > 365) {
            throw new IdentityProjectLifecycleException('Project deletion grace period must be between 1 and 365 days.');
        }

        return $days;
    }

    /** @return list<string> */
    private function protectedProjectSlugs(): array
    {
        return array_values(array_filter((array) config('zolta.identity.protected_project_slugs', [])));
    }

    /** @return array<string, mixed> */
    private function catalogPermissionPayload(IdentityAccessCatalogPermission $permission): array
    {
        return ['id' => $permission->id, 'key' => $permission->key, 'name' => $permission->name, 'description' => $permission->description, 'status' => $permission->status, 'version' => $permission->version];
    }

    /** @return array<string, mixed> */
    private function catalogRolePayload(IdentityAccessCatalogRole $role): array
    {
        return ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name, 'description' => $role->description, 'status' => $role->status, 'version' => $role->version, 'permission_ids' => $role->permissions()->pluck('identity_access_catalog_permissions.id')->all()];
    }

    /** @return array{id: string, user_id: string, label: string, status: string} */
    private function membershipDependencyPayload(IdentityProjectMembership $membership): array
    {
        $user = $membership->user;

        return [
            'id' => (string) $membership->id,
            'user_id' => (string) $membership->user_id,
            'label' => (string) ($user?->username ?: $user?->email ?: $membership->user_id),
            'status' => (string) $membership->status,
        ];
    }

    private function findClient(string $projectId, string $clientId): DomainIdentityClient
    {
        return $this->clientAggregates->findForProject(
            IdentityProjectId::fromString($projectId),
            IdentityClientId::fromString($clientId),
        ) ?? throw new IdentityResourceNotFoundException('Identity project client');
    }

    private function findHostedApplication(string $projectId, string $applicationId): IdentityHostedApplication
    {
        return IdentityHostedApplication::query()
            ->where('project_id', $projectId)
            ->find($applicationId)
            ?? throw new IdentityResourceNotFoundException('Identity hosted application');
    }

    /** @return array<string, mixed> */
    private function resolvedHostedApplication(?IdentityHostedApplication $application): array
    {
        if ($application === null || $application->status !== 'active') {
            throw new IdentityResourceNotFoundException('Identity hosted application');
        }

        $application->loadMissing(['project', 'primaryClient', 'sandboxClient.project']);
        $client = $application->primaryClient;
        if ($application->project === null
            || $application->project->status !== 'active'
            || $client === null
            || $client->status !== 'active') {
            throw new IdentityResourceNotFoundException('Identity hosted application');
        }

        return [
            'key' => $application->key,
            'name' => $application->name,
            'project' => [
                'name' => $application->project->name,
                'slug' => $application->project->slug,
            ],
            'application_url' => $application->application_url,
            'callback_url' => $application->callback_url,
            'auth_page_set' => $application->auth_page_set ?: 'default',
            'appearance' => $this->payloads->hostedApplication($application)['appearance'],
            'authentication' => $this->payloads->hostedApplication($application)['authentication'],
            'primary' => [
                'project' => $application->project->slug,
                'client_id' => $client->id,
            ],
            'sandbox' => $application->sandboxClient === null ? null : [
                'project' => $application->sandboxClient->project->slug,
                'client_id' => $application->sandboxClient->id,
            ],
        ];
    }

    /** @param array<string, mixed> $attributes @return array{brand_name: string|null, brand_badge: string|null, welcome_text: string|null, accent_color: string|null, background_preset: string, design_tokens: array<string, string>} */
    private function hostedApplicationAppearance(array $attributes): array
    {
        $appearance = is_array($attributes['appearance'] ?? null) ? $attributes['appearance'] : [];
        $designTokens = [];
        $colorKeys = [
            'accent',
            'light_background', 'light_background_muted', 'light_card', 'light_text', 'light_muted', 'light_border', 'light_input_border', 'light_status',
            'dark_background', 'dark_background_muted', 'dark_card', 'dark_text', 'dark_muted', 'dark_border', 'dark_input_border', 'dark_status',
            'primary_50', 'primary_100', 'primary_200', 'primary_300', 'primary_400', 'primary_500', 'primary_600', 'primary_700', 'primary_800', 'primary_900', 'primary_950',
        ];
        foreach ((array) ($appearance['design_tokens'] ?? []) as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            if ($key === 'font_family') {
                if (preg_match('/^[A-Za-z0-9 ,._-]+$/', $value) === 1) {
                    $designTokens[$key] = $value;
                }

                continue;
            }
            if (in_array($key, $colorKeys, true) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1) {
                $designTokens[$key] = $value;
            }
        }

        return [
            'brand_name' => isset($appearance['brand_name']) && $appearance['brand_name'] !== ''
                ? trim((string) $appearance['brand_name'])
                : null,
            'brand_badge' => isset($appearance['brand_badge']) && $appearance['brand_badge'] !== ''
                ? trim((string) $appearance['brand_badge'])
                : null,
            'welcome_text' => isset($appearance['welcome_text']) && $appearance['welcome_text'] !== ''
                ? (string) $appearance['welcome_text']
                : null,
            'accent_color' => isset($appearance['accent_color']) && $appearance['accent_color'] !== ''
                ? (string) $appearance['accent_color']
                : null,
            'background_preset' => (string) ($appearance['background_preset'] ?? 'identity'),
            'design_tokens' => $designTokens,
        ];
    }

    /** @param array<string, mixed> $attributes @return array{google_enabled: bool, demo_only: bool, terms_required: bool, terms_url: string|null, privacy_url: string|null} */
    private function hostedApplicationAuthentication(array $attributes): array
    {
        $authentication = is_array($attributes['authentication'] ?? null) ? $attributes['authentication'] : [];
        $termsRequired = (bool) ($authentication['terms_required'] ?? false);

        return [
            'google_enabled' => (bool) ($authentication['google_enabled'] ?? false),
            'demo_only' => (bool) ($authentication['demo_only'] ?? false),
            'terms_required' => $termsRequired,
            'terms_url' => $termsRequired && ! empty($authentication['terms_url']) ? (string) $authentication['terms_url'] : null,
            'privacy_url' => ! empty($authentication['privacy_url']) ? (string) $authentication['privacy_url'] : null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function validateHostedApplicationClients(
        string $actorUserId,
        string $projectId,
        array $attributes,
    ): void {
        $origin = static fn (string $url): array => [
            strtolower((string) parse_url($url, PHP_URL_SCHEME)),
            strtolower((string) parse_url($url, PHP_URL_HOST)),
            parse_url($url, PHP_URL_PORT) ?? (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80),
        ];
        foreach (['callback_url', 'password_reset_url'] as $endpoint) {
            $url = $attributes[$endpoint] ?? null;
            if ($url !== null && ($origin($url) !== $origin($attributes['application_url'])
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
                || parse_url($url, PHP_URL_FRAGMENT) !== null || parse_url($url, PHP_URL_QUERY) !== null)) {
                throw new IdentityAuthorizationException('Application endpoints must use the registered application origin without credentials or fragments.');
            }
        }
        if (($attributes['authentication_mode'] ?? 'hosted') === 'app' && empty($attributes['password_reset_url'])) {
            throw new IdentityAuthorizationException('App authentication requires a registered password recovery endpoint.');
        }

        if (($attributes['authentication_mode'] ?? 'hosted') === 'app'
            && ($attributes['authentication']['terms_required'] ?? false) && empty($attributes['policy_revision'])) {
            throw new IdentityAuthorizationException('Application terms require an approved policy revision.');
        }

        if (($attributes['authentication']['demo_only'] ?? false)
            && empty($attributes['sandbox_client_id'])) {
            throw new IdentityAuthorizationException('Demo-only sign-in requires a linked sandbox client.');
        }

        $primary = IdentityProjectClient::query()
            ->with('project')
            ->where('project_id', $projectId)
            ->find($attributes['primary_client_id']);
        if ($primary === null || $primary->status !== 'active' || $primary->project->mode === 'sandbox') {
            throw new IdentityAuthorizationException('The primary client must be active and belong to this non-sandbox project.');
        }

        $sandboxClientId = $attributes['sandbox_client_id'] ?? null;
        if ($sandboxClientId === null) {
            return;
        }

        $sandbox = IdentityProjectClient::query()->with('project')->find($sandboxClientId);
        if ($sandbox === null || $sandbox->status !== 'active' || $sandbox->project->mode !== 'sandbox') {
            throw new IdentityAuthorizationException('The sandbox client must be active and belong to a sandbox project.');
        }

        $this->authorization->assertProjectAdministrator($actorUserId, $sandbox->project_id);
    }

    private function findMembership(
        string $projectId,
        string $membershipId,
    ): DomainIdentityMembership {
        return $this->membershipAggregates->findForProject(
            IdentityProjectId::fromString($projectId),
            IdentityMembershipId::fromString($membershipId),
        ) ?? throw new IdentityResourceNotFoundException('Identity project membership');
    }

    private function findRole(string $projectId, string $roleId): DomainIdentityRole
    {
        return $this->roleAggregates->findForProject(
            IdentityProjectId::fromString($projectId),
            IdentityRoleId::fromString($roleId),
        ) ?? throw new IdentityResourceNotFoundException('Identity project role');
    }

    private function findWebhook(string $projectId, string $webhookId): DomainIdentityWebhook
    {
        return $this->webhookAggregates->findForProject(
            IdentityProjectId::fromString($projectId),
            IdentityWebhookId::fromString($webhookId),
        ) ?? throw new IdentityResourceNotFoundException('Identity webhook endpoint');
    }
}
