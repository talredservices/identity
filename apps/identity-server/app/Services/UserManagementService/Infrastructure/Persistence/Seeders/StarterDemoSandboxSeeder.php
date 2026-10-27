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

final class StarterDemoSandboxSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'STARTER' => [
                'application_env' => 'STARTER_IDENTITY_APPLICATION',
                'application_fallback' => 'zoltasoft-starter',
                'name' => 'Talred Starter',
                'permissions' => ['account.read', 'account.update'],
                'client_kinds' => ['BFF'],
            ],
            'SAAS' => [
                'application_env' => 'STARTER_SAAS_IDENTITY_APPLICATION',
                'application_fallback' => 'zoltasoft-saas',
                'name' => 'Talred SaaS',
                'permissions' => ['account.read', 'account.update'],
                'client_kinds' => ['BFF', 'API'],
            ],
            'PROJECTS' => [
                'application_env' => 'STARTER_PROJECTS_IDENTITY_APPLICATION',
                'application_fallback' => 'tasks-demo',
                'name' => 'Talred Tasks',
                'permissions' => ['account.read', 'account.update'],
                'client_kinds' => ['BFF', 'API'],
            ],
        ];

        DB::transaction(function () use ($definitions): void {
            foreach ($definitions as $key => $definition) {
                $applicationKey = getenv($definition['application_env']) ?: $definition['application_fallback'];
                $application = IdentityHostedApplication::query()->where('key', $applicationKey)->firstOrFail();
                $liveProject = IdentityProject::query()->findOrFail($application->project_id);
                $sandboxSlug = $liveProject->slug.'-sandbox';
                $sandboxProject = IdentityProject::query()->where('slug', $sandboxSlug)->first();

                if ($sandboxProject !== null && $sandboxProject->mode !== 'sandbox') {
                    throw new RuntimeException('Sandbox slug already belongs to a live project.');
                }

                $sandboxProject ??= new IdentityProject;
                $sandboxProject->forceFill([
                    'name' => $definition['name'].' Demo Sandbox',
                    'slug' => $sandboxSlug,
                    'description' => 'Temporary visitor demo identities',
                    'mode' => 'sandbox',
                    'status' => 'active',
                    'sandbox_ttl_minutes' => 60,
                    'registration_mode' => 'invite_only',
                    'email_verification_required' => false,
                    'google_social_authentication_enabled' => false,
                ])->save();

                $clients = [];
                foreach ($definition['client_kinds'] as $kind) {
                    $clientId = getenv("TALRED_DEMO_{$key}_{$kind}_ID") ?: '';
                    $clientSecret = getenv("TALRED_DEMO_{$key}_{$kind}_SECRET") ?: '';
                    if (! Str::isUuid($clientId) || strlen($clientSecret) < 32) {
                        throw new RuntimeException('Missing sandbox client credentials.');
                    }
                    if ($clientId === (string) $application->primary_client_id) {
                        throw new RuntimeException('Sandbox must use distinct credentials.');
                    }

                    $client = IdentityProjectClient::query()->find($clientId) ?? new IdentityProjectClient;
                    if ($client->exists && (string) $client->project_id !== (string) $sandboxProject->id) {
                        throw new RuntimeException('Sandbox client belongs to another project.');
                    }
                    $client->forceFill([
                        'id' => $clientId,
                        'project_id' => $sandboxProject->id,
                        'name' => $definition['name'].' Sandbox '.$kind,
                        'secret_hash' => hash('sha256', $clientSecret),
                        'secret_prefix' => substr($clientSecret, 0, 8),
                        'status' => 'active',
                    ])->save();
                    $clients[$kind] = $client;
                }

                $permissionIds = [];
                foreach ($definition['permissions'] as $permissionKey) {
                    $permission = IdentityProjectPermission::query()->firstOrNew([
                        'project_id' => $sandboxProject->id,
                        'key' => $permissionKey,
                    ]);
                    $permission->forceFill([
                        'name' => $permissionKey,
                        'source_client_id' => ($clients['API'] ?? $clients['BFF'])->id,
                        'source' => 'manifest',
                        'status' => 'active',
                    ])->save();
                    $permissionIds[] = $permission->id;
                }

                $role = IdentityProjectRole::query()->firstOrNew([
                    'project_id' => $sandboxProject->id,
                    'slug' => 'member',
                ]);
                $role->forceFill([
                    'name' => 'Demo visitor',
                    'description' => 'Access only own sandbox data',
                ])->save();
                $role->permissions()->sync($permissionIds);
                $sandboxProject->forceFill(['registration_role_id' => $role->id])->save();

                $webhookSecret = getenv("TALRED_DEMO_{$key}_WEBHOOK_SECRET") ?: '';
                if (strlen($webhookSecret) < 32) {
                    throw new RuntimeException('Missing sandbox webhook credential.');
                }
                $webhookUrl = 'http://starter-demo-cleanup:8090/webhooks/identity/'.strtolower($key);
                $webhook = IdentityWebhookEndpoint::query()->firstOrNew([
                    'project_id' => $sandboxProject->id,
                    'url' => $webhookUrl,
                ]);
                $webhook->forceFill([
                    'events' => ['identity.user.expired', 'identity.user.deletion_requested'],
                    'secret' => $webhookSecret,
                    'secret_prefix' => substr($webhookSecret, 0, 8),
                    'status' => 'active',
                ])->save();

                $authentication = $application->authentication ?? [];
                $application->forceFill([
                    'sandbox_client_id' => $clients['BFF']->id,
                    'authentication' => [...$authentication, 'demo_only' => true],
                ])->save();

                $this->command?->info(strtolower($key).' sandbox configured (60 minutes).');
            }
        });
    }
}
