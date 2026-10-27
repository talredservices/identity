<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Persistence\Seeders;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookEndpoint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class JobTrackerIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $bffClientId = trim((string) getenv('JOB_TRACKER_IDENTITY_BFF_CLIENT_ID'));
        $bffClientSecret = trim((string) getenv('JOB_TRACKER_IDENTITY_BFF_CLIENT_SECRET'));
        $apiClientId = trim((string) getenv('JOB_TRACKER_IDENTITY_API_CLIENT_ID'));
        $apiClientSecret = trim((string) getenv('JOB_TRACKER_IDENTITY_API_CLIENT_SECRET'));
        $projectSlug = trim((string) (getenv('JOB_TRACKER_IDENTITY_PROJECT_SLUG') ?: 'job-tracker'));
        $projectName = trim((string) (getenv('JOB_TRACKER_IDENTITY_PROJECT_NAME') ?: 'Job Tracker'));
        $sandboxProjectSlug = trim((string) (getenv('JOB_TRACKER_IDENTITY_SANDBOX_PROJECT_SLUG') ?: 'job-tracker-sandbox'));
        $sandboxProjectName = trim((string) (getenv('JOB_TRACKER_IDENTITY_SANDBOX_PROJECT_NAME') ?: 'Job Tracker Demo Sandbox'));
        $sandboxBffClientId = trim((string) getenv('JOB_TRACKER_IDENTITY_SANDBOX_BFF_CLIENT_ID'));
        $sandboxBffClientSecret = trim((string) getenv('JOB_TRACKER_IDENTITY_SANDBOX_BFF_CLIENT_SECRET'));
        $sandboxApiClientId = trim((string) getenv('JOB_TRACKER_IDENTITY_SANDBOX_API_CLIENT_ID'));
        $sandboxApiClientSecret = trim((string) getenv('JOB_TRACKER_IDENTITY_SANDBOX_API_CLIENT_SECRET'));
        $sandboxWebhookSecret = trim((string) getenv('JOB_TRACKER_IDENTITY_SANDBOX_WEBHOOK_SECRET'));
        $sandboxWebhookUrl = trim((string) (getenv('JOB_TRACKER_IDENTITY_SANDBOX_WEBHOOK_URL') ?: 'http://job-tracker-api:8000/api/webhooks/identity'));
        $applicationKey = trim((string) (getenv('JOB_TRACKER_IDENTITY_HOSTED_APPLICATION') ?: 'job-tracker'));
        $hostedApplicationsConfigPath = trim((string) getenv('JOB_TRACKER_HOSTED_APPLICATIONS_CONFIG'));
        if ($hostedApplicationsConfigPath === '' || ! is_readable($hostedApplicationsConfigPath)) {
            throw new RuntimeException('Job Tracker hosted-auth configuration is not readable.');
        }
        $hostedApplicationsConfig = json_decode(
            (string) file_get_contents($hostedApplicationsConfigPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (($hostedApplicationsConfig['version'] ?? null) !== 1 || ! is_array($hostedApplicationsConfig['applications'] ?? null)) {
            throw new RuntimeException('Job Tracker hosted-auth configuration has an unsupported format.');
        }
        $hostedPresentation = $hostedApplicationsConfig['applications'][$applicationKey] ?? null;
        $pageSet = is_array($hostedPresentation) ? ($hostedPresentation['page_set'] ?? null) : null;
        if (! is_string($pageSet) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $pageSet) !== 1) {
            throw new RuntimeException("Job Tracker hosted-auth configuration is missing a valid page set for {$applicationKey}.");
        }
        $appearance = is_array($hostedPresentation['appearance'] ?? null) ? $hostedPresentation['appearance'] : [];
        $applicationUrl = trim((string) getenv('JOB_TRACKER_IDENTITY_APPLICATION_URL'));
        if ($applicationUrl === '') {
            $applicationUrl = 'http://localhost:3000';
        }
        $callbackUrl = trim((string) getenv('JOB_TRACKER_IDENTITY_CALLBACK_URL'));
        if ($callbackUrl === '') {
            $callbackUrl = $applicationUrl.'/api/identity/'.$applicationKey.'/auth/callback';
        }
        if ($sandboxProjectSlug === $projectSlug) {
            throw new RuntimeException('Job Tracker live and sandbox Identity projects must use different slugs.');
        }

        if (! Str::isUuid($bffClientId) || ! Str::isUuid($apiClientId)
            || ! Str::isUuid($sandboxBffClientId) || ! Str::isUuid($sandboxApiClientId)) {
            throw new RuntimeException('Job Tracker Identity client IDs must be UUIDs.');
        }

        if (count(array_unique([$bffClientId, $apiClientId, $sandboxBffClientId, $sandboxApiClientId])) !== 4) {
            throw new RuntimeException('Job Tracker Identity live and sandbox clients must have distinct IDs.');
        }

        if (min(strlen($bffClientSecret), strlen($apiClientSecret), strlen($sandboxBffClientSecret), strlen($sandboxApiClientSecret), strlen($sandboxWebhookSecret)) < 32) {
            throw new RuntimeException('Job Tracker Identity client and webhook secrets must be at least 32 characters.');
        }

        $client = static function (
            IdentityProject $project,
            string $name,
            string $id,
            string $secret,
        ): IdentityProjectClient {
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
            if ($clientById === null && $clientByName !== null) {
                throw new RuntimeException("Configured client ID for {$name} does not match the existing Identity client. Restore the matching runtime secret or reconcile the client explicitly.");
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

        DB::transaction(function () use ($client, $bffClientId, $bffClientSecret, $apiClientId, $apiClientSecret, $projectSlug, $projectName, $sandboxProjectSlug, $sandboxProjectName, $sandboxBffClientId, $sandboxBffClientSecret, $sandboxApiClientId, $sandboxApiClientSecret, $sandboxWebhookSecret, $sandboxWebhookUrl, $applicationKey, $applicationUrl, $callbackUrl, $pageSet, $appearance): void {
            $project = IdentityProject::query()->firstOrCreate(
                ['slug' => $projectSlug],
                ['name' => $projectName, 'description' => $projectName.' product identity boundary', 'mode' => 'live'],
            );
            if ($project->mode === 'sandbox') {
                throw new RuntimeException('The Job Tracker live project slug is already configured as a sandbox project.');
            }

            $bff = $client($project, 'Job Tracker Nuxt BFF', $bffClientId, $bffClientSecret);
            $api = $client($project, 'Job Tracker API', $apiClientId, $apiClientSecret);

            $sandboxProject = IdentityProject::query()->where('slug', $sandboxProjectSlug)->first();
            if ($sandboxProject !== null && $sandboxProject->mode !== 'sandbox') {
                throw new RuntimeException('The Job Tracker sandbox project slug is already in use by a non-sandbox project.');
            }
            $sandboxProject ??= IdentityProject::query()->create([
                'name' => $sandboxProjectName,
                'slug' => $sandboxProjectSlug,
                'description' => 'Temporary demo identities for the local Job Tracker environment.',
                'status' => 'active',
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 60,
                'registration_mode' => 'invite_only',
                'email_verification_required' => false,
                'google_social_authentication_enabled' => false,
            ]);

            $sandboxBff = $client($sandboxProject, 'Job Tracker Sandbox Nuxt BFF', $sandboxBffClientId, $sandboxBffClientSecret);
            $sandboxApi = $client($sandboxProject, 'Job Tracker Sandbox API', $sandboxApiClientId, $sandboxApiClientSecret);

            $permissions = [];
            foreach ([
                'jobs.read' => 'View jobs',
                'jobs.create' => 'Create jobs',
                'jobs.update' => 'Update jobs',
                'jobs.delete' => 'Delete jobs',
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
                    'source_client_id' => $api->id,
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
                'description' => 'Job Tracker member',
            ])->save();
            $member->permissions()->sync(array_map(
                static fn (IdentityProjectPermission $permission): string => (string) $permission->id,
                array_intersect_key($permissions, array_flip([
                    'jobs.read',
                    'jobs.create',
                    'jobs.update',
                    'jobs.delete',
                    'account.read',
                    'account.update',
                ])),
            ));

            $sandboxPermissions = [];
            foreach ([
                'jobs.read' => 'View jobs',
                'jobs.create' => 'Create jobs',
                'jobs.update' => 'Update jobs',
                'jobs.delete' => 'Delete jobs',
                'account.read' => 'View own account',
                'account.update' => 'Update own account',
                'project.users.manage' => 'Manage project users',
            ] as $key => $name) {
                $permission = IdentityProjectPermission::query()
                    ->where('project_id', $sandboxProject->id)
                    ->where('key', $key)
                    ->firstOrNew();
                $permission->forceFill([
                    'project_id' => $sandboxProject->id,
                    'source_client_id' => $sandboxApi->id,
                    'key' => $key,
                    'name' => $name,
                    'description' => $name,
                    'source' => 'manifest',
                    'status' => 'active',
                ])->save();
                $sandboxPermissions[$key] = $permission;
            }

            $sandboxMember = IdentityProjectRole::query()
                ->where('project_id', $sandboxProject->id)
                ->where('slug', 'member')
                ->firstOrNew();
            $sandboxMember->forceFill([
                'project_id' => $sandboxProject->id,
                'name' => 'Member',
                'slug' => 'member',
                'description' => 'Temporary Job Tracker demo member',
            ])->save();
            $sandboxMember->permissions()->sync(array_map(
                static fn (IdentityProjectPermission $permission): string => (string) $permission->id,
                array_intersect_key($sandboxPermissions, array_flip([
                    'jobs.read',
                    'jobs.create',
                    'jobs.update',
                    'jobs.delete',
                    'account.read',
                    'account.update',
                ])),
            ));

            $sandboxProject->forceFill([
                'name' => $sandboxProjectName,
                'description' => 'Temporary demo identities for the local Job Tracker environment.',
                'status' => 'active',
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 60,
                'registration_mode' => 'invite_only',
                'registration_role_id' => $sandboxMember->id,
                'email_verification_required' => false,
                'google_social_authentication_enabled' => false,
            ])->save();

            $webhook = IdentityWebhookEndpoint::query()
                ->where('project_id', $sandboxProject->id)
                ->where('url', $sandboxWebhookUrl)
                ->firstOrNew();
            $webhook->forceFill([
                'project_id' => $sandboxProject->id,
                'url' => $sandboxWebhookUrl,
                'events' => ['identity.user.expired', 'identity.user.deletion_requested'],
                'secret' => $sandboxWebhookSecret,
                'secret_prefix' => substr($sandboxWebhookSecret, 0, 8),
                'status' => 'active',
            ])->save();

            $project->forceFill([
                'name' => $projectName,
                'description' => $projectName.' product identity boundary',
                'status' => 'active',
                'mode' => 'live',
                'sandbox_ttl_minutes' => 60,
                'registration_mode' => 'public',
                'registration_role_id' => $member->id,
                'email_verification_required' => $project->exists ? (bool) $project->email_verification_required : false,
                'google_social_authentication_enabled' => $project->exists ? (bool) $project->google_social_authentication_enabled : false,
            ])->save();

            $application = IdentityHostedApplication::query()
                ->where('key', $applicationKey)
                ->firstOrNew();
            if ($application->exists && $application->project_id !== $project->id) {
                throw new RuntimeException('The job-tracker hosted application belongs to another project.');
            }
            $application->forceFill([
                'project_id' => $project->id,
                'primary_client_id' => $bff->id,
                'sandbox_client_id' => $sandboxBff->id,
                'key' => $applicationKey,
                'name' => $projectName,
                'application_url' => $applicationUrl,
                'callback_url' => $callbackUrl,
                'authentication_mode' => 'hosted',
                'password_reset_url' => null,
                'policy_revision' => $application->policy_revision,
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

        $nodaraBffClientId = trim((string) getenv('NODARA_IDENTITY_BFF_CLIENT_ID'));
        $nodaraBffClientSecret = trim((string) getenv('NODARA_IDENTITY_BFF_CLIENT_SECRET'));
        $nodaraSandboxBffClientId = trim((string) getenv('NODARA_IDENTITY_SANDBOX_BFF_CLIENT_ID'));
        $nodaraSandboxBffClientSecret = trim((string) getenv('NODARA_IDENTITY_SANDBOX_BFF_CLIENT_SECRET'));
        $nodaraApplicationUrl = rtrim(trim((string) (getenv('NODARA_IDENTITY_APPLICATION_URL') ?: 'http://localhost:3000')), '/');
        $nodaraCallbackUrl = trim((string) (getenv('NODARA_IDENTITY_CALLBACK_URL') ?: $nodaraApplicationUrl.'/api/identity/nodara/auth/callback'));
        $nodaraConfig = $hostedApplicationsConfig['applications']['nodara'] ?? null;
        $nodaraPageSet = is_array($nodaraConfig) ? ($nodaraConfig['page_set'] ?? null) : null;
        $nodaraAppearance = is_array($nodaraConfig['appearance'] ?? null) ? $nodaraConfig['appearance'] : [];
        if (! Str::isUuid($nodaraBffClientId) || ! Str::isUuid($nodaraSandboxBffClientId)
            || $nodaraBffClientId === $nodaraSandboxBffClientId
            || min(strlen($nodaraBffClientSecret), strlen($nodaraSandboxBffClientSecret)) < 32) {
            throw new RuntimeException('Nodara live and sandbox Identity BFF clients must have distinct UUIDs and strong secrets.');
        }
        if (! is_string($nodaraPageSet) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $nodaraPageSet) !== 1) {
            throw new RuntimeException('Nodara hosted-auth configuration is missing a valid page set.');
        }

        DB::transaction(function () use ($client, $nodaraBffClientId, $nodaraBffClientSecret, $nodaraSandboxBffClientId, $nodaraSandboxBffClientSecret, $nodaraApplicationUrl, $nodaraCallbackUrl, $nodaraPageSet, $nodaraAppearance): void {
            $project = IdentityProject::query()->firstOrCreate(
                ['slug' => 'nodara'],
                ['name' => 'Nodara', 'description' => 'Nodara product identity boundary', 'mode' => 'live'],
            );
            if ($project->mode === 'sandbox') {
                throw new RuntimeException('The Nodara live project slug is already configured as a sandbox project.');
            }
            $bff = $client($project, 'Nodara Nuxt BFF', $nodaraBffClientId, $nodaraBffClientSecret);

            $sandbox = IdentityProject::query()->where('slug', 'nodara-sandbox')->first();
            if ($sandbox !== null && $sandbox->mode !== 'sandbox') {
                throw new RuntimeException('The Nodara sandbox project slug is already in use by a non-sandbox project.');
            }
            $sandbox ??= IdentityProject::query()->create([
                'name' => 'Nodara Demo Sandbox',
                'slug' => 'nodara-sandbox',
                'description' => 'Temporary Nodara demo identities for the local Talred environment.',
                'status' => 'active',
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 60,
                'registration_mode' => 'invite_only',
                'email_verification_required' => false,
                'google_social_authentication_enabled' => false,
            ]);
            $sandboxBff = $client($sandbox, 'Nodara Sandbox Nuxt BFF', $nodaraSandboxBffClientId, $nodaraSandboxBffClientSecret);
            $member = IdentityProjectRole::query()->where('project_id', $sandbox->id)->where('slug', 'member')->firstOrNew();
            $member->forceFill([
                'project_id' => $sandbox->id,
                'name' => 'Member',
                'slug' => 'member',
                'description' => 'Temporary Nodara demo member',
            ])->save();
            $sandbox->forceFill([
                'name' => 'Nodara Demo Sandbox',
                'description' => 'Temporary Nodara demo identities for the local Talred environment.',
                'status' => 'active',
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 60,
                'registration_mode' => 'invite_only',
                'registration_role_id' => $member->id,
                'email_verification_required' => false,
                'google_social_authentication_enabled' => false,
            ])->save();

            $hosted = IdentityHostedApplication::query()->where('key', 'nodara')->firstOrNew();
            if ($hosted->exists && (string) $hosted->project_id !== (string) $project->id) {
                throw new RuntimeException('The Nodara hosted application belongs to another Identity project.');
            }
            $hosted->forceFill([
                'project_id' => $project->id,
                'primary_client_id' => $bff->id,
                'sandbox_client_id' => $sandboxBff->id,
                'key' => 'nodara',
                'name' => 'Nodara',
                'application_url' => $nodaraApplicationUrl,
                'callback_url' => $nodaraCallbackUrl,
                'authentication_mode' => 'hosted',
                'password_reset_url' => null,
                'policy_revision' => $hosted->policy_revision,
                'auth_page_set' => $nodaraPageSet,
                'appearance' => $nodaraAppearance,
                'authentication' => $hosted->authentication ?? [
                    'google_enabled' => false,
                    'terms_required' => false,
                    'terms_url' => null,
                    'privacy_url' => null,
                ],
                'status' => 'active',
            ])->save();
        });

        fwrite(STDOUT, "Job Tracker and Nodara live/sandbox Identity projects, BFF clients, and hosted applications synchronized.\n");
    }
}
