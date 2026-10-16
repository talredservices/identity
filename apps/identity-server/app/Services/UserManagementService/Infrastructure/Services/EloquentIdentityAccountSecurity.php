<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use App\Services\UserManagementService\Domain\Enums\IdentitySensitiveOperation;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityRefreshToken;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\SocialAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\SocialProvider;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityAuditRecorder;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityClientAuthenticator;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityTokenManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class EloquentIdentityAccountSecurity implements ManageIdentityAccountSecurity
{
    public function __construct(
        private IdentityClientAuthenticator $clients,
        private IdentityTokenManager $tokens,
        private EloquentIdentityAuthenticationService $authentication,
        private IdentityAuditRecorder $audit,
    ) {}

    public function reauthenticate(string $accessToken, array $input): array
    {
        $token = $this->principal($accessToken);
        $client = $this->clients->authenticate($input['client_id'], $input['client_secret']);
        $operation = IdentitySensitiveOperation::from($input['sensitive_operation']);
        $account = IdentityProjectAccount::query()
            ->where('project_id', $token->identity_project_id)->where('user_id', $token->tokenable_id)
            ->where('status', 'active')->first();
        if ((string) $client->id !== (string) $token->identity_client_id
            || ! $account || ! Hash::check($input['password'], $account->password)) {
            throw new IdentityAuthenticationException('Fresh authentication for the signed-in account is required.');
        }

        return $this->issueGrant($token, $operation);
    }

    public function reauthenticateWithGoogle(string $accessToken, string $clientId, string $providerUserId, string $operation): array
    {
        $token = $this->principal($accessToken);
        $provider = SocialProvider::query()->where('social_provider', 'google')->first();
        $linked = $provider && SocialAccount::query()
            ->where('social_provider_id', $provider->id)->where('social_provider_user_id', $providerUserId)
            ->where('project_id', $token->identity_project_id)->where('user_id', $token->tokenable_id)->exists();
        if (! $linked || $clientId !== (string) $token->identity_client_id) {
            throw new IdentityAuthenticationException('Google must authenticate the same signed-in identity.');
        }

        return $this->issueGrant($token, IdentitySensitiveOperation::from($operation));
    }

    private function issueGrant(PersonalAccessToken $token, IdentitySensitiveOperation $operation): array
    {
        $grant = Str::random(64);
        $expiresAt = now()->addMinutes(5);
        DB::table('identity_reauthentication_grants')->insert([
            'grant_hash' => hash('sha256', $grant),
            'user_id' => $token->tokenable_id,
            'project_id' => $token->identity_project_id,
            'client_id' => $token->identity_client_id,
            'session_family_id' => $token->identity_refresh_family_id,
            'operation' => $operation->value,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        $this->audit->record('auth.reauthenticated', $token->identity_project_id, $token->identity_client_id, (string) $token->tokenable_id, 'session', $token->identity_refresh_family_id, ['operation' => $operation->value]);

        return ['grant' => $grant, 'expires_at' => $expiresAt->toIso8601String(), 'operation' => $operation->value];
    }

    public function consumeGrant(string $accessToken, string $grant, string $operation): void
    {
        $token = $this->principal($accessToken);
        $consumed = DB::table('identity_reauthentication_grants')
            ->where('grant_hash', hash('sha256', $grant))
            ->where('user_id', $token->tokenable_id)
            ->where('project_id', $token->identity_project_id)
            ->where('client_id', $token->identity_client_id)
            ->where('session_family_id', $token->identity_refresh_family_id)
            ->where('operation', $operation)->whereNull('consumed_at')
            ->where('expires_at', '>', now())->update(['consumed_at' => now()]);
        if ($consumed !== 1) {
            throw new IdentityAuthenticationException('Fresh authentication is required.');
        }
        $this->audit->record('auth.reauthentication_consumed', $token->identity_project_id, $token->identity_client_id, (string) $token->tokenable_id, 'session', $token->identity_refresh_family_id, ['operation' => $operation]);
    }

    public function changePassword(string $accessToken, string $password): void
    {
        $token = $this->principal($accessToken);
        DB::transaction(function () use ($token, $password): void {
            IdentityProjectAccount::query()->where('project_id', $token->identity_project_id)
                ->where('user_id', $token->tokenable_id)->where('status', 'active')
                ->firstOrFail()->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            $families = IdentityRefreshToken::query()->where('project_id', $token->identity_project_id)
                ->where('user_id', $token->tokenable_id)->where('family_id', '!=', $token->identity_refresh_family_id)
                ->distinct()->pluck('family_id');
            foreach ($families as $family) {
                $this->tokens->revokeFamily($family);
            }
            DB::table('identity_reauthentication_grants')->where('user_id', $token->tokenable_id)
                ->where('project_id', $token->identity_project_id)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });
    }

    private function principal(string $accessToken): PersonalAccessToken
    {
        $token = PersonalAccessToken::findToken($accessToken);
        if (! $token || ! $token->identity_project_id || ! $token->identity_client_id
            || ! $token->identity_refresh_family_id || ($token->expires_at && $token->expires_at->isPast())) {
            throw new IdentityAuthenticationException('An active project session is required.');
        }
        // Also enforces project membership and current account status.
        $identity = $this->authentication->currentIdentity((string) $token->tokenable_id, $accessToken);
        if ($identity['project']['status'] !== 'active' || $identity['client']['status'] !== 'active') {
            throw new IdentityAuthenticationException('An active project and client are required.');
        }
        if ($identity['project']['email_verification_required'] && ! $identity['user']['email_verified']) {
            throw new IdentityAuthenticationException('Email verification is required.');
        }

        return $token;
    }
}
