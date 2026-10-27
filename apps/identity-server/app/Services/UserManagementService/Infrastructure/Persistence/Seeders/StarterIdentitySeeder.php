<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Persistence\Seeders;

use App\Services\UserManagementService\Domain\Aggregates\IdentityMembership as DomainIdentityMembership;
use App\Services\UserManagementService\Domain\Enums\IdentityMembershipStatus;
use App\Services\UserManagementService\Domain\Repositories\IdentityMembershipRepository;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityRoleId;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Zolta\Domain\ValueObjects\UserId;

final class StarterIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $required = static function (string $name): string {
            $value = trim((string) getenv($name));
            if ($value === '') {
                throw new RuntimeException("Missing required environment variable: {$name}");
            }

            return $value;
        };

        $client = static function (IdentityProject $project, string $name, string $id, string $secret): IdentityProjectClient {
            if (! Str::isUuid($id) || strlen($secret) < 32) {
                throw new RuntimeException("Invalid credentials for {$name}.");
            }

            $clientById = IdentityProjectClient::query()->find($id);
            if ($clientById !== null && (string) $clientById->project_id !== (string) $project->id) {
                throw new RuntimeException("Configured client ID for {$name} belongs to another project.");
            }

            $clientByName = IdentityProjectClient::query()
                ->where('project_id', $project->id)
                ->where('name', $name)
                ->first();

            if ($clientById !== null && $clientByName !== null && (string) $clientById->id !== (string) $clientByName->id) {
                throw new RuntimeException("Configured client ID and name for {$name} resolve to different records.");
            }

            $client = $clientById ?? $clientByName ?? new IdentityProjectClient;

            if (! $client->exists) {
                $client->id = $id;
                $client->project_id = $project->id;
            }

            $client->forceFill([
                'name' => $name,
                'secret_hash' => hash('sha256', $secret),
                'secret_prefix' => substr($secret, 0, 8),
                'status' => 'active',
            ])->save();

            return $client;
        };

        $bootstrapEmail = $required('IDENTITY_BOOTSTRAP_EMAIL');
        $sharedIdentity = getenv('STARTER_SHARED_IDENTITY') === 'true';
        $bootstrapPassword = $required($sharedIdentity ? 'IDENTITY_BOOTSTRAP_PASSWORD' : 'STARTER_IDENTITY_BOOTSTRAP_PASSWORD');
        $hostedApplicationsConfigPath = $required('STARTER_HOSTED_APPLICATIONS_CONFIG');
        if (! is_readable($hostedApplicationsConfigPath)) {
            throw new RuntimeException('Starter hosted-auth configuration is not readable.');
        }
        $hostedApplicationsConfig = json_decode(
            (string) file_get_contents($hostedApplicationsConfigPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (($hostedApplicationsConfig['version'] ?? null) !== 1 || ! is_array($hostedApplicationsConfig['applications'] ?? null)) {
            throw new RuntimeException('Starter hosted-auth configuration has an unsupported format.');
        }

        $owner = User::query()->where('email', $bootstrapEmail)->first()
            ?? User::query()->where('is_system_admin', true)->firstOrFail();
        if (! $sharedIdentity) {
            $owner->forceFill([
                'email_verified_at' => $owner->email_verified_at ?: now(),
                'is_system_admin' => true,
            ])->save();

            $consoleProject = IdentityProject::query()->where('slug', 'identity-console')->firstOrFail();
            $consoleAccount = IdentityProjectAccount::query()->firstOrNew([
                'project_id' => $consoleProject->id,
                'user_id' => $owner->id,
            ]);
            $consoleAccountAttributes = [
                'username' => $owner->username,
                'email_verified_at' => now(),
                'status' => 'active',
            ];
            if (! $consoleAccount->exists) {
                $consoleAccountAttributes['password'] = $bootstrapPassword;
                $consoleAccountAttributes['password_changed_at'] = now();
            }
            $consoleAccount->forceFill($consoleAccountAttributes)->save();

            $consoleClient = IdentityProjectClient::query()
                ->where('project_id', $consoleProject->id)
                ->where('name', 'Identity Console BFF')
                ->firstOrFail();
            $consoleSecret = $required('STARTER_IDENTITY_CONSOLE_CLIENT_SECRET');
            $consoleClient->forceFill([
                'secret_hash' => hash('sha256', $consoleSecret),
                'secret_prefix' => substr($consoleSecret, 0, 8),
                'status' => 'active',
            ])->save();

        }

        $memberships = app(IdentityMembershipRepository::class);

        $sync = static function (array $definition) use ($required, $client, $owner, $bootstrapPassword, $memberships, $hostedApplicationsConfig): void {
            $projectSlug = $required($definition['projectEnv']);
            $projectName = $required($definition['projectNameEnv']);
            $applicationName = isset($definition['applicationNameEnv'])
                ? $required($definition['applicationNameEnv'])
                : $projectName;
            $applicationKey = $required($definition['applicationEnv']);
            $hostedPresentation = $hostedApplicationsConfig['applications'][$applicationKey] ?? null;
            $pageSet = is_array($hostedPresentation) ? ($hostedPresentation['page_set'] ?? null) : null;
            if (! is_string($pageSet) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $pageSet) !== 1) {
                throw new RuntimeException("Starter hosted-auth configuration is missing a valid page set for {$applicationKey}.");
            }
            $policyRevision = $hostedPresentation['policy_revision'] ?? null;
            if ($policyRevision !== null && (! is_string($policyRevision) || trim($policyRevision) === '' || strlen($policyRevision) > 128)) {
                throw new RuntimeException("Starter hosted-auth configuration has an invalid policy revision for {$applicationKey}.");
            }
            $appearance = is_array($hostedPresentation['appearance'] ?? null) ? $hostedPresentation['appearance'] : [];
            $publicUrl = $required($definition['urlEnv']);
            $callbackUrl = $required($definition['callbackEnv']);
            $bffId = $required($definition['bffIdEnv']);
            $bffSecret = $required($definition['bffSecretEnv']);
            $apiId = isset($definition['apiIdEnv']) ? $required($definition['apiIdEnv']) : null;
            $apiSecret = isset($definition['apiSecretEnv']) ? $required($definition['apiSecretEnv']) : null;
            DB::transaction(function () use ($projectSlug, $projectName, $applicationName, $applicationKey, $publicUrl, $callbackUrl, $bffId, $bffSecret, $apiId, $apiSecret, $pageSet, $policyRevision, $appearance, $definition, $client, $owner, $bootstrapPassword, $memberships): void {
                $project = IdentityProject::query()->firstOrCreate(
                    ['slug' => $projectSlug],
                    ['name' => $projectName, 'description' => $projectName.' product identity boundary'],
                );

                $bff = $client($project, $definition['bffName'], $bffId, $bffSecret);
                $api = $apiId !== null && $apiSecret !== null
                    ? $client($project, $definition['apiName'], $apiId, $apiSecret)
                    : null;

                $permissions = [];
                foreach ([
                    'account.read' => 'View own account',
                    'account.update' => 'Update own account',
                    'project.users.manage' => 'Manage project users',
                ] as $key => $name) {
                    $permission = IdentityProjectPermission::query()
                        ->where('project_id', $project->id)
                        ->where('key', $key)
                        ->firstOrNew();
                    $permission->forceFill([
                        'project_id' => $project->id,
                        'source_client_id' => $api?->id ?? $bff->id,
                        'key' => $key,
                        'name' => $name,
                        'description' => $name,
                        'source' => 'manifest',
                        'status' => 'active',
                    ])->save();
                    $permissions[$key] = $permission;
                }

                $member = IdentityProjectRole::query()
                    ->where('project_id', $project->id)
                    ->where('slug', 'member')
                    ->firstOrNew();
                $member->forceFill([
                    'project_id' => $project->id,
                    'name' => 'Member',
                    'slug' => 'member',
                    'description' => $projectName.' member',
                ])->save();
                $member->permissions()->sync(array_map(
                    static fn (IdentityProjectPermission $permission): string => (string) $permission->id,
                    array_values($permissions),
                ));

                $project->forceFill([
                    'name' => $projectName,
                    'description' => $projectName.' product identity boundary',
                    'status' => 'active',
                    'mode' => 'live',
                    'sandbox_ttl_minutes' => 60,
                    // Registration belongs to each product definition. The Starter
                    // workspace is invitation-only; SaaS and Tasks stay public.
                    'registration_mode' => $definition['registrationMode'] ?? 'public',
                    'registration_role_id' => $member->id,
                    'email_verification_required' => $project->exists ? (bool) $project->email_verification_required : false,
                    'google_social_authentication_enabled' => $project->exists ? (bool) $project->google_social_authentication_enabled : false,
                ])->save();

                $account = IdentityProjectAccount::query()->firstOrNew([
                    'project_id' => $project->id,
                    'user_id' => $owner->id,
                ]);
                $accountAttributes = [
                    'username' => $owner->username,
                    'email_verified_at' => now(),
                    'status' => 'active',
                ];
                if (! $account->exists) {
                    $accountAttributes['password'] = $bootstrapPassword;
                    $accountAttributes['password_changed_at'] = now();
                }
                $account->forceFill($accountAttributes)->save();

                $projectId = IdentityProjectId::fromString($project->id);
                $userId = new UserId((string) $owner->id);
                $roleId = IdentityRoleId::fromString($member->id);
                $membership = $memberships->findForProjectUser($projectId, $userId)
                    ?? DomainIdentityMembership::create($projectId, $userId, true, [$roleId]);
                $membership->updateAccess([$roleId], $membership->permissionIds(), true, IdentityMembershipStatus::Active);
                $memberships->save($membership);

                $application = IdentityHostedApplication::query()
                    ->where('key', $applicationKey)
                    ->firstOrNew();
                if ($application->exists && $application->project_id !== $project->id) {
                    throw new RuntimeException("Hosted application {$applicationKey} belongs to another project.");
                }
                $application->forceFill([
                    'project_id' => $project->id,
                    'primary_client_id' => $bff->id,
                    'key' => $applicationKey,
                    'name' => $applicationName,
                    'application_url' => $publicUrl,
                    'callback_url' => $callbackUrl,
                    'authentication_mode' => 'hosted',
                    'password_reset_url' => null,
                    'policy_revision' => $policyRevision ?? $application->policy_revision,
                    'auth_page_set' => $pageSet,
                    'appearance' => $appearance,
                    'authentication' => $application->authentication ?? [
                        'google_enabled' => false,
                        'terms_required' => false,
                        'terms_url' => null,
                        'privacy_url' => null,
                    ],
                    'status' => 'active',
                ])->save();
            });
        };

        $sync([
            'projectEnv' => 'STARTER_IDENTITY_PROJECT',
            'projectNameEnv' => 'STARTER_IDENTITY_PROJECT_NAME',
            'applicationEnv' => 'STARTER_IDENTITY_APPLICATION',
            'urlEnv' => 'STARTER_IDENTITY_PUBLIC_URL',
            'callbackEnv' => 'STARTER_IDENTITY_CALLBACK_URL',
            'bffIdEnv' => 'STARTER_IDENTITY_CLIENT_ID',
            'bffSecretEnv' => 'STARTER_IDENTITY_CLIENT_SECRET',
            'bffName' => 'Talred Starter Nuxt BFF',
            'registrationMode' => 'invite_only',
        ]);

        $sync([
            'projectEnv' => 'STARTER_SAAS_IDENTITY_PROJECT',
            'projectNameEnv' => 'STARTER_SAAS_IDENTITY_PROJECT_NAME',
            'applicationNameEnv' => 'STARTER_SAAS_IDENTITY_APPLICATION_NAME',
            'applicationEnv' => 'STARTER_SAAS_IDENTITY_APPLICATION',
            'urlEnv' => 'STARTER_SAAS_IDENTITY_PUBLIC_URL',
            'callbackEnv' => 'STARTER_SAAS_IDENTITY_CALLBACK_URL',
            'bffIdEnv' => 'STARTER_SAAS_IDENTITY_CLIENT_ID',
            'bffSecretEnv' => 'STARTER_SAAS_IDENTITY_CLIENT_SECRET',
            'bffName' => 'Talred SaaS Nuxt BFF',
            'registrationMode' => 'public',
        ]);

        $sync([
            'projectEnv' => 'STARTER_PROJECTS_IDENTITY_PROJECT',
            'projectNameEnv' => 'STARTER_PROJECTS_IDENTITY_PROJECT_NAME',
            'applicationEnv' => 'STARTER_PROJECTS_IDENTITY_APPLICATION',
            'urlEnv' => 'STARTER_PROJECTS_PUBLIC_URL',
            'callbackEnv' => 'STARTER_PROJECTS_CALLBACK_URL',
            'bffIdEnv' => 'STARTER_PROJECTS_IDENTITY_CLIENT_ID',
            'bffSecretEnv' => 'STARTER_PROJECTS_IDENTITY_CLIENT_SECRET',
            'apiIdEnv' => 'STARTER_PROJECTS_API_IDENTITY_CLIENT_ID',
            'apiSecretEnv' => 'STARTER_PROJECTS_API_IDENTITY_CLIENT_SECRET',
            'bffName' => 'Zoltasoft Tasks Nuxt BFF',
            'apiName' => 'Zoltasoft Tasks Laravel API',
            'registrationMode' => 'public',
        ]);

        fwrite(STDOUT, "Starter identity projects, clients, permissions, and hosted applications synchronized.\n");
    }
}
