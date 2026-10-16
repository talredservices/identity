<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services\Identity;

use App\Services\UserManagementService\Application\Contracts\Identity\Projects\IssuePlatformOnboardingInvitation;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthorizationException;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectInvitation;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentPlatformOnboardingInvitationIssuer implements IssuePlatformOnboardingInvitation
{
    public function issue(string $clientId, string $clientSecret, string $email): array
    {
        $project = $this->platformLoginProject($clientId, $clientSecret);

        $email = Str::lower(trim($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && IdentityProjectAccount::query()->where('project_id', $project->id)->where('user_id', $user->id)->exists()) {
            return ['email' => $email, 'already_a_member' => true, 'invitation_token' => null, 'expires_at' => null];
        }

        $hostedApplication = IdentityHostedApplication::query()
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if ($hostedApplication === null) {
            throw new IdentityAuthorizationException('Platform login is not configured for invitations.');
        }

        return DB::transaction(function () use ($email, $hostedApplication, $project): array {
            $plainToken = Str::random(64);
            $expiresAt = now()->addHours((int) config('zolta.identity.invitation_ttl_hours', 72));

            IdentityProjectInvitation::query()->create([
                'project_id' => $project->id,
                'hosted_application_id' => $hostedApplication->id,
                'invited_by' => null,
                'email' => $email,
                'token_hash' => hash('sha256', $plainToken),
                'is_admin' => false,
                'expires_at' => $expiresAt,
            ]);

            return [
                'email' => $email,
                'already_a_member' => false,
                'invitation_token' => $plainToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        });
    }

    public function revoke(string $clientId, string $clientSecret, string $invitationToken): void
    {
        $project = $this->platformLoginProject($clientId, $clientSecret);
        $invitation = IdentityProjectInvitation::query()
            ->where('project_id', $project->id)
            ->where('token_hash', hash('sha256', $invitationToken))
            ->first();

        if ($invitation === null) {
            return;
        }

        if ($invitation->accepted_at !== null) {
            throw new IdentityAuthorizationException('The platform login invitation has already been accepted.');
        }

        $invitation->delete();
    }

    private function platformLoginProject(string $clientId, string $clientSecret): IdentityProject
    {
        $client = IdentityProjectClient::query()->with('project')->where('status', 'active')->find($clientId);
        $configuredClientId = (string) config('services.platform_onboarding.client_id');

        if (! $client
            || $configuredClientId === ''
            || ! hash_equals($configuredClientId, (string) $client->getKey())
            || ! hash_equals((string) $client->secret_hash, hash('sha256', $clientSecret))) {
            throw new IdentityAuthenticationException('Invalid platform onboarding credentials.');
        }

        $project = $client->project;
        if (! $project instanceof IdentityProject
            || $project->slug !== (string) config('services.platform_onboarding.login_project_slug')
            || $project->status !== 'active'
            || $project->registration_mode !== 'invite_only') {
            throw new IdentityAuthorizationException('Platform onboarding is unavailable for this client.');
        }

        return $project;
    }
}
