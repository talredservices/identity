<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services\Identity;

use App\Services\UserManagementService\Domain\Aggregates\IdentityClient as DomainIdentityClient;
use App\Services\UserManagementService\Domain\Aggregates\IdentityMembership as DomainIdentityMembership;
use App\Services\UserManagementService\Domain\Aggregates\IdentityPermission as DomainIdentityPermission;
use App\Services\UserManagementService\Domain\Aggregates\IdentityProject as DomainIdentityProject;
use App\Services\UserManagementService\Domain\Aggregates\IdentityRole as DomainIdentityRole;
use App\Services\UserManagementService\Domain\Aggregates\IdentityWebhook as DomainIdentityWebhook;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectMembership;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookEndpoint;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Support\Facades\Storage;

final class IdentityPayloadFactory
{
    /** @return array<string, mixed> */
    public function roleAggregate(DomainIdentityRole $role): array
    {
        return [
            'id' => $role->id()->toString(),
            'project_id' => $role->projectId()->toString(),
            'name' => $role->name(),
            'slug' => $role->slug(),
            'description' => $role->description(),
            'permission_ids' => array_map(
                static fn ($id): string => $id->toString(),
                $role->permissionIds(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function permissionAggregate(DomainIdentityPermission $permission): array
    {
        return [
            'id' => $permission->id()->toString(),
            'project_id' => $permission->projectId()->toString(),
            'key' => $permission->key(),
            'name' => $permission->name(),
            'description' => $permission->description(),
            'source' => $permission->source()->value,
            'source_client_id' => $permission->sourceClientId()?->toString(),
            'status' => $permission->status()->value,
        ];
    }

    /** @return array<string, mixed> */
    public function membershipAggregate(DomainIdentityMembership $membership): array
    {
        return [
            'id' => $membership->id()->toString(),
            'project_id' => $membership->projectId()->toString(),
            'user' => ['id' => $membership->userId()->toString()],
            'status' => $membership->status()->value,
            'is_admin' => $membership->isAdministrator(),
            'authorization_version' => $membership->authorizationVersion(),
            'role_ids' => array_map(
                static fn ($id): string => $id->toString(),
                $membership->roleIds(),
            ),
            'direct_permission_ids' => array_map(
                static fn ($id): string => $id->toString(),
                $membership->permissionIds(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function clientAggregate(DomainIdentityClient $client): array
    {
        return [
            'id' => $client->id()->toString(),
            'project_id' => $client->projectId()->toString(),
            'name' => $client->name(),
            'secret_prefix' => $client->secretPrefix(),
            'status' => $client->status()->value,
            'last_used_at' => $client->lastUsedAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function webhookAggregate(DomainIdentityWebhook $webhook): array
    {
        return [
            'id' => $webhook->id()->toString(),
            'project_id' => $webhook->projectId()->toString(),
            'url' => $webhook->url(),
            'events' => $webhook->events(),
            'secret_prefix' => $webhook->secretPrefix(),
            'status' => $webhook->status()->value,
            'last_delivered_at' => $webhook->lastDeliveredAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function projectAggregate(DomainIdentityProject $project): array
    {
        return [
            'id' => $project->id()->toString(),
            'name' => $project->name(),
            'slug' => $project->slug(),
            'description' => $project->description(),
            'status' => $project->status()->value,
            'mode' => $project->mode()->value,
            'sandbox_ttl_minutes' => $project->sandboxTtlMinutes(),
            'registration_mode' => $project->registrationMode()->value,
            'registration_role_id' => $project->registrationRoleId(),
            'email_verification_required' => $project->emailVerificationRequired(),
            'deletion_scheduled_at' => $project->deletionScheduledAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function identity(
        User $user,
        IdentityProject $project,
        IdentityProjectClient $client,
        IdentityProjectMembership $membership,
        ?IdentityProjectAccount $account = null,
    ): array {
        $account ??= IdentityProjectAccount::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'username' => $account?->username ?? $user->username,
                'avatar_url' => $account?->profile_picture ?? $user->avatar_url,
                'email_verified' => $account !== null ? $account->email_verified_at !== null : $user->email_verified_at !== null,
                'is_system_admin' => $user->is_system_admin,
                'is_temporary' => $user->is_temporary,
                'temporary_expires_at' => $user->demo_expires_at?->toIso8601String(),
            ],
            'project' => $this->project($project),
            'client' => $this->client($client),
            'membership' => $this->membership($membership),
        ];
    }

    /** @return array<string, mixed> */
    public function project(IdentityProject $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->status,
            'mode' => $project->mode,
            'sandbox_ttl_minutes' => $project->sandbox_ttl_minutes,
            'registration_mode' => $project->registration_mode,
            'registration_role_id' => $project->registration_role_id,
            'email_verification_required' => $project->email_verification_required,
            'google_social_authentication_enabled' => $project->google_social_authentication_enabled,
            'deletion_scheduled_at' => $project->deletion_scheduled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function client(IdentityProjectClient $client): array
    {
        return [
            'id' => $client->id,
            'project_id' => $client->project_id,
            'name' => $client->name,
            'secret_prefix' => $client->secret_prefix,
            'status' => $client->status,
            'last_used_at' => $client->last_used_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function hostedApplication(IdentityHostedApplication $application): array
    {
        return [
            'id' => $application->id,
            'project_id' => $application->project_id,
            'primary_client_id' => $application->primary_client_id,
            'sandbox_client_id' => $application->sandbox_client_id,
            'key' => $application->key,
            'name' => $application->name,
            'application_url' => $application->application_url,
            'callback_url' => $application->callback_url,
            'authentication_mode' => $application->authentication_mode ?: 'hosted',
            'password_reset_url' => $application->password_reset_url,
            'policy_revision' => $application->policy_revision,
            'auth_page_set' => $application->auth_page_set ?: 'default',
            'appearance' => $this->hostedApplicationAppearance($application),
            'authentication' => $this->hostedApplicationAuthentication($application),
            'status' => $application->status,
        ];
    }

    /** @return array<string, mixed> */
    public function hostedApplicationDirectoryItem(IdentityHostedApplication $application): array
    {
        $project = $application->project;
        $applicationUrl = $application->application_url;
        $urlParts = $applicationUrl === null ? false : parse_url($applicationUrl);
        $safeApplicationUrl = is_array($urlParts)
            && in_array(strtolower((string) ($urlParts['scheme'] ?? '')), ['http', 'https'], true)
            && ! empty($urlParts['host'])
            && ! isset($urlParts['user'])
            && ! isset($urlParts['pass'])
            && ! isset($urlParts['query'])
            && ! isset($urlParts['fragment'])
            ? $applicationUrl
            : null;

        return [
            'id' => $application->id,
            'name' => $application->name,
            'key' => $application->key,
            'status' => $application->status,
            'application_url' => $safeApplicationUrl,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status,
                'mode' => $project->mode,
            ],
        ];
    }

    /** @return array{brand_name: string|null, brand_badge: string|null, welcome_text: string|null, accent_color: string|null, background_preset: string, logo_url: string|null, design_tokens: array<string, string>} */
    private function hostedApplicationAppearance(IdentityHostedApplication $application): array
    {
        $appearance = $application->appearance ?? [];
        $disk = (string) config('zolta.identity.hosted_applications.branding_disk', 'public');
        $logoPath = $application->logo_path;

        return [
            'brand_name' => isset($appearance['brand_name']) && is_string($appearance['brand_name']) ? $appearance['brand_name'] : null,
            'brand_badge' => isset($appearance['brand_badge']) && is_string($appearance['brand_badge']) ? $appearance['brand_badge'] : null,
            'welcome_text' => $appearance['welcome_text'] ?? null,
            'accent_color' => $appearance['accent_color'] ?? null,
            'background_preset' => $appearance['background_preset'] ?? 'identity',
            'logo_url' => $this->safeHostedApplicationLogoUrl(
                $disk,
                $logoPath,
                is_string($appearance['logo_url'] ?? null) ? $appearance['logo_url'] : null,
            ),
            'design_tokens' => $this->safeHostedApplicationDesignTokens($appearance['design_tokens'] ?? null),
        ];
    }

    /** @param mixed $value @return array<string, string> */
    private function safeHostedApplicationDesignTokens(mixed $value): array
    {
        $tokens = [];
        $colorKeys = [
            'accent',
            'light_background', 'light_background_muted', 'light_card', 'light_text', 'light_muted', 'light_border', 'light_input_border', 'light_status',
            'dark_background', 'dark_background_muted', 'dark_card', 'dark_text', 'dark_muted', 'dark_border', 'dark_input_border', 'dark_status',
            'primary_50', 'primary_100', 'primary_200', 'primary_300', 'primary_400', 'primary_500', 'primary_600', 'primary_700', 'primary_800', 'primary_900', 'primary_950',
        ];
        foreach ((array) $value as $key => $token) {
            if (! is_string($key) || ! is_string($token)) {
                continue;
            }
            if ($key === 'font_family') {
                if (preg_match('/^[A-Za-z0-9 ,._-]+$/', $token) === 1) {
                    $tokens[$key] = $token;
                }

                continue;
            }
            if (in_array($key, $colorKeys, true) && preg_match('/^#[0-9A-Fa-f]{6}$/', $token) === 1) {
                $tokens[$key] = $token;
            }
        }

        return $tokens;
    }

    /** @return array{google_enabled: bool, demo_only: bool, terms_required: bool, terms_url: string|null, privacy_url: string|null} */
    private function hostedApplicationAuthentication(IdentityHostedApplication $application): array
    {
        $authentication = $application->authentication ?? [];

        return [
            'google_enabled' => (bool) $application->project?->google_social_authentication_enabled,
            'demo_only' => (bool) ($authentication['demo_only'] ?? false),
            'terms_required' => (bool) ($authentication['terms_required'] ?? false),
            'terms_url' => $authentication['terms_url'] ?? null,
            'privacy_url' => $authentication['privacy_url'] ?? null,
        ];
    }

    private function safeHostedApplicationLogoUrl(string $disk, ?string $logoPath, ?string $configuredUrl = null): ?string
    {
        if ($logoPath !== null
            && in_array(strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return Storage::disk($disk)->url($logoPath);
        }

        if ($configuredUrl === null || strlen($configuredUrl) > 2048) {
            return null;
        }

        $parts = parse_url(trim($configuredUrl));
        if (($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'], $parts['pass'], $parts['fragment'])) {
            return null;
        }

        return trim($configuredUrl);
    }

    /** @return array<string, mixed> */
    public function membership(IdentityProjectMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'project_id' => $membership->project_id,
            'user' => $membership->relationLoaded('user')
                ? [
                    'id' => $membership->user->id,
                    'email' => $membership->user->email,
                    'username' => $membership->user->username,
                ]
                : ['id' => $membership->user_id],
            'status' => $membership->status,
            'is_admin' => $membership->is_admin,
            'authorization_version' => $membership->authorization_version,
            'role_ids' => $membership->roles()->pluck('identity_project_roles.id')->all(),
            'direct_permission_ids' => $membership->permissions()->pluck('identity_project_permissions.id')->all(),
            'roles' => $membership->effectiveRoleSlugs(),
            'permissions' => $membership->effectivePermissionKeys(),
        ];
    }

    /** @return array<string, mixed> */
    public function role(IdentityProjectRole $role): array
    {
        return [
            'id' => $role->id,
            'project_id' => $role->project_id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'catalog_role_id' => $role->catalog_role_id,
            'catalog_version' => $role->catalog_version,
            'catalog_origin' => $role->catalog_origin,
            'permission_ids' => $role->permissions()->pluck('identity_project_permissions.id')->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function permission(IdentityProjectPermission $permission): array
    {
        return [
            'id' => $permission->id,
            'project_id' => $permission->project_id,
            'key' => $permission->key,
            'name' => $permission->name,
            'description' => $permission->description,
            'source' => $permission->source,
            'source_client_id' => $permission->source_client_id,
            'catalog_permission_id' => $permission->catalog_permission_id,
            'catalog_version' => $permission->catalog_version,
            'catalog_origin' => $permission->catalog_origin,
            'status' => $permission->status,
        ];
    }

    /** @return array<string, mixed> */
    public function webhook(IdentityWebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'project_id' => $endpoint->project_id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret_prefix' => $endpoint->secret_prefix,
            'status' => $endpoint->status,
            'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
        ];
    }
}
