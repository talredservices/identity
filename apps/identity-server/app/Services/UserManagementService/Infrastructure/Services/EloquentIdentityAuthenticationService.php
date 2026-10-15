<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\AcceptIdentityInvitation;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\IssueIdentityAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityHandoffs;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentityAccessContext;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\RecoverIdentityPassword;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\SyncIdentityClientManifest;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\VerifyIdentityEmail;
use App\Services\UserManagementService\Application\Contracts\OAuthGateway;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthorizationException;
use App\Services\UserManagementService\Application\Exceptions\IdentityResourceNotFoundException;
use App\Services\UserManagementService\Domain\Aggregates\IdentityMembership as DomainIdentityMembership;
use App\Services\UserManagementService\Domain\Enums\IdentityMembershipStatus;
use App\Services\UserManagementService\Domain\Repositories\IdentityMembershipRepository;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityRoleId;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAccountPortalIntent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAuthorizationCode;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAuthorizationIntent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplicationConsent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityLogoutIntent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectInvitation;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityRefreshToken;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\SocialAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\SocialProvider;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Notifications\ResetIdentityPassword;
use App\Services\UserManagementService\Infrastructure\Notifications\VerifyEmailCode;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectClientRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectMembershipRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectRoleRepository;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityAuditRecorder;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityClientAuthenticator;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityPayloadFactory;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityPermissionManifestSynchronizer;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityTokenManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Zolta\Domain\ValueObjects\UserId;

final readonly class EloquentIdentityAuthenticationService implements AcceptIdentityInvitation, IssueIdentityAccess, ManageIdentityHandoffs, ManageIdentitySessions, ReadIdentityAccessContext, ReadIdentitySessions, RecoverIdentityPassword, SyncIdentityClientManifest, VerifyIdentityEmail
{
    public function __construct(
        private EloquentIdentityProjectMembershipRepository $memberships,
        private IdentityMembershipRepository $membershipAggregates,
        private EloquentIdentityProjectRoleRepository $projectRoles,
        private EloquentIdentityProjectClientRepository $projectClients,
        private IdentityClientAuthenticator $clients,
        private IdentityPermissionManifestSynchronizer $permissionManifests,
        private IdentityTokenManager $tokens,
        private IdentityPayloadFactory $payloads,
        private IdentityAuditRecorder $audit,
        private OAuthGateway $oauthGateway,
    ) {}

    public function login(
        array $credentials,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate(
            (string) $credentials['client_id'],
            (string) $credentials['client_secret'],
        );
        $project = $client->project;

        if ($project->mode === 'sandbox') {
            throw new IdentityAuthorizationException('Sandbox projects only accept temporary sessions.');
        }

        $requestedProject = (string) ($credentials['project'] ?? '');
        if ($requestedProject !== ''
            && ! in_array($requestedProject, [$project->id, $project->slug], true)) {
            throw new IdentityAuthenticationException('Invalid credentials.');
        }

        $user = User::query()
            ->whereRaw('lower(email) = ?', [Str::lower((string) $credentials['email'])])
            ->first();
        $account = $user ? IdentityProjectAccount::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first() : null;

        if ($user?->is_system_admin && (! $account || ! Hash::check((string) $credentials['password'], $account->password))) {
            $account = $this->ensureSystemAdminProjectAccess(
                $user,
                $project,
                (string) $credentials['password'],
                $client->id,
                $ipAddress,
                $userAgent,
            );
        }

        if (! $user || ! $account || ! Hash::check((string) $credentials['password'], $account->password)) {
            $this->audit->record(
                'auth.login_failed',
                $project->id,
                $client->id,
                null,
                null,
                null,
                [],
                $ipAddress,
                $userAgent,
            );
            throw new IdentityAuthenticationException('Invalid credentials.');
        }

        $membership = $this->memberships->findActiveForProjectUser($project->id, $user->id);
        if (! $membership || $project->status !== 'active') {
            throw new IdentityAuthenticationException('This account does not have access to the project.');
        }

        $tokens = $this->tokens->issuePair($user, $project, $client, $membership);
        $account->forceFill(['last_authenticated_at' => now()])->save();
        $this->audit->record(
            'auth.login_succeeded',
            $project->id,
            $client->id,
            $user->id,
            'user',
            $user->id,
            [],
            $ipAddress,
            $userAgent,
        );

        return $tokens + [
            'identity' => $this->payloads->identity($user, $project, $client, $membership, $account),
        ];
    }

    public function authenticationContext(
        string $clientId,
        string $clientSecret,
        ?string $project = null,
    ): array {
        $client = $this->clients->authenticate($clientId, $clientSecret);

        if ($project !== null
            && $project !== ''
            && ! in_array($project, [$client->project->id, $client->project->slug], true)) {
            throw new IdentityAuthenticationException('Invalid client credentials.');
        }

        $application = IdentityHostedApplication::query()->where('primary_client_id', $client->id)->where('status', 'active')->first();

        return [
            'application' => $application ? [
                'key' => $application->key,
                'mode' => $application->authentication_mode,
                'google_enabled' => (bool) ($application->authentication['google_enabled'] ?? false) && (bool) $client->project->google_social_authentication_enabled,
                'terms_required' => (bool) ($application->authentication['terms_required'] ?? false),
                'policy_revision' => $application->policy_revision,
            ] : null,
            'project' => $this->payloads->project($client->project),
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
        ];
    }

    public function createHandoff(
        string $userId,
        string $accessToken,
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate(
            (string) $input['client_id'],
            (string) $input['client_secret'],
        );
        $token = PersonalAccessToken::findToken($accessToken);

        if (! $token
            || (string) $token->tokenable_id !== $userId
            || (string) $token->identity_project_id !== (string) $client->project_id
            || (string) $token->identity_client_id !== (string) $client->id) {
            throw new IdentityAuthenticationException(
                'The Identity session cannot authorize this client.',
            );
        }

        $plainCode = Str::random(96);
        IdentityAuthorizationCode::query()->create([
            'user_id' => $userId,
            'project_id' => $client->project_id,
            'client_id' => $client->id,
            'source_refresh_family_id' => $token->identity_refresh_family_id,
            'code_hash' => hash('sha256', $plainCode),
            'redirect_uri' => (string) $input['redirect_uri'],
            'expires_at' => now()->addMinutes(2),
        ]);
        $this->audit->record(
            'auth.handoff_created',
            $client->project_id,
            $client->id,
            $userId,
            'client',
            $client->id,
            [],
            $ipAddress,
            $userAgent,
        );

        return [
            'code' => $plainCode,
            'redirect_uri' => (string) $input['redirect_uri'],
            'expires_in' => 120,
        ];
    }

    public function exchangeHandoff(
        array $input,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate(
            (string) $input['client_id'],
            (string) $input['client_secret'],
        );

        return DB::transaction(function () use ($client, $input, $ipAddress, $userAgent): array {
            $authorizationCode = IdentityAuthorizationCode::query()
                ->where('code_hash', hash('sha256', (string) $input['code']))
                ->lockForUpdate()
                ->first();

            if (! $authorizationCode
                || $authorizationCode->consumed_at !== null
                || $authorizationCode->expires_at->isPast()
                || (string) $authorizationCode->client_id !== (string) $client->id
                || ! hash_equals(
                    (string) $authorizationCode->redirect_uri,
                    (string) $input['redirect_uri'],
                )) {
                throw new IdentityAuthenticationException(
                    'The authorization handoff is invalid or expired.',
                );
            }

            $user = User::query()->findOrFail($authorizationCode->user_id);
            $project = IdentityProject::query()
                ->where('status', 'active')
                ->findOrFail($authorizationCode->project_id);
            $membership = $this->memberships->findActiveForProjectUser(
                $project->id,
                $user->id,
            );
            if (! $membership) {
                throw new IdentityAuthenticationException('Project access has been revoked.');
            }

            $authorizationCode->forceFill(['consumed_at' => now()])->save();
            if ($authorizationCode->reauthentication_cipher !== null) {
                $delivery = json_decode(Crypt::decryptString($authorizationCode->reauthentication_cipher), true, 512, JSON_THROW_ON_ERROR);
                $authorizationCode->forceFill(['reauthentication_cipher' => null])->save();

                return ['reauthentication' => $delivery];
            }
            $tokens = $this->tokens->issuePair($user, $project, $client, $membership);
            if ($authorizationCode->source_refresh_family_id !== null) {
                $this->tokens->revokeFamily((string) $authorizationCode->source_refresh_family_id);
            }
            $this->audit->record(
                'auth.handoff_exchanged',
                $project->id,
                $client->id,
                $user->id,
                'client',
                $client->id,
                [],
                $ipAddress,
                $userAgent,
            );

            return $tokens + [
                'identity' => $this->payloads->identity($user, $project, $client, $membership),
            ];
        });
    }

    public function createAuthorizationIntent(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $client = $this->clients->authenticate((string) $input['client_id'], (string) $input['client_secret']);
        $application = IdentityHostedApplication::query()
            ->with('sandboxClient.project')
            ->where('key', (string) $input['hosted_application'])
            ->where('primary_client_id', $client->id)
            ->where('status', 'active')
            ->firstOrFail();
        $sandboxClient = $application->sandboxClient;
        $demoAccountEnabled = (bool) $input['demo_account_enabled']
            && $sandboxClient !== null
            && $sandboxClient->status === 'active'
            && $sandboxClient->project?->status === 'active'
            && $sandboxClient->project?->mode === 'sandbox';
        $intent = Str::random(96);

        IdentityAuthorizationIntent::query()->create([
            'hosted_application_id' => $application->id,
            'intent_hash' => hash('sha256', $intent),
            'state' => (string) $input['state'],
            'demo_account_enabled' => $demoAccountEnabled,
            'expires_at' => now()->addMinutes(10),
        ]);
        $this->audit->record(
            'auth.authorization_intent_created',
            $application->project_id,
            $application->primary_client_id,
            null,
            'hosted_application',
            $application->id,
            ['demo_account_enabled' => $demoAccountEnabled],
            $ipAddress,
            $userAgent,
        );

        return ['intent' => $intent, 'expires_in' => 600];
    }

    public function consumeAuthorizationIntent(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($input, $ipAddress, $userAgent): array {
            $intent = IdentityAuthorizationIntent::query()
                ->where('intent_hash', hash('sha256', (string) $input['intent']))
                ->lockForUpdate()
                ->first();
            if (! $intent || $intent->consumed_at !== null || $intent->expires_at->isPast()
                || (string) $intent->hosted_application_id !== (string) $input['hosted_application_id']) {
                throw new IdentityAuthenticationException('The hosted authorization request is invalid or expired.');
            }

            $intent->forceFill(['consumed_at' => now()])->save();
            $application = IdentityHostedApplication::query()->findOrFail($intent->hosted_application_id);
            $this->audit->record(
                'auth.authorization_intent_consumed',
                $application->project_id,
                $application->primary_client_id,
                null,
                'hosted_application',
                $application->id,
                ['demo_account_enabled' => $intent->demo_account_enabled],
                $ipAddress,
                $userAgent,
            );

            return [
                'state' => $intent->state,
                'features' => ['demo_account' => $intent->demo_account_enabled],
            ];
        });
    }

    public function createAccountPortalIntent(string $userId, string $accessToken, array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $application = IdentityHostedApplication::query()->with('primaryClient')
            ->when(isset($input['hosted_application_id']), fn ($query) => $query->whereKey((string) $input['hosted_application_id']))
            ->when(isset($input['hosted_application']), fn ($query) => $query->where('key', (string) $input['hosted_application']))
            ->firstOrFail();
        $token = PersonalAccessToken::findToken($accessToken);
        if (! $token
            || (string) $token->tokenable_id !== $userId
            || (string) $token->identity_project_id !== (string) $application->project_id
            || (string) $token->identity_client_id !== (string) $application->primary_client_id) {
            throw new IdentityAuthenticationException('The Identity session cannot open account settings.');
        }

        $intent = Str::random(96);
        IdentityAccountPortalIntent::query()->create([
            'hosted_application_id' => $application->id,
            'user_id' => $userId,
            'intent_hash' => hash('sha256', $intent),
            'expires_at' => now()->addMinutes(2),
        ]);
        $this->audit->record('auth.account_portal_intent_created', $application->project_id, $application->primary_client_id, $userId, 'hosted_application', $application->id, [], $ipAddress, $userAgent);

        return ['intent' => $intent, 'expires_in' => 120];
    }

    public function consumeAccountPortalIntent(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($input, $ipAddress, $userAgent): array {
            $intent = IdentityAccountPortalIntent::query()
                ->where('intent_hash', hash('sha256', (string) $input['intent']))
                ->lockForUpdate()
                ->first();
            if (! $intent || $intent->consumed_at !== null || $intent->expires_at->isPast()
                || (string) $intent->hosted_application_id !== (string) $input['hosted_application_id']) {
                throw new IdentityAuthenticationException('The account settings request is invalid or expired.');
            }
            $intent->forceFill(['consumed_at' => now()])->save();
            $application = IdentityHostedApplication::query()->findOrFail($intent->hosted_application_id);
            $this->audit->record('auth.account_portal_intent_consumed', $application->project_id, $application->primary_client_id, $intent->user_id, 'hosted_application', $application->id, [], $ipAddress, $userAgent);

            return ['authorized' => true];
        });
    }

    public function createLogoutIntent(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $client = $this->clients->authenticate((string) $input['client_id'], (string) $input['client_secret']);
        $application = IdentityHostedApplication::query()
            ->where('key', (string) $input['hosted_application'])
            ->where('primary_client_id', $client->id)
            ->where('status', 'active')
            ->firstOrFail();
        $returnTo = (string) $input['return_to'];
        if (! str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//') || str_contains($returnTo, '\\')) {
            throw new IdentityAuthenticationException('The logout return destination is invalid.');
        }
        $intent = Str::random(96);
        $applicationUrl = parse_url((string) $application->application_url);
        $applicationOrigin = ($applicationUrl['scheme'] ?? 'https').'://'.($applicationUrl['host'] ?? '');
        if (isset($applicationUrl['port'])) {
            $applicationOrigin .= ':'.$applicationUrl['port'];
        }
        IdentityLogoutIntent::query()->create([
            'hosted_application_id' => $application->id,
            'intent_hash' => hash('sha256', $intent),
            'return_url' => $applicationOrigin.$returnTo,
            'expires_at' => now()->addMinutes(2),
        ]);
        $this->audit->record('auth.logout_intent_created', $application->project_id, $application->primary_client_id, null, 'hosted_application', $application->id, [], $ipAddress, $userAgent);

        return ['intent' => $intent, 'expires_in' => 120];
    }

    public function consumeLogoutIntent(array $input, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        return DB::transaction(function () use ($input, $ipAddress, $userAgent): array {
            $intent = IdentityLogoutIntent::query()
                ->where('intent_hash', hash('sha256', (string) $input['intent']))
                ->lockForUpdate()
                ->first();
            if (! $intent || $intent->consumed_at !== null || $intent->expires_at->isPast()
                || (string) $intent->hosted_application_id !== (string) $input['hosted_application_id']) {
                throw new IdentityAuthenticationException('The logout request is invalid or expired.');
            }
            $intent->forceFill(['consumed_at' => now()])->save();
            $application = IdentityHostedApplication::query()->findOrFail($intent->hosted_application_id);
            $this->audit->record('auth.logout_intent_consumed', $application->project_id, $application->primary_client_id, null, 'hosted_application', $application->id, [], $ipAddress, $userAgent);

            return ['redirect_url' => $intent->return_url];
        });
    }

    public function register(
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate(
            (string) $attributes['client_id'],
            (string) $attributes['client_secret'],
        );
        $project = $client->project;
        $application = IdentityHostedApplication::query()->where('primary_client_id', $client->id)
            ->where('status', 'active')->where('authentication_mode', 'app')->first();
        if ($application !== null) {
            if (($attributes['application_key'] ?? null) !== $application->key) {
                throw new IdentityAuthenticationException('The registered application is required.');
            }
            $termsRequired = ($application->authentication['terms_required'] ?? false) === true;
            if ($termsRequired && (($attributes['terms_accepted'] ?? false) !== true
                || ! $application->policy_revision || ($attributes['policy_revision'] ?? null) !== $application->policy_revision)) {
                throw new IdentityAuthorizationException('Acceptance of the current application terms is required.');
            }
            $attributes['hosted_application_id'] = $application->id;
            $attributes['terms_required'] = $termsRequired;
            $attributes['terms_url'] = $application->authentication['terms_url'] ?? null;
            $attributes['policy_revision'] = $application->policy_revision;
        }

        if ($project->mode === 'sandbox') {
            throw new IdentityAuthorizationException(
                'Sandbox projects do not accept permanent registrations.',
            );
        }

        $requestedProject = (string) ($attributes['project'] ?? '');
        if ($requestedProject !== ''
            && ! in_array($requestedProject, [$project->id, $project->slug], true)) {
            throw new IdentityAuthenticationException('Invalid credentials.');
        }

        if ($project->status !== 'active' || $project->registration_mode !== 'public') {
            throw new IdentityAuthorizationException(
                'Public registration is not enabled for this project.',
            );
        }

        return DB::transaction(function () use (
            $attributes,
            $project,
            $client,
            $ipAddress,
            $userAgent,
        ): array {
            $email = Str::lower((string) $attributes['email']);
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->lockForUpdate()->first();
            if ($user !== null && IdentityProjectAccount::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->exists()) {
                throw new IdentityAuthenticationException(
                    'An account with this email already exists in this project. Sign in instead.',
                );
            }

            $user ??= User::query()->create([
                'username' => (string) $attributes['username'],
                'email' => $email,
                // End-user credentials are held by the project account. This random
                // value is never used by project authentication or administration.
                'password' => Str::random(64),
                'terms' => 'accepted',
            ]);
            $account = IdentityProjectAccount::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'username' => (string) $attributes['username'],
                'password' => (string) $attributes['password'],
                'email_verified_at' => $project->email_verification_required ? null : now(),
                'password_changed_at' => now(),
            ]);
            $roleIds = [];
            if ($project->registration_role_id !== null
                && $this->projectRoles->existsForProject(
                    $project->id,
                    $project->registration_role_id,
                )) {
                $roleIds[] = IdentityRoleId::fromString($project->registration_role_id);
            }
            $membershipAggregate = DomainIdentityMembership::create(
                IdentityProjectId::fromString($project->id),
                new UserId((string) $user->id),
                roleIds: $roleIds,
            );
            $this->membershipAggregates->save($membershipAggregate);
            $membership = $this->memberships->findForProjectOrFail(
                $project->id,
                $membershipAggregate->id()->toString(),
            );

            if (($attributes['terms_required'] ?? false) === true && isset($attributes['hosted_application_id'])) {
                IdentityHostedApplicationConsent::query()->create([
                    'hosted_application_id' => (string) $attributes['hosted_application_id'],
                    'user_id' => $user->id,
                    'project_account_id' => $account->id,
                    'terms_url' => $attributes['terms_url'] ?? null,
                    'policy_revision' => $attributes['policy_revision'] ?? null,
                    'accepted_at' => now(),
                ]);
            }

            $tokens = $this->tokens->issuePair($user, $project, $client, $membership);
            if ($project->email_verification_required) {
                $this->issueEmailVerification($account, $user);
            }
            $this->audit->record(
                'auth.registered',
                $project->id,
                $client->id,
                $user->id,
                'user',
                $user->id,
                [],
                $ipAddress,
                $userAgent,
            );

            return $tokens + [
                'identity' => $this->payloads->identity($user, $project, $client, $membership, $account),
            ];
        });
    }

    public function socialLogin(
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate((string) $attributes['client_id'], (string) $attributes['client_secret']);
        $project = $client->project;
        if ($project->mode === 'sandbox' || $project->status !== 'active') {
            throw new IdentityAuthorizationException('This project does not accept social sign-in.');
        }
        if (! $project->google_social_authentication_enabled) {
            throw new IdentityAuthorizationException('Social sign-in is not enabled for this project.');
        }

        $remote = $this->oauthGateway->fetchUser(
            (string) $attributes['provider'],
            (string) $attributes['access_token'],
        );
        if ($remote->email === '') {
            throw new IdentityAuthenticationException('Google did not return an email address.');
        }

        return DB::transaction(function () use ($attributes, $client, $ipAddress, $project, $remote, $userAgent): array {
            $provider = SocialProvider::query()->firstOrCreate(
                ['social_provider' => (string) $attributes['provider']],
                ['id' => (string) Str::uuid()],
            );
            $account = SocialAccount::query()
                ->where('social_provider_id', $provider->id)
                ->where('project_id', $project->id)
                ->where('social_provider_user_id', $remote->providerUserId)
                ->first();
            $user = $account?->user ?? User::query()
                ->whereRaw('lower(email) = ?', [Str::lower($remote->email)])
                ->first();
            $newMembership = false;

            if ($user === null) {
                if ($project->registration_mode !== 'public') {
                    throw new IdentityAuthorizationException('Public registration is not enabled for this project.');
                }
                $user = User::query()->create([
                    'username' => Str::limit($remote->name !== '' ? $remote->name : Str::before($remote->email, '@'), 100, ''),
                    'email' => Str::lower($remote->email),
                    'password' => Str::random(64),
                    'terms' => 'accepted',
                ]);
                $newMembership = true;
            }

            $membership = $this->memberships->findActiveForProjectUser($project->id, $user->id);
            if ($membership === null) {
                if ($project->registration_mode !== 'public') {
                    throw new IdentityAuthorizationException('This account does not have access to the project.');
                }
                if (($attributes['terms_required'] ?? false) === true
                    && ! ($attributes['terms_accepted'] ?? false)) {
                    throw new IdentityAuthorizationException(
                        'You must accept the terms of service to create an account.',
                    );
                }
                $roleIds = $project->registration_role_id !== null && $this->projectRoles->existsForProject($project->id, $project->registration_role_id)
                    ? [IdentityRoleId::fromString($project->registration_role_id)] : [];
                $aggregate = DomainIdentityMembership::create(IdentityProjectId::fromString($project->id), new UserId((string) $user->id), roleIds: $roleIds);
                $this->membershipAggregates->save($aggregate);
                $membership = $this->memberships->findForProjectOrFail($project->id, $aggregate->id()->toString());
                $newMembership = true;
            }

            $projectAccount = IdentityProjectAccount::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->first();
            if ($projectAccount === null) {
                $projectAccount = IdentityProjectAccount::query()->create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'username' => Str::limit($remote->name !== '' ? $remote->name : Str::before($remote->email, '@'), 100, ''),
                    'password' => Str::random(64),
                    'email_verified_at' => now(),
                    'password_changed_at' => now(),
                ]);
            }

            SocialAccount::query()->updateOrCreate(
                ['social_provider_id' => $provider->id, 'project_id' => $project->id, 'social_provider_user_id' => $remote->providerUserId],
                ['id' => (string) Str::uuid(), 'user_id' => $user->id, 'access_token' => $remote->accessToken, 'refresh_token' => $remote->refreshToken, 'avatar_url' => $remote->avatarUrl],
            );
            if ($newMembership && ($attributes['terms_required'] ?? false) === true && isset($attributes['hosted_application_id'])) {
                IdentityHostedApplicationConsent::query()->create([
                    'hosted_application_id' => (string) $attributes['hosted_application_id'],
                    'user_id' => $user->id,
                    'project_account_id' => $projectAccount->id,
                    'terms_url' => $attributes['terms_url'] ?? null,
                    'policy_revision' => $attributes['policy_revision'] ?? null,
                    'accepted_at' => now(),
                ]);
            }

            $tokens = $this->tokens->issuePair($user, $project, $client, $membership);
            $this->audit->record('auth.social_login', $project->id, $client->id, $user->id, 'user', $user->id, ['provider' => $attributes['provider']], $ipAddress, $userAgent);

            return $tokens + ['identity' => $this->payloads->identity($user, $project, $client, $membership, $projectAccount)];
        });
    }

    public function createSandboxSession(
        string $clientId,
        string $clientSecret,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate($clientId, $clientSecret);
        $project = $client->project;

        if ($project->mode !== 'sandbox') {
            throw new IdentityAuthorizationException(
                'Temporary sessions are only available for sandbox projects.',
            );
        }

        return DB::transaction(function () use (
            $project,
            $client,
            $ipAddress,
            $userAgent,
        ): array {
            $id = (string) Str::uuid();
            $suffix = Str::lower(Str::random(8));
            $expiresAt = now()->addMinutes((int) $project->sandbox_ttl_minutes);
            $user = User::query()->create([
                'id' => $id,
                'username' => "Sandbox Guest {$suffix}",
                'email' => "sandbox-{$id}@identity.invalid",
                'password' => Str::random(64),
                'terms' => 'accepted',
                'email_verified_at' => now(),
                'is_temporary' => true,
                'demo_expires_at' => $expiresAt,
                'login_alerts_enabled' => false,
            ]);
            $roleIds = [];
            if ($project->registration_role_id !== null
                && $this->projectRoles->existsForProject(
                    $project->id,
                    $project->registration_role_id,
                )) {
                $roleIds[] = IdentityRoleId::fromString($project->registration_role_id);
            }
            $membershipAggregate = DomainIdentityMembership::create(
                IdentityProjectId::fromString($project->id),
                new UserId((string) $user->id),
                roleIds: $roleIds,
            );
            $this->membershipAggregates->save($membershipAggregate);
            $membership = $this->memberships->findForProjectOrFail(
                $project->id,
                $membershipAggregate->id()->toString(),
            );
            $account = IdentityProjectAccount::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'username' => $user->username,
                'password' => Str::random(64),
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ]);

            $tokens = $this->tokens->issuePair($user, $project, $client, $membership);
            $this->audit->record(
                'auth.sandbox_session_created',
                $project->id,
                $client->id,
                $user->id,
                'user',
                $user->id,
                ['expires_at' => $expiresAt->toIso8601String()],
                $ipAddress,
                $userAgent,
            );

            return $tokens + [
                'is_temporary' => true,
                'expires_at' => $expiresAt->toIso8601String(),
                'identity' => $this->payloads->identity($user, $project, $client, $membership, $account),
            ];
        });
    }

    public function resendEmailVerification(string $userId, string $accessToken): array
    {
        $token = $this->identityAccessToken($accessToken);
        $account = IdentityProjectAccount::query()->where('project_id', $token->identity_project_id)->where('user_id', $userId)->firstOrFail();
        if ($account->email_verified_at !== null) {
            return ['message' => 'Email address is already verified.'];
        }

        $code = $this->issueEmailVerification($account, $account->user()->firstOrFail());
        $result = ['message' => 'A new verification code was sent.'];
        if ((bool) config('zolta.identity.expose_development_tokens', false)) {
            $result['development_code'] = $code;
        }

        return $result;
    }

    public function verifyEmail(string $userId, string $accessToken, string $code): void
    {
        $token = $this->identityAccessToken($accessToken);
        $account = IdentityProjectAccount::query()->where('project_id', $token->identity_project_id)->where('user_id', $userId)->firstOrFail();
        if ($account->email_verified_at !== null) {
            return;
        }

        if ($account->email_verification_expires_at === null
            || $account->email_verification_expires_at->isPast()
            || ! hash_equals(
                (string) $account->email_verification_code_hash,
                $this->secretHash($code),
            )) {
            throw new IdentityAuthenticationException(
                'The verification code is invalid or expired.',
            );
        }

        $account->forceFill([
            'email_verified_at' => now(),
            'email_verification_code_hash' => null,
            'email_verification_expires_at' => null,
        ])->save();
        $this->audit->record(
            'auth.email_verified',
            null,
            null,
            $userId,
            'project_account',
            $account->id,
        );
    }

    public function requestPasswordReset(
        string $clientId,
        string $clientSecret,
        string $email,
    ): array {
        $client = $this->clients->authenticate($clientId, $clientSecret);
        $result = ['message' => 'If that account exists, password reset instructions were sent.'];
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();

        if ($user?->is_system_admin) {
            $this->ensureSystemAdminProjectAccess($user, $client->project, null, $client->id);
        }

        $account = $user ? IdentityProjectAccount::query()->where('project_id', $client->project_id)->where('user_id', $user->id)->where('status', 'active')->first() : null;

        if (! $user || ! $account) {
            return $result;
        }

        $token = Str::random(64);
        DB::table('identity_project_password_reset_tokens')->updateOrInsert(
            ['project_account_id' => $account->id],
            ['token_hash' => $this->secretHash($token), 'created_at' => now()],
        );
        $application = IdentityHostedApplication::query()
            ->where(function ($query) use ($clientId): void {
                $query
                    ->where('primary_client_id', $clientId)
                    ->orWhere('sandbox_client_id', $clientId);
            })
            ->where('status', 'active')
            ->first();
        $user->notify(new ResetIdentityPassword(
            $token,
            $clientId,
            $application?->auth_page_set,
            $application?->name ?: $client->project->name,
        ));

        if ((bool) config('zolta.identity.expose_development_tokens', false)) {
            $result['development_token'] = $token;
        }

        return $result;
    }

    private function ensureSystemAdminProjectAccess(
        User $user,
        IdentityProject $project,
        ?string $password,
        string $clientId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?IdentityProjectAccount {
        if (! $user->is_system_admin || ($password !== null && ! Hash::check($password, (string) $user->password))) {
            return null;
        }

        return DB::transaction(function () use ($user, $project, $password, $clientId, $ipAddress, $userAgent): IdentityProjectAccount {
            $account = IdentityProjectAccount::query()->firstOrNew([
                'project_id' => $project->id,
                'user_id' => $user->id,
            ]);
            $created = ! $account->exists;
            $account->fill([
                'username' => $user->username,
                'status' => 'active',
                'email_verified_at' => $account->email_verified_at ?? now(),
                'password_changed_at' => $account->password_changed_at ?? now(),
            ]);
            if ($created || ($password !== null && ! Hash::check($password, (string) $account->password))) {
                $account->password = $password ?? Str::random(64);
                $account->password_changed_at = now();
            }
            $account->save();

            $membership = $this->membershipAggregates->findForProjectUser(
                IdentityProjectId::fromString($project->id),
                new UserId((string) $user->id),
            );
            $membershipCreated = $membership === null;
            if ($membershipCreated) {
                $membership = DomainIdentityMembership::create(
                    IdentityProjectId::fromString($project->id),
                    new UserId((string) $user->id),
                    true,
                );
            } elseif (! $membership->isAdministrator() || $membership->status() !== IdentityMembershipStatus::Active) {
                $membership->updateAccess(
                    $membership->roleIds(),
                    $membership->permissionIds(),
                    true,
                    IdentityMembershipStatus::Active,
                );
            }
            $this->membershipAggregates->save($membership);

            if ($created || $membershipCreated) {
                $this->audit->record(
                    'auth.system_admin_project_access_provisioned',
                    $project->id,
                    $clientId,
                    $user->id,
                    'user',
                    $user->id,
                    ['project_access' => 'system_admin'],
                    $ipAddress,
                    $userAgent,
                );
            }

            return $account;
        });
    }

    public function resetPassword(
        string $clientId,
        string $clientSecret,
        string $email,
        string $token,
        string $password,
    ): void {
        $client = $this->clients->authenticate($clientId, $clientSecret);
        $user = User::query()->whereRaw('lower(email) = ?', [Str::lower($email)])->first();
        $account = $user ? IdentityProjectAccount::query()->where('project_id', $client->project_id)->where('user_id', $user->id)->first() : null;
        $reset = $account
            ? DB::table('identity_project_password_reset_tokens')->where('project_account_id', $account->id)->first()
            : null;
        $expiresAt = $reset?->created_at
            ? Carbon::parse($reset->created_at)->addMinutes(
                (int) config('zolta.identity.password_reset_ttl_minutes', 60),
            )
            : null;

        if (! $user || ! $account || ! $reset || $expiresAt?->isPast() !== false
            || ! hash_equals((string) $reset->token_hash, $this->secretHash($token))) {
            throw new IdentityAuthenticationException(
                'The password reset token is invalid or expired.',
            );
        }

        DB::transaction(function () use ($account, $client, $password, $user): void {
            $account->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
            DB::table('identity_project_password_reset_tokens')->where('project_account_id', $account->id)->delete();
            $this->tokens->revokeProjectUser($client->project_id, $user->id);
            $this->audit->record(
                'auth.password_reset',
                $client->project_id,
                $client->id,
                $user->id,
                'user',
                $user->id,
            );
        });
    }

    public function refresh(
        array $credentials,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $client = $this->clients->authenticate(
            (string) $credentials['client_id'],
            (string) $credentials['client_secret'],
        );
        $plainRefreshToken = (string) $credentials['refresh_token'];

        $result = DB::transaction(function () use (
            $client,
            $plainRefreshToken,
            $ipAddress,
            $userAgent,
        ): array {
            $refresh = IdentityRefreshToken::query()
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if (! $refresh || $refresh->client_id !== $client->id) {
                throw new IdentityAuthenticationException('Invalid refresh token.');
            }

            if ($refresh->rotated_to_id !== null || $refresh->used_at !== null) {
                $this->tokens->revokeFamily($refresh->family_id);
                $this->audit->record(
                    'auth.refresh_replay_detected',
                    $refresh->project_id,
                    $refresh->client_id,
                    $refresh->user_id,
                    'session',
                    $refresh->family_id,
                    [],
                    $ipAddress,
                    $userAgent,
                );

                return ['replay_detected' => true];
            }

            if ($refresh->revoked_at !== null || $refresh->expires_at->isPast()) {
                throw new IdentityAuthenticationException('Refresh token is expired or revoked.');
            }

            $user = User::query()->findOrFail($refresh->user_id);
            $project = IdentityProject::query()
                ->where('status', 'active')
                ->findOrFail($refresh->project_id);
            $membership = $this->memberships->findActiveForProjectUser(
                $project->id,
                $user->id,
            );

            if (! $membership) {
                $this->tokens->revokeFamily($refresh->family_id);
                throw new IdentityAuthenticationException('Project access has been revoked.');
            }

            $tokens = $this->tokens->issuePair(
                $user,
                $project,
                $client,
                $membership,
                $refresh->family_id,
            );
            $newRefresh = IdentityRefreshToken::query()
                ->where('token_hash', hash('sha256', $tokens['refresh_token']))
                ->firstOrFail();
            $refresh->forceFill([
                'used_at' => now(),
                'rotated_to_id' => $newRefresh->id,
            ])->save();
            $this->audit->record(
                'auth.token_refreshed',
                $project->id,
                $client->id,
                $user->id,
                'session',
                $refresh->family_id,
                [],
                $ipAddress,
                $userAgent,
            );

            return $tokens + [
                'identity' => $this->payloads->identity($user, $project, $client, $membership),
            ];
        });

        if (($result['replay_detected'] ?? false) === true) {
            throw new IdentityAuthenticationException(
                'Refresh token reuse detected. The session has been revoked.',
            );
        }

        return $result;
    }

    public function introspect(
        string $clientId,
        string $clientSecret,
        string $accessToken,
    ): array {
        $client = $this->clients->authenticate($clientId, $clientSecret);
        $token = PersonalAccessToken::findToken($accessToken);

        if (! $token
            || $token->expires_at?->isPast()
            || $token->identity_project_id !== $client->project_id) {
            return ['active' => false];
        }

        $membership = $this->memberships->findActiveForProjectUser(
            (string) $token->identity_project_id,
            (string) $token->tokenable_id,
        );
        if (! $membership) {
            return ['active' => false];
        }

        $user = User::query()->find($token->tokenable_id);
        if (! $user
            || ($user->is_temporary
                && ($user->demo_expires_at === null || $user->demo_expires_at->isPast()))) {
            return ['active' => false];
        }

        $account = IdentityProjectAccount::query()->where('project_id', $client->project_id)->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $account || ($client->project->email_verification_required && $account->email_verified_at === null)) {
            return ['active' => false];
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $client->forceFill(['last_used_at' => now()])->save();

        return [
            'active' => true,
            'sub' => $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'email_verified' => $account->email_verified_at !== null,
            'project_id' => $token->identity_project_id,
            'project_slug' => $client->project->slug,
            'project_mode' => $client->project->mode,
            'client_id' => $token->identity_client_id,
            'session_id' => $token->identity_refresh_family_id,
            'roles' => $membership->effectiveRoleSlugs(),
            'permissions' => $membership->effectivePermissionKeys(),
            'authorization_version' => $membership->authorization_version,
            'is_temporary' => $user->is_temporary,
            'temporary_expires_at' => $user->demo_expires_at?->toIso8601String(),
            'exp' => $token->expires_at?->getTimestamp(),
        ];
    }

    public function logout(string $accessToken): void
    {
        $token = PersonalAccessToken::findToken($accessToken);
        if (! $token) {
            return;
        }

        if ($token->identity_refresh_family_id) {
            $this->tokens->revokeFamily((string) $token->identity_refresh_family_id);

            return;
        }

        $token->delete();
    }

    public function currentIdentity(string $userId, string $accessToken): array
    {
        $token = PersonalAccessToken::findToken($accessToken);
        if (! $token
            || (string) $token->tokenable_id !== $userId
            || ! $token->identity_project_id) {
            throw new IdentityAuthenticationException('Invalid access token.');
        }

        $user = User::query()->findOrFail($userId);
        $project = IdentityProject::query()->findOrFail($token->identity_project_id);
        $client = $this->projectClients->findForProjectOrFail(
            $project->id,
            (string) $token->identity_client_id,
        );
        $membership = $this->memberships->findActiveForProjectUser($project->id, $userId);

        if ($membership === null) {
            throw new IdentityResourceNotFoundException('Identity project membership');
        }

        return $this->payloads->identity($user, $project, $client, $membership);
    }

    public function listSessions(string $userId, string $accessToken): array
    {
        $currentToken = $this->identityAccessToken($accessToken);
        $currentFamily = $currentToken->identity_refresh_family_id;

        return IdentityRefreshToken::query()
            ->where('user_id', $userId)
            ->where('project_id', $currentToken->identity_project_id)
            ->where('client_id', $currentToken->identity_client_id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->unique('family_id')
            ->map(function (IdentityRefreshToken $token) use ($currentFamily): array {
                $project = IdentityProject::query()->find($token->project_id);
                $client = $project
                    ? $this->projectClients->findForProject($project->id, $token->client_id)
                    : null;

                return [
                    'id' => $token->family_id,
                    'current' => $token->family_id === $currentFamily,
                    'project' => $project ? $this->payloads->project($project) : null,
                    'client' => $client ? $this->payloads->client($client) : null,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'expires_at' => $token->expires_at->toIso8601String(),
                ];
            })->values()->all();
    }

    public function revokeSession(string $userId, string $familyId, string $accessToken): void
    {
        $currentToken = $this->identityAccessToken($accessToken);
        $owned = IdentityRefreshToken::query()
            ->where('user_id', $userId)
            ->where('family_id', $familyId)
            ->where('project_id', $currentToken->identity_project_id)
            ->where('client_id', $currentToken->identity_client_id)
            ->exists();

        if (! $owned) {
            throw new IdentityAuthorizationException(
                'The session does not belong to the authenticated user.',
            );
        }

        $this->tokens->revokeFamily($familyId);
        $this->audit->record(
            'auth.session_revoked',
            null,
            null,
            $userId,
            'session',
            $familyId,
        );
    }

    private function identityAccessToken(string $accessToken): PersonalAccessToken
    {
        $token = PersonalAccessToken::findToken($accessToken);
        if (! $token || ! $token->identity_project_id || ! $token->identity_client_id) {
            throw new IdentityAuthenticationException('Invalid access token.');
        }

        return $token;
    }

    public function acceptInvitation(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $invitation = IdentityProjectInvitation::query()
                ->where(
                    'token_hash',
                    hash('sha256', (string) $attributes['invitation_token']),
                )
                ->lockForUpdate()
                ->first();

            if (! $invitation
                || $invitation->accepted_at !== null
                || $invitation->expires_at->isPast()) {
                throw new IdentityAuthenticationException('Invitation is invalid or expired.');
            }

            $user = User::query()
                ->whereRaw('lower(email) = ?', [Str::lower($invitation->email)])
                ->first();

            if (! $user) {
                $user = User::query()->create([
                    'id' => (string) Str::uuid(),
                    'username' => $attributes['username'],
                    'email' => $invitation->email,
                    'password' => Str::random(64),
                    'terms' => 'accepted',
                    'email_verified_at' => now(),
                ]);
            }

            if (! hash_equals((string) $attributes['password'], (string) $attributes['password_confirmation'])) {
                throw new IdentityAuthenticationException('The password confirmation does not match.');
            }
            if (IdentityProjectAccount::query()->where('project_id', $invitation->project_id)->where('user_id', $user->id)->exists()) {
                throw new IdentityAuthenticationException('This email already has an account in the project. Sign in instead.');
            }

            $projectId = IdentityProjectId::fromString($invitation->project_id);
            $userId = new UserId((string) $user->id);
            $membershipAggregate = $this->membershipAggregates->findForProjectUser(
                $projectId,
                $userId,
            ) ?? DomainIdentityMembership::create(
                $projectId,
                $userId,
                (bool) $invitation->is_admin,
            );
            $membershipAggregate->acceptInvitation((bool) $invitation->is_admin);
            $this->membershipAggregates->save($membershipAggregate);
            $membership = $this->memberships->findForProjectOrFail(
                $invitation->project_id,
                $membershipAggregate->id()->toString(),
            );
            $account = IdentityProjectAccount::query()->create([
                'project_id' => $invitation->project_id,
                'user_id' => $user->id,
                'username' => (string) $attributes['username'],
                'password' => (string) $attributes['password'],
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ]);
            if ($invitation->hosted_application_id !== null) {
                IdentityHostedApplicationConsent::query()->create([
                    'hosted_application_id' => $invitation->hosted_application_id,
                    'user_id' => $user->id,
                    'project_account_id' => $account->id,
                    'accepted_at' => now(),
                ]);
            }
            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->audit->record(
                'invitation.accepted',
                $invitation->project_id,
                null,
                $user->id,
                'membership',
                $membership->id,
            );

            return [
                'membership_id' => $membership->id,
                'project_id' => $membership->project_id,
                'user_id' => $user->id,
            ];
        });
    }

    public function syncOwnPermissionManifest(
        string $clientId,
        string $clientSecret,
        array $manifest,
    ): array {
        $client = $this->clients->authenticate($clientId, $clientSecret);
        $permissions = $this->permissionManifests->sync(
            $client->project_id,
            $client->id,
            $manifest,
        );
        $this->audit->record(
            'permission_manifest.synced',
            $client->project_id,
            $client->id,
            null,
            'client',
            $client->id,
            ['permission_count' => count($manifest)],
        );

        return $permissions;
    }

    private function issueEmailVerification(IdentityProjectAccount $account, User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $account->forceFill([
            'email_verification_code_hash' => $this->secretHash($code),
            'email_verification_expires_at' => now()->addMinutes(
                (int) config('zolta.identity.email_verification_ttl_minutes', 15),
            ),
        ])->save();
        $user->notify(new VerifyEmailCode($code));

        return $code;
    }

    private function secretHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
