<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\CreateIdentityProject;
use App\Services\UserManagementService\Application\Contracts\OAuthGateway;
use App\Services\UserManagementService\Application\DTOs\External\OAuthUser;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use App\Services\UserManagementService\Infrastructure\Jobs\DeliverIdentityWebhook;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAccessCatalogPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAccessCatalogRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAuthorizationIntent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityLogoutIntent;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectMembership;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectPermission;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectRole;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityRefreshToken;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookDelivery;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookEndpoint;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Notifications\ResetIdentityPassword;
use App\Services\UserManagementService\Infrastructure\Webhooks\IdentityWebhookPublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;
use Zolta\Exceptions\ValidationException;

final class IdentityAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_resolve_the_project_authentication_experience(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill([
            'mode' => 'sandbox',
            'sandbox_ttl_minutes' => 45,
        ])->save();

        $this->postJson('/api/v1/identity/auth/context', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
        ])->assertOk()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.project.mode', 'sandbox')
            ->assertJsonPath('data.project.sandbox_ttl_minutes', 45)
            ->assertJsonPath('data.client.id', $client->id);

        $this->postJson('/api/v1/identity/auth/context', [
            'project' => 'another-project',
            'client_id' => $client->id,
            'client_secret' => $secret,
        ])->assertUnauthorized();
    }

    public function test_login_refresh_and_introspection_return_current_project_grants(): void
    {
        [$user, $project, $client, $secret, $membership] = $this->identityFixture();
        $permission = IdentityProjectPermission::query()->create([
            'project_id' => $project->id,
            'key' => 'documents.read',
            'name' => 'Read documents',
            'source' => 'manual',
            'status' => 'active',
        ]);
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Editor',
            'slug' => 'editor',
        ]);
        $role->permissions()->attach($permission);
        $membership->roles()->attach($role);

        $login = $this->login($user, $project, $client, $secret);
        $this->assertSame(['editor'], $login['identity']['membership']['roles']);

        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $login['access_token'],
        ])->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('sub', $user->id)
            ->assertJsonPath('email_verified', true)
            ->assertJsonPath('permissions.0', 'documents.read');

        $refresh = $this->postJson('/api/v1/identity/auth/refresh', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'refresh_token' => $login['refresh_token'],
        ])->assertOk()->json('data');
        $this->assertNotSame($login['refresh_token'], $refresh['refresh_token']);
    }

    public function test_system_admin_can_bootstrap_access_to_any_project_with_the_global_password(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $user->forceFill([
            'is_system_admin' => true,
            'password' => 'correct-password',
        ])->save();
        IdentityProjectAccount::query()->where('project_id', $project->id)->where('user_id', $user->id)->delete();
        IdentityProjectMembership::query()->where('project_id', $project->id)->where('user_id', $user->id)->delete();

        $this->postJson('/api/v1/identity/auth/login', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('data.identity.user.is_system_admin', true)
            ->assertJsonPath('data.identity.project.id', $project->id)
            ->assertJsonPath('data.identity.membership.is_admin', true);

        $this->assertDatabaseHas('identity_project_accounts', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('identity_project_memberships', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'is_admin' => true,
            'status' => 'active',
        ]);
    }

    public function test_refresh_token_replay_revokes_the_token_family(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $login = $this->login($user, $project, $client, $secret);
        $rotated = $this->postJson('/api/v1/identity/auth/refresh', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'refresh_token' => $login['refresh_token'],
        ])->assertOk()->json('data');

        $this->postJson('/api/v1/identity/auth/refresh', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'refresh_token' => $login['refresh_token'],
        ])->assertUnauthorized();
        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $rotated['access_token'],
        ])->assertOk()->assertJsonPath('active', false);
    }

    public function test_logout_revokes_only_the_current_refresh_family(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $firstSession = $this->login($user, $project, $client, $secret);
        $secondSession = $this->login($user, $project, $client, $secret);

        $this->withToken($firstSession['access_token'])
            ->postJson('/api/v1/identity/auth/logout')
            ->assertOk();

        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $firstSession['access_token'],
        ])->assertOk()->assertJsonPath('active', false);
        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $secondSession['access_token'],
        ])->assertOk()->assertJsonPath('active', true);
        $this->postJson('/api/v1/identity/auth/refresh', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'refresh_token' => $firstSession['refresh_token'],
        ])->assertUnauthorized();
        $this->postJson('/api/v1/identity/auth/refresh', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'refresh_token' => $secondSession['refresh_token'],
        ])->assertOk();
    }

    public function test_hosted_authentication_handoff_is_client_bound_and_single_use(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $login = $this->login($user, $project, $client, $secret);
        $callback = 'http://localhost:3000/api/auth/callback';

        $code = $this->withToken($login['access_token'])
            ->postJson('/api/v1/identity/auth/handoff', [
                'client_id' => $client->id,
                'client_secret' => $secret,
                'redirect_uri' => $callback,
            ])
            ->assertCreated()
            ->json('data.code');

        $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => 'http://localhost:3000/a-different-callback',
        ])->assertUnauthorized();

        $exchange = $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => $callback,
        ])->assertOk()->json('data');

        $this->assertSame($user->id, $exchange['identity']['user']['id']);
        $this->assertNotSame($login['access_token'], $exchange['access_token']);

        $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => $callback,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $login['access_token'],
        ])->assertOk()->assertJsonPath('active', false);
    }

    public function test_logout_intent_is_client_bound_local_path_only_and_single_use(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'key' => 'example-app',
            'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test/dashboard',
            'callback_url' => 'https://example-app.example.test/api/identity/example-app/auth/callback',
            'status' => 'active',
        ]);
        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');

        $this->postJson('/api/v1/identity/auth/logout/intent', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'hosted_application' => 'example-app',
            'return_to' => 'https://attacker.example',
        ])->assertUnprocessable();

        $intent = $this->postJson('/api/v1/identity/auth/logout/intent', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'hosted_application' => 'example-app',
            'return_to' => '/auth/login',
        ])->assertCreated()->json('data.intent');

        $consume = fn () => $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app/auth/logout/intent/consume', ['intent' => $intent]);

        $consume()->assertOk()->assertJsonPath('data.redirect_url', 'https://example-app.example.test/auth/login');
        $consume()->assertUnauthorized();

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app/auth/logout/intent/consume', [
                'intent' => Str::random(96),
            ])->assertUnauthorized();

        $expired = $this->postJson('/api/v1/identity/auth/logout/intent', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'hosted_application' => 'example-app',
            'return_to' => '/auth/login',
        ])->assertCreated()->json('data.intent');
        IdentityLogoutIntent::query()->where('intent_hash', hash('sha256', $expired))
            ->update(['expires_at' => now()->subSecond()]);
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app/auth/logout/intent/consume', [
                'intent' => $expired,
            ])->assertUnauthorized();
    }

    public function test_intent_creation_throttles_are_isolated_from_other_api_traffic(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'key' => 'example-app',
            'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test/dashboard',
            'callback_url' => 'https://example-app.example.test/api/identity/example-app/auth/callback',
            'status' => 'active',
        ]);

        $clientCredentials = [
            'client_id' => $client->id,
            'client_secret' => $secret,
        ];

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/identity/auth/context', $clientCredentials)
                ->assertOk();
        }

        $this->postJson('/api/v1/identity/auth/authorization/intent', [
            ...$clientCredentials,
            'hosted_application' => 'example-app',
            'state' => Str::random(48),
            'demo_account_enabled' => false,
        ])->assertCreated();

        $this->postJson('/api/v1/identity/auth/logout/intent', [
            ...$clientCredentials,
            'hosted_application' => 'example-app',
            'return_to' => '/en/auth/login',
        ])->assertCreated();
    }

    public function test_authorization_intent_carries_the_client_bound_demo_policy_once(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $sandboxProject = IdentityProject::query()->create([
            'name' => 'Example Application sandbox',
            'slug' => 'example-app-sandbox-'.Str::random(6),
            'status' => 'active',
            'mode' => 'sandbox',
            'sandbox_ttl_minutes' => 60,
            'registration_mode' => 'invite_only',
        ]);
        $sandboxSecret = Str::random(64);
        $sandboxClient = IdentityProjectClient::query()->create([
            'project_id' => $sandboxProject->id,
            'name' => 'Example Application sandbox BFF',
            'secret_hash' => hash('sha256', $sandboxSecret),
            'secret_prefix' => Str::substr($sandboxSecret, 0, 8),
            'status' => 'active',
        ]);
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'sandbox_client_id' => $sandboxClient->id,
            'key' => 'example-app-auth',
            'name' => 'Example Application Auth',
            'application_url' => 'https://example-app.example.test',
            'callback_url' => 'https://example-app.example.test/api/identity/example-app/auth/callback',
            'status' => 'active',
        ]);
        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');
        $state = Str::random(48);

        $intent = $this->postJson('/api/v1/identity/auth/authorization/intent', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'hosted_application' => 'example-app-auth',
            'state' => $state,
            'demo_account_enabled' => true,
        ])->assertCreated()->json('data.intent');

        $consume = fn () => $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app-auth/auth/authorization/intent/consume', [
                'intent' => $intent,
            ]);

        $consume()->assertOk()
            ->assertJsonPath('data.state', $state)
            ->assertJsonPath('data.features.demo_account', true);
        $consume()->assertUnauthorized();

        $disabledIntent = $this->postJson('/api/v1/identity/auth/authorization/intent', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'hosted_application' => 'example-app-auth',
            'state' => Str::random(48),
            'demo_account_enabled' => false,
        ])->assertCreated()->json('data.intent');

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app-auth/auth/authorization/intent/consume', [
                'intent' => $disabledIntent,
            ])->assertOk()->assertJsonPath('data.features.demo_account', false);

        $withoutSandboxSecret = Str::random(64);
        $withoutSandboxClient = IdentityProjectClient::query()->create([
            'project_id' => $project->id,
            'name' => 'Example Application without sandbox BFF',
            'secret_hash' => hash('sha256', $withoutSandboxSecret),
            'secret_prefix' => Str::substr($withoutSandboxSecret, 0, 8),
            'status' => 'active',
        ]);
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $withoutSandboxClient->id,
            'key' => 'example-app-without-sandbox',
            'name' => 'Example Application without sandbox',
            'application_url' => 'https://example-app.example.test',
            'callback_url' => 'https://example-app.example.test/api/identity/example-app/auth/callback',
            'status' => 'active',
        ]);
        $unavailableIntent = $this->postJson('/api/v1/identity/auth/authorization/intent', [
            'client_id' => $withoutSandboxClient->id,
            'client_secret' => $withoutSandboxSecret,
            'hosted_application' => 'example-app-without-sandbox',
            'state' => Str::random(48),
            'demo_account_enabled' => true,
        ])->assertCreated()->json('data.intent');

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app-without-sandbox/auth/authorization/intent/consume', [
                'intent' => $unavailableIntent,
            ])->assertOk()->assertJsonPath('data.features.demo_account', false);

        $this->assertSame(3, IdentityAuthorizationIntent::query()->count());
    }

    public function test_account_portal_intent_still_authorizes_entry_without_authenticating_the_hosted_session(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $login = $this->login($user, $project, $client, $secret);
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'key' => 'account-portal',
            'name' => 'Account portal',
            'application_url' => 'https://example-app.example.test',
            'callback_url' => 'https://example-app.example.test/api/identity/account-portal/auth/callback',
            'status' => 'active',
        ]);
        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');

        $intent = $this->withToken($login['access_token'])
            ->postJson('/api/v1/identity/auth/account/intent', [
                'client_id' => $client->id,
                'client_secret' => $secret,
                'hosted_application' => 'account-portal',
            ])->assertCreated()->json('data.intent');

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/account-portal/auth/account/intent/consume', [
                'intent' => $intent,
            ])->assertOk()->assertJsonPath('data.authorized', true);
    }

    public function test_public_project_registration_assigns_the_default_role(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Member',
            'slug' => 'member',
        ]);
        $project->forceFill([
            'registration_mode' => 'public',
            'registration_role_id' => $role->id,
            'email_verification_required' => true,
        ])->save();

        $this->postJson('/api/v1/identity/auth/register', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'username' => 'New member',
            'email' => 'new-member@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated()
            ->assertJsonPath('data.identity.membership.roles.0', 'member')
            ->assertJsonPath('data.identity.user.email_verified', false);
    }

    public function test_invite_only_project_rejects_public_registration(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $this->postJson('/api/v1/identity/auth/register', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'username' => 'No access',
            'email' => 'no-access@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertForbidden();
    }

    public function test_project_can_allow_public_registration_without_email_verification(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill([
            'registration_mode' => 'public',
            'email_verification_required' => false,
        ])->save();

        $this->postJson('/api/v1/identity/auth/register', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'username' => 'Verified member',
            'email' => 'verified-member@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated()
            ->assertJsonPath('data.identity.user.email_verified', true);
    }

    public function test_sandbox_client_creates_a_temporary_identity_without_credentials(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Demo member',
            'slug' => 'demo-member',
        ]);
        $project->forceFill([
            'mode' => 'sandbox',
            'sandbox_ttl_minutes' => 60,
            'registration_role_id' => $role->id,
        ])->save();

        $session = $this->postJson('/api/v1/identity/auth/sandbox-session', [
            'client_id' => $client->id,
            'client_secret' => $secret,
        ])->assertCreated()
            ->assertJsonPath('data.is_temporary', true)
            ->assertJsonPath('data.identity.project.mode', 'sandbox')
            ->assertJsonPath('data.identity.membership.roles.0', 'demo-member')
            ->json('data');

        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $session['access_token'],
        ])->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('project_slug', $project->slug)
            ->assertJsonPath('project_mode', 'sandbox')
            ->assertJsonPath('is_temporary', true);

        $temporary = User::query()->findOrFail($session['identity']['user']['id']);
        $this->assertTrue($temporary->is_temporary);
        $this->assertNotNull($temporary->email_verified_at);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $temporary->demo_expires_at?->getTimestamp(), 5);
    }

    public function test_hosted_sandbox_handoff_is_bound_to_the_sandbox_client(): void
    {
        [, $project, $sandboxClient, $sandboxSecret] = $this->identityFixture();
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Demo member',
            'slug' => 'demo-member',
        ]);
        $project->forceFill([
            'mode' => 'sandbox',
            'sandbox_ttl_minutes' => 60,
            'registration_role_id' => $role->id,
        ])->save();
        $primarySecret = Str::random(64);
        $primaryClient = IdentityProjectClient::query()->create([
            'project_id' => $project->id,
            'name' => 'Live BFF',
            'secret_hash' => hash('sha256', $primarySecret),
            'secret_prefix' => Str::substr($primarySecret, 0, 8),
            'status' => 'active',
        ]);
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $primaryClient->id,
            'sandbox_client_id' => $sandboxClient->id,
            'key' => 'demo-app',
            'name' => 'Demo App',
            'application_url' => 'https://demo.example.test',
            'callback_url' => 'https://demo.example.test/api/auth/callback',
            'status' => 'active',
        ]);
        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');

        $session = $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/demo-app/auth/sandbox-session')
            ->assertCreated()
            ->json('data');

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->withToken($session['access_token'])
            ->postJson('/api/v1/identity/hosted-applications/demo-app/auth/handoff', ['connection' => 'primary'])
            ->assertUnauthorized();

        $code = $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->withToken($session['access_token'])
            ->postJson('/api/v1/identity/hosted-applications/demo-app/auth/handoff', ['connection' => 'sandbox'])
            ->assertCreated()
            ->json('data.code');

        $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $sandboxClient->id,
            'client_secret' => $sandboxSecret,
            'code' => $code,
            'redirect_uri' => 'https://demo.example.test/api/auth/callback',
        ])->assertOk();
    }

    public function test_live_project_rejects_sandbox_sessions(): void
    {
        [, , $client, $secret] = $this->identityFixture();

        $this->postJson('/api/v1/identity/auth/sandbox-session', [
            'client_id' => $client->id,
            'client_secret' => $secret,
        ])->assertForbidden();
    }

    public function test_identity_cleanup_webhooks_are_queued_for_subscribed_project_endpoints(): void
    {
        Queue::fake();
        [, $project] = $this->identityFixture();
        IdentityWebhookEndpoint::query()->create([
            'project_id' => $project->id,
            'url' => 'http://localhost:8000/api/webhooks/identity',
            'events' => ['identity.user.expired'],
            'secret' => Str::random(64),
            'secret_prefix' => 'test',
            'status' => 'active',
        ]);
        $userId = (string) Str::uuid();

        app(IdentityWebhookPublisher::class)->publish($project->id, 'identity.user.expired', [
            'user_id' => $userId,
        ]);

        $delivery = IdentityWebhookDelivery::query()->firstOrFail();
        $this->assertSame('identity.user.expired', $delivery->event);
        $this->assertSame($userId, $delivery->payload['data']['user_id']);
        Queue::assertPushed(DeliverIdentityWebhook::class);
    }

    public function test_registered_user_can_resend_and_verify_email(): void
    {
        config()->set('zolta.identity.expose_development_tokens', true);
        [, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill(['registration_mode' => 'public'])->save();
        $registration = $this->postJson('/api/v1/identity/auth/register', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'username' => 'Verify me',
            'email' => 'verify-me@example.com',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated()->json('data');

        $code = $this->withToken($registration['access_token'])
            ->postJson('/api/v1/identity/auth/email/verification/resend')
            ->assertOk()
            ->json('data.development_code');
        $this->withToken($registration['access_token'])
            ->postJson('/api/v1/identity/auth/email/verification', ['code' => $code])
            ->assertOk();
        $this->assertNotNull(IdentityProjectAccount::query()
            ->where('project_id', $project->id)
            ->whereHas('user', fn ($query) => $query->where('email', 'verify-me@example.com'))
            ->value('email_verified_at'));
    }

    public function test_password_reset_revokes_existing_sessions(): void
    {
        config()->set('zolta.identity.expose_development_tokens', true);
        [$user, $project, $client, $secret] = $this->identityFixture();
        $login = $this->login($user, $project, $client, $secret);
        $token = $this->postJson('/api/v1/identity/auth/password/forgot', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $user->email,
        ])->assertOk()->json('data.development_token');

        $this->postJson('/api/v1/identity/auth/password/reset', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $user->email,
            'token' => $token,
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertOk();
        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'token' => $login['access_token'],
        ])->assertOk()->assertJsonPath('active', false);
        $this->postJson('/api/v1/identity/auth/login', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $user->email,
            'password' => 'replacement-password',
        ])->assertOk();
    }

    public function test_same_email_has_independent_project_accounts_and_passwords(): void
    {
        [, $firstProject, $firstClient, $firstSecret] = $this->identityFixture();
        [, $secondProject, $secondClient, $secondSecret] = $this->identityFixture();
        $firstProject->forceFill(['registration_mode' => 'public'])->save();
        $secondProject->forceFill(['registration_mode' => 'public'])->save();

        foreach ([
            [$firstProject, $firstClient, $firstSecret, 'First profile', 'first-project-password'],
            [$secondProject, $secondClient, $secondSecret, 'Second profile', 'second-project-password'],
        ] as [$project, $client, $secret, $username, $password]) {
            $this->postJson('/api/v1/identity/auth/register', [
                'project' => $project->slug,
                'client_id' => $client->id,
                'client_secret' => $secret,
                'username' => $username,
                'email' => 'shared-email@example.com',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertCreated();
        }

        $user = User::query()->where('email', 'shared-email@example.com')->sole();
        $this->assertDatabaseCount('identity_project_accounts', 4);
        $this->assertDatabaseHas('identity_project_accounts', ['project_id' => $firstProject->id, 'user_id' => $user->id, 'username' => 'First profile']);
        $this->assertDatabaseHas('identity_project_accounts', ['project_id' => $secondProject->id, 'user_id' => $user->id, 'username' => 'Second profile']);

        $this->postJson('/api/v1/identity/auth/login', [
            'project' => $firstProject->slug,
            'client_id' => $firstClient->id,
            'client_secret' => $firstSecret,
            'email' => $user->email,
            'password' => 'second-project-password',
        ])->assertUnauthorized();
        $this->postJson('/api/v1/identity/auth/login', [
            'project' => $secondProject->slug,
            'client_id' => $secondClient->id,
            'client_secret' => $secondSecret,
            'email' => $user->email,
            'password' => 'second-project-password',
        ])->assertOk()->assertJsonPath('data.identity.user.username', 'Second profile');
    }

    public function test_recovery_rotates_owner_credentials_without_erasing_identity_data(): void
    {
        $recoveryKey = 'test-recovery-key-that-is-never-stored-in-the-database';
        putenv('IDENTITY_RECOVERY_KEY='.$recoveryKey);

        $this->artisan('identity:bootstrap', [
            'email' => 'owner@example.com',
            '--name' => 'Owner',
            '--password' => 'original-owner-password',
        ])->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $owner->createToken('existing-session');
        $account = IdentityProjectAccount::query()->where('user_id', $owner->getKey())->firstOrFail();
        $projectCount = IdentityProject::query()->count();

        $this->artisan('identity:recover-owner', ['--email' => 'owner@example.com'])
            ->expectsQuestion('Recovery key', $recoveryKey)
            ->expectsQuestion('New owner password', 'recovered-owner-password')
            ->expectsQuestion('Confirm new owner password', 'recovered-owner-password')
            ->assertSuccessful();

        $owner->refresh();
        $account->refresh();
        $this->assertTrue(Hash::check('recovered-owner-password', $owner->password));
        $this->assertTrue(Hash::check('recovered-owner-password', $account->password));
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertSame($projectCount, IdentityProject::query()->count());
        $this->assertDatabaseHas('identity_audit_events', [
            'event' => 'auth.identity_owner_recovered',
            'target_id' => $owner->getKey(),
        ]);

        putenv('IDENTITY_RECOVERY_KEY');
    }

    public function test_bootstrap_creates_owner_console_project_and_client(): void
    {
        $this->artisan('identity:bootstrap', [
            'email' => 'owner@example.com',
            '--name' => 'Owner',
            '--password' => 'strong-password-123',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_system_admin' => true]);
        $this->assertDatabaseHas('identity_projects', [
            'slug' => 'identity-console',
            'registration_mode' => 'invite_only',
        ]);
        $this->assertDatabaseCount('identity_project_clients', 1);
    }

    public function test_bootstrapped_owner_can_login_to_console_project(): void
    {
        $clientId = '02e92e58-0e50-4681-9d57-b122cac61b77';
        $clientSecret = 'stable-local-console-client-secret-1234567890';
        $password = 'strong-password-123';

        $this->artisan('identity:bootstrap', [
            'email' => 'owner@example.com',
            '--name' => 'Owner',
            '--password' => $password,
            '--client-id' => $clientId,
            '--client-secret' => $clientSecret,
        ])->assertSuccessful();

        $this->assertDatabaseHas('identity_project_accounts', [
            'username' => 'Owner',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/identity/auth/login', [
            'project' => 'identity-console',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'email' => 'owner@example.com',
            'password' => $password,
        ])->assertOk()
            ->assertJsonPath('data.identity.user.email', 'owner@example.com')
            ->assertJsonPath('data.identity.user.username', 'Owner');
    }

    public function test_bootstrap_accepts_stable_console_credentials_and_is_idempotent_when_requested(): void
    {
        $clientId = '02e92e58-0e50-4681-9d57-b122cac61b77';
        $clientSecret = 'stable-local-console-client-secret-1234567890';
        $arguments = [
            'email' => 'owner@example.com',
            '--name' => 'Owner',
            '--password' => 'strong-password-123',
            '--client-id' => $clientId,
            '--client-secret' => $clientSecret,
            '--if-needed' => true,
        ];

        $this->artisan('identity:bootstrap', $arguments)->assertSuccessful();
        $this->artisan('identity:bootstrap', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('identity_project_clients', 1);
        $this->assertDatabaseHas('identity_project_clients', [
            'id' => $clientId,
            'secret_hash' => hash('sha256', $clientSecret),
        ]);
    }

    public function test_if_needed_repairs_an_existing_installation_missing_console_resources(): void
    {
        User::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner@example.com',
            'username' => 'Owner',
            'password' => Hash::make('old-owner-password'),
            'terms' => 'accepted',
            'email_verified_at' => now(),
            'is_system_admin' => true,
        ]);

        $this->artisan('identity:bootstrap', [
            'email' => 'owner@example.com',
            '--name' => 'Owner',
            '--password' => 'strong-password-123',
            '--client-id' => '02e92e58-0e50-4681-9d57-b122cac61b77',
            '--client-secret' => 'stable-local-console-client-secret-1234567890',
            '--if-needed' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('identity_projects', ['slug' => 'identity-console']);
        $this->assertDatabaseHas('identity_project_clients', [
            'id' => '02e92e58-0e50-4681-9d57-b122cac61b77',
            'name' => 'Identity Console BFF',
        ]);
        $this->assertDatabaseHas('identity_project_accounts', [
            'username' => 'Owner',
            'status' => 'active',
        ]);
    }

    public function test_client_from_another_project_cannot_introspect_a_token(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $login = $this->login($user, $project, $client, $secret);
        [, , $otherClient, $otherSecret] = $this->identityFixture();

        $this->postJson('/api/v1/identity/auth/introspect', [
            'client_id' => $otherClient->id,
            'client_secret' => $otherSecret,
            'token' => $login['access_token'],
        ])->assertOk()->assertJsonPath('active', false);
    }

    public function test_project_administrator_only_lists_and_reads_their_projects_resources(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        [, $otherProject] = $this->identityFixture();
        IdentityProjectRole::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other project role',
            'slug' => 'other-project-role',
        ]);
        IdentityProjectPermission::query()->create([
            'project_id' => $otherProject->id,
            'key' => 'other.read',
            'name' => 'Other project permission',
            'source' => 'manual',
            'status' => 'active',
        ]);
        IdentityWebhookEndpoint::query()->create([
            'project_id' => $otherProject->id,
            'url' => 'https://other.example.com/identity',
            'events' => ['identity.user.expired'],
            'secret' => Str::random(64),
            'secret_prefix' => 'other',
            'status' => 'active',
        ]);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $this->withToken($accessToken)
            ->getJson('/api/v1/identity/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);

        $this->withToken($accessToken)
            ->getJson("/api/v1/identity/projects/{$project->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.clients')
            ->assertJsonCount(1, 'data.memberships')
            ->assertJsonCount(0, 'data.roles')
            ->assertJsonCount(0, 'data.permissions')
            ->assertJsonCount(0, 'data.webhooks');

        $this->withToken($accessToken)
            ->getJson("/api/v1/identity/projects/{$otherProject->id}")
            ->assertForbidden();
    }

    public function test_project_lifecycle_writes_use_the_explicit_project_domain_commands(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $administrator->forceFill(['is_system_admin' => true])->save();
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $createdProjectId = $this->withToken($accessToken)
            ->postJson('/api/v1/identity/projects', [
                'name' => 'New Identity Project',
                'slug' => 'new-identity-project',
                'description' => 'Created through the project aggregate.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.mode', 'live')
            ->assertJsonPath('data.registration_mode', 'invite_only')
            ->json('data.id');

        $this->withToken($accessToken)
            ->patchJson("/api/v1/identity/projects/{$createdProjectId}/registration", [
                'registration_mode' => 'public',
                'registration_role_id' => null,
                'email_verification_required' => false,
            ])
            ->assertOk();

        $this->withToken($accessToken)
            ->patchJson("/api/v1/identity/projects/{$createdProjectId}/environment", [
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 120,
            ])
            ->assertOk();

        $created = IdentityProject::query()->findOrFail($createdProjectId);
        $this->assertSame('public', $created->registration_mode);
        $this->assertFalse($created->email_verification_required);
        $this->assertSame('sandbox', $created->mode);
        $this->assertSame(120, $created->sandbox_ttl_minutes);
        $this->assertDatabaseHas('identity_project_memberships', [
            'project_id' => $createdProjectId,
            'user_id' => $administrator->id,
            'is_admin' => true,
        ]);
    }

    public function test_project_creation_maps_a_duplicate_slug_constraint_to_validation(): void
    {
        [$administrator] = $this->identityFixture(true);
        $administrator->forceFill(['is_system_admin' => true])->save();
        $projects = app(CreateIdentityProject::class);
        $attributes = [
            'name' => 'Duplicate slug project',
            'slug' => 'duplicate-slug-project',
            'description' => null,
        ];

        $projects->createProject((string) $administrator->id, $attributes);

        try {
            $projects->createProject((string) $administrator->id, $attributes);
            $this->fail('Expected the database unique constraint to be mapped to validation.');
        } catch (ValidationException $exception) {
            $this->assertSame('The slug has already been taken.', $exception->getErrors()['slug']);
        }

        $this->assertDatabaseCount('identity_projects', 2);
    }

    public function test_system_administrator_can_schedule_and_cancel_project_deletion(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $administrator->forceFill(['is_system_admin' => true])->save();
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $projectId = $this->withToken($accessToken)
            ->postJson('/api/v1/identity/projects', [
                'name' => 'Disposable Project',
                'slug' => 'disposable-project',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$projectId}", ['confirmation' => 'disposable-project'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending_deletion')
            ->assertJsonPath('data.deletion_scheduled_at', fn (?string $value): bool => $value !== null);
        $this->assertDatabaseHas('identity_projects', ['id' => $projectId, 'status' => 'pending_deletion']);

        $this->withToken($accessToken)
            ->patchJson("/api/v1/identity/projects/{$projectId}/environment", [
                'mode' => 'sandbox',
                'sandbox_ttl_minutes' => 60,
            ])
            ->assertConflict();

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$projectId}/deletion/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('identity_projects', ['id' => $projectId, 'status' => 'active']);
    }

    public function test_system_administrator_can_suspend_a_project_and_revoke_its_sessions(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $administrator->forceFill(['is_system_admin' => true])->save();
        $session = $this->login($administrator, $project, $client, $secret);
        $accessToken = $session['access_token'];

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/suspension", ['confirmation' => $project->slug])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('identity_projects', ['id' => $project->id, 'status' => 'suspended']);
        $this->assertNull(PersonalAccessToken::findToken($accessToken));
        $this->assertDatabaseHas('identity_refresh_tokens', [
            'project_id' => $project->id,
            'token_hash' => hash('sha256', $session['refresh_token']),
        ]);
        $this->assertNotNull(
            IdentityRefreshToken::query()
                ->where('project_id', $project->id)
                ->value('revoked_at'),
        );

        $this->postJson('/api/v1/identity/auth/login', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $administrator->email,
            'password' => 'correct-password',
        ])->assertUnauthorized();
    }

    public function test_project_administrator_cannot_suspend_a_project(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/suspension", ['confirmation' => $project->slug])
            ->assertForbidden();
    }

    public function test_project_administrator_can_use_catalog_items_created_by_a_system_administrator(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $administrator->forceFill(['is_system_admin' => true])->save();
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $permission = $this->withToken($accessToken)
            ->postJson('/api/v1/identity/project-access-catalog/permissions', [
                'key' => 'documents.read', 'name' => 'Read documents',
            ])->assertCreated()->json('data');
        $role = $this->withToken($accessToken)
            ->postJson('/api/v1/identity/project-access-catalog/roles', [
                'name' => 'Reader', 'slug' => 'reader', 'permission_ids' => [$permission['id']],
            ])->assertCreated()->json('data');

        $administrator->forceFill(['is_system_admin' => false])->save();
        $this->withToken($accessToken)
            ->getJson('/api/v1/identity/project-access-catalog')
            ->assertOk()
            ->assertJsonPath('data.roles.0.id', $role['id']);

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/access-catalog/import", [
                'permission_ids' => [], 'role_ids' => [$role['id']],
            ])->assertOk();

        $this->assertDatabaseHas('identity_project_permissions', [
            'project_id' => $project->id, 'key' => 'documents.read', 'catalog_permission_id' => $permission['id'], 'catalog_origin' => 'imported',
        ]);
        $this->assertDatabaseHas('identity_project_roles', [
            'project_id' => $project->id, 'slug' => 'reader', 'catalog_role_id' => $role['id'], 'catalog_origin' => 'imported',
        ]);
    }

    public function test_client_and_webhook_lifecycle_writes_preserve_the_public_api_contract(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $createdClient = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", [
                'name' => 'Example Application API',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data');
        $this->assertNotEmpty($createdClient['client_secret']);
        $this->assertSame(
            substr($createdClient['client_secret'], 0, 8),
            $createdClient['secret_prefix'],
        );

        $rotatedClientSecret = $this->withToken($accessToken)
            ->postJson(
                "/api/v1/identity/projects/{$project->id}/clients/{$createdClient['id']}/rotate-secret",
            )
            ->assertOk()
            ->json('data.client_secret');
        $this->assertNotSame($createdClient['client_secret'], $rotatedClientSecret);

        $this->withToken($accessToken)
            ->patchJson(
                "/api/v1/identity/projects/{$project->id}/clients/{$createdClient['id']}",
                ['status' => 'disabled'],
            )
            ->assertOk();
        $this->assertDatabaseHas('identity_project_clients', [
            'id' => $createdClient['id'],
            'project_id' => $project->id,
            'status' => 'disabled',
        ]);

        $createdWebhook = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/webhooks", [
                'url' => 'https://example-app.example.com/webhooks/identity',
                'events' => ['identity.user.expired'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data');
        $this->assertNotEmpty($createdWebhook['secret']);
        $this->assertSame(
            substr($createdWebhook['secret'], 0, 8),
            $createdWebhook['secret_prefix'],
        );

        $this->withToken($accessToken)
            ->putJson(
                "/api/v1/identity/projects/{$project->id}/webhooks/{$createdWebhook['id']}",
                [
                    'url' => 'https://example-app.example.com/webhooks/accounts',
                    'events' => ['identity.user.deletion_requested'],
                    'status' => 'disabled',
                ],
            )
            ->assertOk();

        $rotatedWebhookSecret = $this->withToken($accessToken)
            ->postJson(
                "/api/v1/identity/projects/{$project->id}/webhooks/{$createdWebhook['id']}/rotate-secret",
            )
            ->assertOk()
            ->json('data.secret');
        $this->assertNotSame($createdWebhook['secret'], $rotatedWebhookSecret);
        $this->assertDatabaseHas('identity_webhook_endpoints', [
            'id' => $createdWebhook['id'],
            'project_id' => $project->id,
            'url' => 'https://example-app.example.com/webhooks/accounts',
            'status' => 'disabled',
        ]);

        $this->withToken($accessToken)
            ->deleteJson(
                "/api/v1/identity/projects/{$project->id}/webhooks/{$createdWebhook['id']}",
            )
            ->assertOk();
        $this->assertDatabaseMissing('identity_webhook_endpoints', [
            'id' => $createdWebhook['id'],
        ]);
    }

    public function test_project_administrator_can_delete_a_client_without_losing_project_permissions(): void
    {
        [$administrator, $project, $managementClient, $managementSecret] = $this->identityFixture(true);
        $managementToken = $this->login($administrator, $project, $managementClient, $managementSecret)['access_token'];
        $victim = $this->withToken($managementToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", ['name' => 'Retired API'])
            ->assertCreated()
            ->json('data');
        $victimClient = IdentityProjectClient::query()->findOrFail($victim['id']);
        $victimToken = $this->login($administrator, $project, $victimClient, $victim['client_secret'])['access_token'];
        $permission = IdentityProjectPermission::query()->create([
            'project_id' => $project->id,
            'source_client_id' => $victimClient->id,
            'key' => 'documents.read',
            'name' => 'Read documents',
            'source' => 'manifest',
            'status' => 'active',
        ]);
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Reader',
            'slug' => 'reader',
        ]);
        $role->permissions()->attach($permission);

        $this->withToken($managementToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/clients/{$victimClient->id}", ['confirmation' => 'Retired API'])
            ->assertOk();

        $this->assertDatabaseMissing('identity_project_clients', ['id' => $victimClient->id]);
        $this->assertNull(PersonalAccessToken::findToken($victimToken));
        $this->assertDatabaseHas('identity_project_permissions', [
            'id' => $permission->id,
            'source_client_id' => null,
            'source' => 'manual',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('identity_project_role_permission', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);

        $replacement = $this->withToken($managementToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", ['name' => 'Retired API'])
            ->assertCreated()
            ->json('data');
        $this->assertNotSame($victimClient->id, $replacement['id']);
    }

    public function test_client_deletion_is_blocked_when_a_hosted_application_uses_it(): void
    {
        [$administrator, $project, $managementClient, $managementSecret] = $this->identityFixture(true);
        $managementToken = $this->login($administrator, $project, $managementClient, $managementSecret)['access_token'];
        $client = $this->withToken($managementToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", ['name' => 'Hosted API'])
            ->assertCreated()
            ->json('data');
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client['id'],
            'key' => 'hosted-api',
            'name' => 'Hosted API',
            'application_url' => 'https://hosted.example.com',
            'callback_url' => 'https://hosted.example.com/callback',
            'status' => 'active',
        ]);

        $this->withToken($managementToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/clients/{$client['id']}", ['confirmation' => 'Hosted API'])
            ->assertConflict();
        $this->assertDatabaseHas('identity_project_clients', ['id' => $client['id']]);
    }

    public function test_project_administrator_can_rename_a_client_without_changing_credentials_or_bindings(): void
    {
        [$administrator, $project, $managementClient, $managementSecret] = $this->identityFixture(true);
        $managementToken = $this->login($administrator, $project, $managementClient, $managementSecret)['access_token'];
        $client = $this->withToken($managementToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", ['name' => 'Example Application Worker'])
            ->assertCreated()
            ->json('data');
        $clientModel = IdentityProjectClient::query()->findOrFail($client['id']);
        $secretHash = $clientModel->secret_hash;
        $application = IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $clientModel->id,
            'key' => 'example-page-set',
            'name' => 'Example Application',
            'application_url' => 'https://tasks.example.com',
            'callback_url' => 'https://tasks.example.com/callback',
            'status' => 'active',
        ]);

        $this->withToken($managementToken)
            ->patchJson(
                "/api/v1/identity/projects/{$project->id}/clients/{$clientModel->id}/name",
                ['name' => 'Renamed Example Application Worker'],
            )
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Example Application Worker');

        $clientModel->refresh();
        $this->assertSame('Renamed Example Application Worker', $clientModel->name);
        $this->assertSame($secretHash, $clientModel->secret_hash);
        $this->assertDatabaseHas('identity_hosted_applications', [
            'id' => $application->id,
            'primary_client_id' => $clientModel->id,
        ]);
    }

    public function test_project_administrator_can_manage_a_hosted_application_and_the_nuxt_host_can_resolve_it(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];
        $created = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/hosted-applications", [
                'name' => 'Example Application',
                'key' => 'example-app',
                'primary_client_id' => $client->id,
                'application_url' => 'https://example-app.example.test/dashboard',
                'callback_url' => 'https://example-app.example.test/api/auth/callback',
                'appearance' => [
                    'welcome_text' => 'Manage your next opportunity.',
                    'accent_color' => '#3157D5',
                    'background_preset' => 'indigo',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.key', 'example-app')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.appearance.background_preset', 'indigo')
            ->json('data');

        $this->assertDatabaseHas('identity_hosted_applications', [
            'id' => $created['id'],
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'key' => 'example-app',
        ]);

        $this->withToken($accessToken)
            ->getJson("/api/v1/identity/projects/{$project->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.hosted_applications')
            ->assertJsonPath('data.hosted_applications.0.id', $created['id']);

        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');
        $this->getJson('/api/v1/identity/hosted-applications/example-app/configuration')
            ->assertUnauthorized();
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->getJson('/api/v1/identity/hosted-applications/example-app/configuration')
            ->assertOk()
            ->assertJsonPath('data.primary.project', $project->slug)
            ->assertJsonPath('data.primary.client_id', $client->id)
            ->assertJsonPath('data.appearance.welcome_text', 'Manage your next opportunity.')
            ->assertJsonPath('data.appearance.accent_color', '#3157D5')
            ->assertJsonMissingPath('data.primary.client_secret');

        Storage::fake('public');
        config()->set('zolta.identity.hosted_applications.branding_disk', 'public');
        $logo = UploadedFile::fake()->image('example-app.png', 128, 128);
        $uploaded = $this->withToken($accessToken)
            ->post("/api/v1/identity/projects/{$project->id}/hosted-applications/{$created['id']}/logo", ['logo' => $logo])
            ->assertCreated()
            ->json('data');
        $this->assertIsString($uploaded['appearance']['logo_url']);
        $this->assertStringContainsString('identity/hosted-applications/', $uploaded['appearance']['logo_url']);
        $this->assertDatabaseHas('identity_hosted_applications', [
            'id' => $created['id'],
            'logo_path' => 'identity/hosted-applications/'.$created['id'].'/'.basename(parse_url($uploaded['appearance']['logo_url'], PHP_URL_PATH)),
        ]);
        IdentityHostedApplication::query()->whereKey($created['id'])->update([
            'logo_path' => 'identity/hosted-applications/'.$created['id'].'/legacy.svg',
        ]);
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->getJson('/api/v1/identity/hosted-applications/example-app/configuration')
            ->assertOk()
            ->assertJsonPath('data.appearance.logo_url', null);
        $this->withToken($accessToken)
            ->post("/api/v1/identity/projects/{$project->id}/hosted-applications/{$created['id']}/logo", [
                'logo' => UploadedFile::fake()->create('unsafe.svg', 16, 'image/svg+xml'),
            ])
            ->assertUnprocessable();
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/hosted-applications/{$created['id']}/logo")
            ->assertOk();
        $this->assertDatabaseHas('identity_hosted_applications', [
            'id' => $created['id'],
            'logo_path' => null,
        ]);
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson('/api/v1/identity/hosted-applications/example-app/auth/context')
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id);

        $anotherExampleAppClient = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients", ['name' => 'Another Example App BFF'])
            ->assertCreated()
            ->json('data');
        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/hosted-applications", [
                'name' => 'Another Example App',
                'key' => 'another-example-app',
                'primary_client_id' => $anotherExampleAppClient['id'],
                'application_url' => 'https://another-example-app.example.test',
                'callback_url' => 'https://another-example-app.example.test/api/identity/another-example-app/auth/callback',
                'appearance' => [
                    'welcome_text' => 'A focused writing space.',
                    'accent_color' => '#16806B',
                    'background_preset' => 'emerald',
                ],
            ])
            ->assertCreated();
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->getJson('/api/v1/identity/hosted-applications/another-example-app/configuration')
            ->assertOk()
            ->assertJsonPath('data.name', 'Another Example App')
            ->assertJsonPath('data.primary.client_id', $anotherExampleAppClient['id'])
            ->assertJsonPath('data.callback_url', 'https://another-example-app.example.test/api/identity/another-example-app/auth/callback')
            ->assertJsonPath('data.appearance.background_preset', 'emerald');
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->getJson('/api/v1/identity/hosted-applications/example-app/configuration')
            ->assertOk()
            ->assertJsonPath('data.primary.client_id', $client->id)
            ->assertJsonPath('data.appearance.background_preset', 'indigo');

        $this->withToken($accessToken)
            ->patchJson("/api/v1/identity/projects/{$project->id}/hosted-applications/{$created['id']}", [
                'name' => 'Example Application',
                'primary_client_id' => $client->id,
                'application_url' => 'https://example-app.example.test/dashboard',
                'callback_url' => 'https://example-app.example.test/api/auth/callback',
                'appearance' => [
                    'welcome_text' => null,
                    'accent_color' => null,
                    'background_preset' => 'identity',
                ],
                'status' => 'disabled',
            ])
            ->assertOk();
        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->getJson('/api/v1/identity/hosted-applications/example-app/configuration')
            ->assertNotFound();
    }

    public function test_hosted_login_throttle_is_scoped_to_each_hosted_application(): void
    {
        [$user, $project, $client] = $this->identityFixture();
        $exampleApp = 'example-app-rate-limit-'.Str::lower(Str::random(6));
        $anotherExampleApp = 'another-example-app-rate-limit-'.Str::lower(Str::random(6));
        $anotherExampleAppClient = IdentityProjectClient::query()->create([
            'project_id' => $project->id,
            'name' => 'Another Example App BFF',
            'secret_hash' => hash('sha256', Str::random(64)),
            'secret_prefix' => 'example',
            'status' => 'active',
        ]);

        foreach ([$exampleApp => $client, $anotherExampleApp => $anotherExampleAppClient] as $key => $hostedClient) {
            IdentityHostedApplication::query()->create([
                'project_id' => $project->id,
                'primary_client_id' => $hostedClient->id,
                'key' => $key,
                'name' => $key,
                'application_url' => 'https://'.$key.'.example.test',
                'callback_url' => 'https://'.$key.'.example.test/auth/callback',
                'status' => 'active',
            ]);
        }

        config()->set('identity.hosted_applications.internal_token', 'hosted-application-test-token');
        $payload = [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
                ->postJson("/api/v1/identity/hosted-applications/{$exampleApp}/auth/login", $payload)
                ->assertUnauthorized();
        }

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson("/api/v1/identity/hosted-applications/{$anotherExampleApp}/auth/login", $payload)
            ->assertUnauthorized();

        $this->withHeader('X-Internal-Token', 'hosted-application-test-token')
            ->postJson("/api/v1/identity/hosted-applications/{$exampleApp}/auth/login", $payload)
            ->assertTooManyRequests();
    }

    public function test_role_permission_and_membership_writes_preserve_the_public_api_contract(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $role = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/roles", [
                'name' => 'Document editor',
                'slug' => 'document-editor',
                'description' => 'Creates and edits project documents.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.permission_ids', [])
            ->json('data');

        $permission = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/permissions", [
                'key' => 'documents.write',
                'name' => 'Write documents',
            ])
            ->assertCreated()
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'active')
            ->json('data');

        $this->withToken($accessToken)
            ->putJson(
                "/api/v1/identity/projects/{$project->id}/roles/{$role['id']}/permissions",
                ['permission_ids' => [$permission['id']]],
            )
            ->assertOk();
        $this->assertDatabaseHas('identity_project_role_permission', [
            'role_id' => $role['id'],
            'permission_id' => $permission['id'],
        ]);

        $member = User::query()->create([
            'username' => 'project-member',
            'email' => Str::uuid().'@example.com',
            'password' => 'correct-password',
            'email_verified_at' => now(),
        ]);
        $membership = IdentityProjectMembership::query()->create([
            'project_id' => $project->id,
            'user_id' => $member->id,
            'status' => 'active',
            'is_admin' => false,
        ]);

        $this->withToken($accessToken)
            ->putJson(
                "/api/v1/identity/projects/{$project->id}/memberships/{$membership->id}/access",
                [
                    'role_ids' => [$role['id']],
                    'permission_ids' => [$permission['id']],
                    'is_admin' => false,
                    'status' => 'active',
                ],
            )
            ->assertOk();
        $this->assertDatabaseHas('identity_project_memberships', [
            'id' => $membership->id,
            'authorization_version' => 2,
        ]);
        $this->assertDatabaseHas('identity_membership_role', [
            'membership_id' => $membership->id,
            'role_id' => $role['id'],
        ]);
        $this->assertDatabaseHas('identity_membership_permission', [
            'membership_id' => $membership->id,
            'permission_id' => $permission['id'],
        ]);

        $this->withToken($accessToken)
            ->deleteJson(
                "/api/v1/identity/projects/{$project->id}/memberships/{$membership->id}",
            )
            ->assertOk();
        $this->assertDatabaseMissing('identity_project_memberships', [
            'id' => $membership->id,
        ]);
    }

    public function test_project_administrator_can_delete_unused_roles_and_permissions(): void
    {
        [$administrator, $project, $client, $secret, $membership] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];
        $role = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/roles", [
                'name' => 'Temporary editor',
                'slug' => 'temporary-editor',
            ])
            ->assertCreated()
            ->json('data');
        $permission = $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/permissions", [
                'key' => 'temporary.edit',
                'name' => 'Temporary edit',
            ])
            ->assertCreated()
            ->json('data');
        $catalogRole = IdentityAccessCatalogRole::query()->create([
            'name' => 'Temporary editor',
            'slug' => 'temporary-editor',
            'status' => 'active',
            'version' => 1,
        ]);
        $catalogPermission = IdentityAccessCatalogPermission::query()->create([
            'key' => 'temporary.edit',
            'name' => 'Temporary edit',
            'status' => 'active',
            'version' => 1,
        ]);
        IdentityProjectRole::query()->findOrFail($role['id'])->forceFill([
            'catalog_role_id' => $catalogRole->id,
            'catalog_version' => 1,
            'catalog_origin' => 'imported',
        ])->save();
        IdentityProjectPermission::query()->findOrFail($permission['id'])->forceFill([
            'source' => 'catalog',
            'catalog_permission_id' => $catalogPermission->id,
            'catalog_version' => 1,
            'catalog_origin' => 'imported',
        ])->save();
        IdentityProjectRole::query()->findOrFail($role['id'])->permissions()->attach($permission['id']);

        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/roles/{$role['id']}", [
                'confirmation' => 'temporary-editor',
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Role deleted.');
        $this->assertDatabaseMissing('identity_project_roles', ['id' => $role['id']]);

        $authorizationVersion = $membership->fresh()->authorization_version;
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/permissions/{$permission['id']}", [
                'confirmation' => 'temporary.edit',
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Permission deleted.');
        $this->assertDatabaseMissing('identity_project_permissions', ['id' => $permission['id']]);
        $this->assertDatabaseHas('identity_access_catalog_roles', ['id' => $catalogRole->id]);
        $this->assertDatabaseHas('identity_access_catalog_permissions', ['id' => $catalogPermission->id]);
        $this->assertSame($authorizationVersion + 1, $membership->fresh()->authorization_version);
        $this->assertDatabaseHas('identity_audit_events', ['event' => 'role.deleted', 'target_id' => $role['id']]);
        $this->assertDatabaseHas('identity_audit_events', ['event' => 'permission.deleted', 'target_id' => $permission['id']]);
    }

    public function test_role_deletion_reports_membership_and_registration_dependencies(): void
    {
        [$administrator, $project, $client, $secret, $membership] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Default member',
            'slug' => 'default-member',
        ]);
        $membership->roles()->attach($role);
        $project->forceFill(['registration_role_id' => $role->id])->save();

        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/roles/{$role->id}", [
                'confirmation' => 'default-member',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.public.code', 'identity.access_dependency_conflict')
            ->assertJsonPath('errors.public.resource_type', 'role')
            ->assertJsonPath('errors.public.resource_id', $role->id)
            ->assertJsonPath('errors.public.dependencies.registration_default', true)
            ->assertJsonPath('errors.public.dependencies.memberships.0.id', $membership->id);
        $this->assertDatabaseHas('identity_project_roles', ['id' => $role->id]);
    }

    public function test_permission_deletion_reports_grants_and_respects_manifest_ownership(): void
    {
        [$administrator, $project, $client, $secret, $membership] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];
        $permission = IdentityProjectPermission::query()->create([
            'project_id' => $project->id,
            'key' => 'documents.archive',
            'name' => 'Archive documents',
            'source' => 'manual',
            'status' => 'active',
        ]);
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Archivist',
            'slug' => 'archivist',
        ]);
        $role->permissions()->attach($permission);
        $membership->permissions()->attach($permission);

        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/permissions/{$permission->id}", [
                'confirmation' => 'documents.archive',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.public.code', 'identity.access_dependency_conflict')
            ->assertJsonPath('errors.public.dependencies.roles.0.id', $role->id)
            ->assertJsonPath('errors.public.dependencies.memberships.0.id', $membership->id);

        $manifestPermission = IdentityProjectPermission::query()->create([
            'project_id' => $project->id,
            'source_client_id' => $client->id,
            'key' => 'manifest.owned',
            'name' => 'Manifest owned',
            'source' => 'manifest',
            'status' => 'active',
        ]);
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/permissions/{$manifestPermission->id}", [
                'confirmation' => 'manifest.owned',
            ])
            ->assertConflict()
            ->assertJsonPath('errors.public.dependencies.manifest_client.id', $client->id);

        $manifestPermission->forceFill(['status' => 'stale'])->save();
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/permissions/{$manifestPermission->id}", [
                'confirmation' => 'manifest.owned',
            ])
            ->assertOk();
        $this->assertDatabaseMissing('identity_project_permissions', ['id' => $manifestPermission->id]);
    }

    public function test_access_deletion_validates_confirmation_scope_and_project_administration(): void
    {
        [$administrator, $project, $client, $secret] = $this->identityFixture(true);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Protected role',
            'slug' => 'protected-role',
        ]);

        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/roles/{$role->id}", [
                'confirmation' => 'wrong-role',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.public.code', 'validation.failed')
            ->assertJsonPath('errors.public.errors.confirmation.0', 'Type the exact role slug to confirm deletion.');

        [, $otherProject] = $this->identityFixture();
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/roles/".IdentityProjectRole::query()->create([
                'project_id' => $otherProject->id,
                'name' => 'Other role',
                'slug' => 'other-role',
            ])->id, ['confirmation' => 'other-role'])
            ->assertNotFound();

        [$member, $memberProject, $memberClient, $memberSecret] = $this->identityFixture();
        $memberToken = $this->login($member, $memberProject, $memberClient, $memberSecret)['access_token'];
        $memberProjectRole = IdentityProjectRole::query()->create([
            'project_id' => $memberProject->id,
            'name' => 'Member project role',
            'slug' => 'member-project-role',
        ]);
        $this->withToken($memberToken)
            ->deleteJson("/api/v1/identity/projects/{$memberProject->id}/roles/{$memberProjectRole->id}", [
                'confirmation' => 'member-project-role',
            ])
            ->assertForbidden();

        $project->forceFill(['status' => 'suspended'])->save();
        $this->withToken($accessToken)
            ->deleteJson("/api/v1/identity/projects/{$project->id}/roles/{$role->id}", [
                'confirmation' => 'protected-role',
            ])
            ->assertConflict();
        $this->assertDatabaseHas('identity_project_roles', ['id' => $role->id]);
    }

    public function test_permission_manifest_sync_reactivates_current_and_stales_removed_permissions(): void
    {
        [, $project, $client, $secret, $membership] = $this->identityFixture();

        $this->putJson('/api/v1/identity/clients/permission-manifest', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'permissions' => [
                ['key' => 'documents.read', 'name' => 'Read documents'],
                ['key' => 'documents.write', 'name' => 'Write documents'],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data');

        $this->putJson('/api/v1/identity/clients/permission-manifest', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'permissions' => [
                ['key' => 'documents.read', 'name' => 'Read project documents'],
            ],
        ])->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('identity_project_permissions', [
            'project_id' => $project->id,
            'source_client_id' => $client->id,
            'key' => 'documents.read',
            'name' => 'Read project documents',
            'source' => 'manifest',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('identity_project_permissions', [
            'project_id' => $project->id,
            'source_client_id' => $client->id,
            'key' => 'documents.write',
            'status' => 'stale',
        ]);
        $this->assertSame(3, $membership->fresh()->authorization_version);
    }

    public function test_project_administrator_cannot_mutate_resources_from_another_project(): void
    {
        [$administrator, $project, $client, $secret, $membership] = $this->identityFixture(true);
        [, $otherProject, $otherClient, , $otherMembership] = $this->identityFixture();
        $role = IdentityProjectRole::query()->create([
            'project_id' => $project->id,
            'name' => 'Project role',
            'slug' => 'project-role',
        ]);
        $otherPermission = IdentityProjectPermission::query()->create([
            'project_id' => $otherProject->id,
            'key' => 'other.manage',
            'name' => 'Other project permission',
            'source' => 'manual',
            'status' => 'active',
        ]);
        $otherRole = IdentityProjectRole::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Other project role',
            'slug' => 'other-project-role',
        ]);
        $otherWebhook = IdentityWebhookEndpoint::query()->create([
            'project_id' => $otherProject->id,
            'url' => 'https://other.example.com/identity',
            'events' => ['identity.user.expired'],
            'secret' => Str::random(64),
            'secret_prefix' => 'other',
            'status' => 'active',
        ]);
        $accessToken = $this->login($administrator, $project, $client, $secret)['access_token'];

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/clients/{$otherClient->id}/rotate-secret")
            ->assertNotFound();

        $this->withToken($accessToken)
            ->putJson("/api/v1/identity/projects/{$project->id}/roles/{$otherRole->id}/permissions", [
                'permission_ids' => [$otherPermission->id],
            ])
            ->assertNotFound();

        $this->withToken($accessToken)
            ->putJson("/api/v1/identity/projects/{$project->id}/roles/{$role->id}/permissions", [
                'permission_ids' => [$otherPermission->id],
            ])
            ->assertForbidden();

        $this->withToken($accessToken)
            ->putJson("/api/v1/identity/projects/{$project->id}/memberships/{$otherMembership->id}/access", [
                'role_ids' => [],
                'permission_ids' => [],
                'is_admin' => false,
                'status' => 'active',
            ])
            ->assertNotFound();

        $this->withToken($accessToken)
            ->putJson("/api/v1/identity/projects/{$project->id}/memberships/{$membership->id}/access", [
                'role_ids' => [$otherRole->id],
                'permission_ids' => [$otherPermission->id],
                'is_admin' => true,
                'status' => 'active',
            ])
            ->assertForbidden();

        $this->withToken($accessToken)
            ->postJson("/api/v1/identity/projects/{$project->id}/webhooks/{$otherWebhook->id}/rotate-secret")
            ->assertNotFound();

        $this->assertSame($otherProject->id, $otherClient->fresh()->project_id);
        $this->assertSame($otherProject->id, $otherRole->fresh()->project_id);
        $this->assertSame($otherProject->id, $otherMembership->fresh()->project_id);
        $this->assertSame($otherProject->id, $otherWebhook->fresh()->project_id);
    }

    public function test_a_project_scoped_token_cannot_access_another_project_even_when_the_user_administers_it(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture(true);
        [, $otherProject] = $this->identityFixture();
        IdentityProjectMembership::query()->create([
            'project_id' => $otherProject->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_admin' => true,
        ]);
        $accessToken = $this->login($user, $project, $client, $secret)['access_token'];

        $this->withToken($accessToken)
            ->getJson("/api/v1/identity/projects/{$otherProject->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'The identity token is not authorized for this project.');
    }

    public function test_identity_sessions_are_limited_to_the_access_token_project(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        [, $otherProject, $otherClient, $otherSecret] = $this->identityFixture();
        IdentityProjectMembership::query()->create([
            'project_id' => $otherProject->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_admin' => false,
        ]);
        IdentityProjectAccount::query()->create([
            'project_id' => $otherProject->id,
            'user_id' => $user->id,
            'username' => $user->username,
            'password' => 'correct-password',
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
        $projectSession = $this->login($user, $project, $client, $secret);
        $otherSession = $this->login($user, $otherProject, $otherClient, $otherSecret);
        $otherFamily = PersonalAccessToken::findToken($otherSession['access_token'])?->identity_refresh_family_id;

        $this->withToken($projectSession['access_token'])
            ->getJson('/api/v1/identity/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project.id', $project->id);
        $this->withToken($projectSession['access_token'])
            ->deleteJson("/api/v1/identity/auth/sessions/{$otherFamily}")
            ->assertForbidden();
    }

    public function test_identity_sessions_cannot_list_or_revoke_another_client_in_the_same_project(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $otherSecret = Str::random(64);
        $otherClient = IdentityProjectClient::query()->create([
            'project_id' => $project->id, 'name' => 'Other application client',
            'secret_hash' => hash('sha256', $otherSecret), 'secret_prefix' => substr($otherSecret, 0, 8), 'status' => 'active',
        ]);
        $session = $this->login($user, $project, $client, $secret);
        $otherSession = $this->login($user, $project, $otherClient, $otherSecret);
        $otherFamily = PersonalAccessToken::findToken($otherSession['access_token'])->identity_refresh_family_id;
        $this->withToken($session['access_token'])->getJson('/api/v1/identity/auth/sessions')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client.id', $client->id);
        $this->withToken($session['access_token'])->deleteJson("/api/v1/identity/auth/sessions/{$otherFamily}")
            ->assertForbidden();
        $this->withToken($otherSession['access_token'])->getJson('/api/v1/identity/auth/me')->assertOk();
    }

    public function test_app_account_password_requires_a_single_use_operation_bound_grant(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $session = $this->login($user, $project, $client, $secret);
        $otherSession = $this->login($user, $project, $client, $secret);
        $password = ['password' => 'new-project-password', 'password_confirmation' => 'new-project-password'];
        $this->withToken($session['access_token'])->putJson('/api/v1/identity/auth/account/password', $password)
            ->assertForbidden()->assertJsonPath('code', 'reauthentication_required');
        $grant = $this->withToken($session['access_token'])->postJson('/api/v1/identity/auth/reauthenticate', [
            'client_id' => $client->id, 'client_secret' => $secret,
            'password' => 'correct-password', 'sensitive_operation' => 'password',
        ])->assertOk()->json('data.grant');
        $this->assertSame(64, strlen($grant));
        $this->withToken($session['access_token'])->withHeader('X-Identity-Reauthentication', $grant)
            ->putJson('/api/v1/identity/auth/account/password', $password)->assertOk();
        $account = IdentityProjectAccount::query()->where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check('new-project-password', $account->password));
        $this->assertTrue(Hash::check('correct-password', $user->fresh()->password));
        $this->assertNull(PersonalAccessToken::findToken($otherSession['access_token']));
        $this->withToken($session['access_token'])->withHeader('X-Identity-Reauthentication', $grant)
            ->putJson('/api/v1/identity/auth/account/password', $password)->assertForbidden();
    }

    public function test_reauthentication_rejects_wrong_password_and_other_clients(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        [, , $otherClient, $otherSecret] = $this->identityFixture();
        $session = $this->login($user, $project, $client, $secret);
        foreach ([[$client->id, $secret, 'wrong-password'], [$otherClient->id, $otherSecret, 'correct-password']] as [$id, $credential, $password]) {
            $this->withToken($session['access_token'])->postJson('/api/v1/identity/auth/reauthenticate', [
                'client_id' => $id, 'client_secret' => $credential, 'password' => $password, 'sensitive_operation' => 'password',
            ])->assertUnauthorized();
        }
    }

    public function test_reauthentication_grants_expire_and_cannot_cross_session_or_operation(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $session = $this->login($user, $project, $client, $secret);
        $other = $this->login($user, $project, $client, $secret);
        $grant = $this->withToken($session['access_token'])->postJson('/api/v1/identity/auth/reauthenticate', [
            'client_id' => $client->id, 'client_secret' => $secret,
            'password' => 'correct-password', 'sensitive_operation' => 'export',
        ])->assertOk()->json('data.grant');
        $security = app(ManageIdentityAccountSecurity::class);
        foreach ([[$other['access_token'], 'export'], [$session['access_token'], 'password']] as [$token, $operation]) {
            try {
                $security->consumeGrant($token, $grant, $operation);
                $this->fail('An incorrectly bound grant was accepted.');
            } catch (IdentityAuthenticationException) {
                $this->assertTrue(true);
            }
        }
        $this->travel(5)->minutes();
        $this->expectException(IdentityAuthenticationException::class);
        $security->consumeGrant($session['access_token'], $grant, 'export');
    }

    public function test_applications_contract_forces_hosted_mode_and_persists_configured_auth_appearance(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture(true);
        $session = $this->login($user, $project, $client, $secret);
        $created = $this->withToken($session['access_token'])->postJson("/api/v1/identity/projects/{$project->id}/applications", [
            'key' => 'example-product', 'name' => 'Example Product', 'primary_client_id' => $client->id, 'authentication_mode' => 'hosted',
            'application_url' => 'https://product.example.test', 'callback_url' => 'https://product.example.test/api/identity/projects/auth/callback',
            'auth_page_set' => 'example-product', 'appearance' => ['welcome_text' => 'Welcome to Example Product'],
        ])->assertCreated()->assertJsonPath('data.authentication_mode', 'hosted')->json('data');
        IdentityHostedApplication::query()->whereKey($created['id'])->update([
            'password_reset_url' => 'https://example-app.example.test/projects/auth/reset-password',
        ]);
        $this->withToken($session['access_token'])->patchJson("/api/v1/identity/projects/{$project->id}/applications/{$created['id']}", [
            'name' => 'Example Product', 'primary_client_id' => $client->id, 'status' => 'active',
            'application_url' => $created['application_url'], 'callback_url' => $created['callback_url'],
            'authentication_mode' => 'app', 'password_reset_url' => 'https://example-app.example.test/projects/auth/reset-password',
            'auth_page_set' => 'example-product', 'appearance' => ['welcome_text' => 'Configured product text'],
        ])->assertOk();
        $application = IdentityHostedApplication::query()->findOrFail($created['id']);
        $this->assertSame('example-product', $application->key);
        $this->assertSame('example-product', $application->auth_page_set);
        $this->assertSame('Configured product text', $application->appearance['welcome_text']);
        $this->assertNull($application->password_reset_url);
        $this->assertSame('hosted', $application->authentication_mode);
        $this->withToken($session['access_token'])->patchJson("/api/v1/identity/projects/{$project->id}/applications/{$created['id']}", [
            'name' => 'Example Product', 'primary_client_id' => $client->id, 'status' => 'active',
            'application_url' => $created['application_url'], 'callback_url' => $created['callback_url'],
            'authentication_mode' => 'app', 'password_reset_url' => 'https://evil.example/reset',
        ])->assertOk();
        $this->assertDatabaseHas('identity_hosted_applications', [
            'id' => $created['id'],
            'authentication_mode' => 'hosted',
            'password_reset_url' => null,
        ]);
    }

    public function test_app_registration_enforces_policy_and_preserves_project_verification(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill(['registration_mode' => 'public', 'email_verification_required' => true])->save();
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id, 'primary_client_id' => $client->id, 'key' => 'example-page-set', 'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test', 'callback_url' => 'https://example-app.example.test/callback',
            'authentication_mode' => 'app', 'password_reset_url' => 'https://example-app.example.test/reset', 'policy_revision' => '2026-10',
            'authentication' => ['terms_required' => true, 'terms_url' => 'https://example-app.example.test/terms'], 'status' => 'active',
        ]);
        $input = ['client_id' => $client->id, 'client_secret' => $secret, 'application_key' => 'example-page-set',
            'username' => 'new-user', 'email' => 'new-user@example.test', 'password' => 'new-account-password', 'password_confirmation' => 'new-account-password'];
        $this->postJson('/api/v1/identity/auth/register', $input)->assertForbidden();
        $this->postJson('/api/v1/identity/auth/register', $input + ['terms_accepted' => true, 'policy_revision' => 'old'])->assertForbidden();
        $registered = $this->postJson('/api/v1/identity/auth/register', $input + ['terms_accepted' => true, 'policy_revision' => '2026-10'])
            ->assertCreated()->assertJsonPath('data.identity.user.email_verified', false)->json('data');
        $this->assertDatabaseHas('identity_hosted_application_consents', ['policy_revision' => '2026-10']);
        $this->withToken($registered['access_token'])->postJson('/api/v1/identity/auth/reauthenticate', [
            'client_id' => $client->id, 'client_secret' => $secret, 'password' => 'new-account-password', 'sensitive_operation' => 'export',
        ])->assertUnauthorized();
    }

    public function test_google_transaction_denial_expiry_replay_and_callback_binding(): void
    {
        [, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill(['google_social_authentication_enabled' => true])->save();
        config()->set('services.google.client_id', 'google-test-client');
        config()->set('services.google.client_secret', 'google-test-secret');
        config()->set('services.google.identity_redirect', 'https://identity.example.test/api/v1/identity/auth/google/callback');
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id, 'primary_client_id' => $client->id, 'key' => 'example-page-set', 'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test', 'callback_url' => 'https://example-app.example.test/callback',
            'authentication_mode' => 'app', 'password_reset_url' => 'https://example-app.example.test/reset',
            'authentication' => ['google_enabled' => true], 'status' => 'active',
        ]);
        $input = ['client_id' => $client->id, 'client_secret' => $secret, 'application_key' => 'example-page-set',
            'redirect_uri' => 'https://example-app.example.test/callback', 'state' => str_repeat('s', 64)];
        $this->postJson('/api/v1/identity/auth/google/authorize', array_replace($input, ['redirect_uri' => 'https://evil.example/callback']))->assertUnauthorized();
        $url = $this->postJson('/api/v1/identity/auth/google/authorize', $input)->assertOk()->json('data.redirect_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->getJson('/api/v1/identity/auth/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))
            ->assertRedirect('https://example-app.example.test/callback?'.http_build_query(['state' => $input['state'], 'error' => 'access_denied']));
        $this->getJson('/api/v1/identity/auth/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertUnauthorized();
        $url = $this->postJson('/api/v1/identity/auth/google/authorize', $input)->assertOk()->json('data.redirect_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->travel(10)->minutes();
        $this->getJson('/api/v1/identity/auth/google/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertUnauthorized();
    }

    public function test_google_success_and_same_identity_reauthentication_use_one_time_handoffs(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        $project->forceFill(['google_social_authentication_enabled' => true])->save();
        config()->set('services.google.client_id', 'google-test-client');
        config()->set('services.google.client_secret', 'google-test-secret');
        config()->set('services.google.identity_redirect', 'https://identity.example.test/api/v1/identity/auth/google/callback');
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id, 'primary_client_id' => $client->id, 'key' => 'example-page-set', 'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test', 'callback_url' => 'https://example-app.example.test/callback',
            'authentication_mode' => 'app', 'password_reset_url' => 'https://example-app.example.test/reset',
            'authentication' => ['google_enabled' => true], 'status' => 'active',
        ]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-provider-token'])]);
        $remote = new OAuthUser('google-user-1', 'Google User', $user->email, 'google-provider-token', null, null);
        $this->mock(OAuthGateway::class, function ($mock) use ($remote): void {
            $mock->shouldReceive('fetchUser')->with('google', 'google-provider-token')->andReturn($remote);
        });
        $input = ['client_id' => $client->id, 'client_secret' => $secret, 'application_key' => 'example-page-set',
            'redirect_uri' => 'https://example-app.example.test/callback', 'state' => str_repeat('s', 64)];
        $url = $this->postJson('/api/v1/identity/auth/google/authorize', $input)->assertOk()->json('data.redirect_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->get('/api/v1/identity/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'provider-code']))->assertRedirect();
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $delivery);
        $exchange = $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id, 'client_secret' => $secret, 'redirect_uri' => $input['redirect_uri'], 'code' => $delivery['code'],
        ])->assertOk()->assertJsonPath('data.identity.user.id', $user->id)->json('data');
        $url = $this->withToken($exchange['access_token'])->postJson('/api/v1/identity/auth/google/authorize', $input + ['sensitive_operation' => 'export'])->assertOk()->json('data.redirect_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $callback = $this->get('/api/v1/identity/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'fresh-provider-code']))->assertRedirect();
        parse_str(parse_url($callback->headers->get('Location'), PHP_URL_QUERY), $delivery);
        $grant = $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id, 'client_secret' => $secret, 'redirect_uri' => $input['redirect_uri'], 'code' => $delivery['code'],
        ])->assertOk()->assertJsonMissingPath('data.access_token')->assertJsonPath('data.reauthentication.operation', 'export')->json('data.reauthentication.grant');
        $this->assertNotNull(PersonalAccessToken::findToken($exchange['access_token']));
        $this->withToken($exchange['access_token'])->withHeader('X-Identity-Reauthentication', $grant)
            ->postJson('/api/v1/identity/auth/account/export')->assertOk()->assertJsonPath('data.account_export.account.id', $user->id);
        $this->postJson('/api/v1/identity/auth/handoff/exchange', [
            'client_id' => $client->id, 'client_secret' => $secret, 'redirect_uri' => $input['redirect_uri'], 'code' => $delivery['code'],
        ])->assertUnauthorized();
    }

    public function test_versioned_profile_export_and_shared_deletion_require_fresh_authentication(): void
    {
        if (Schema::hasColumn('users', 'provider_id')) {
            Schema::table('users', static function (Blueprint $table): void {
                $table->dropColumn('provider_id');
            });
        }
        $this->assertFalse(Schema::hasColumn('users', 'provider_id'));

        [$user, $project, $client, $secret] = $this->identityFixture();
        $session = $this->login($user, $project, $client, $secret);
        $grant = function (string $operation) use ($session, $client, $secret): string {
            return $this->withToken($session['access_token'])->postJson('/api/v1/identity/auth/reauthenticate', [
                'client_id' => $client->id, 'client_secret' => $secret, 'password' => 'correct-password', 'sensitive_operation' => $operation,
            ])->assertOk()->json('data.grant');
        };
        $profileGrant = $grant('profile');
        $this->withToken($session['access_token'])->withHeader('X-Identity-Reauthentication', $profileGrant)
            ->putJson('/api/v1/identity/auth/account/profile', ['username' => 'Updated Profile', 'email' => $user->email, 'avatar_url' => null])->assertOk();
        $this->assertDatabaseHas('identity_project_accounts', ['project_id' => $project->id, 'user_id' => $user->id, 'username' => 'Updated Profile']);
        $this->travel(1)->minutes();
        $exportGrant = $grant('export');
        $this->withToken($session['access_token'])->withHeader('X-Identity-Reauthentication', $exportGrant)
            ->postJson('/api/v1/identity/auth/account/export')->assertOk()->assertJsonPath('data.account_export.account.id', $user->id);

        DB::table('sessions')->insert([
            'id' => 'unrelated-laravel-session',
            'user_id' => 0,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $this->travel(1)->minutes();
        $deleteGrant = $grant('delete_account');
        $this->withToken($session['access_token'])->withHeader('X-Identity-Reauthentication', $deleteGrant)
            ->deleteJson('/api/v1/identity/auth/account')->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-laravel-session']);
    }

    public function test_recovery_uses_an_enabled_application_page_set_and_returns_a_generic_response(): void
    {
        [$user, $project, $client, $secret] = $this->identityFixture();
        config()->set('zolta.identity.expose_development_tokens', false);
        config()->set('zolta.identity.password_reset_url', 'https://identity.example.test/auth/reset-password');
        config()->set('zolta.identity.hosted_applications.page_sets', ['default', 'example-app']);
        Notification::fake();
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id, 'primary_client_id' => $client->id, 'key' => 'example-app', 'name' => 'Example Application',
            'application_url' => 'https://example-app.example.test', 'callback_url' => 'https://example-app.example.test/callback',
            'authentication_mode' => 'app', 'auth_page_set' => 'example-app', 'status' => 'active',
        ]);
        $body = ['client_id' => $client->id, 'client_secret' => $secret, 'email' => $user->email, 'reset_url' => 'https://evil.example/reset'];
        $known = $this->postJson('/api/v1/identity/auth/password/forgot', $body)->assertOk()->json('data');
        $unknown = $this->postJson('/api/v1/identity/auth/password/forgot', array_replace($body, ['email' => 'unknown@example.test']))->assertOk()->json('data');
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetIdentityPassword::class, function ($notification) use ($user): bool {
            $resetUrl = (string) $notification->toMail($user)->viewData['resetUrl'];

            return str_starts_with($resetUrl, 'https://identity.example.test/auth/example-app/reset-password?')
                && ! str_starts_with($resetUrl, 'https://example-app.example.test/');
        });
    }

    /** @return array{User, IdentityProject, IdentityProjectClient, string, IdentityProjectMembership} */
    private function identityFixture(bool $isAdmin = false): array
    {
        $user = User::query()->create([
            'username' => 'identity-user',
            'email' => Str::uuid().'@example.com',
            'password' => 'correct-password',
            'email_verified_at' => now(),
        ]);
        $project = IdentityProject::query()->create([
            'name' => 'Example Application',
            'slug' => 'example-app-'.Str::random(6),
            'status' => 'active',
            'mode' => 'live',
            'sandbox_ttl_minutes' => 60,
            'registration_mode' => 'invite_only',
        ]);
        $secret = Str::random(64);
        $client = IdentityProjectClient::query()->create([
            'project_id' => $project->id,
            'name' => 'Example Application BFF',
            'secret_hash' => hash('sha256', $secret),
            'secret_prefix' => Str::substr($secret, 0, 8),
            'status' => 'active',
        ]);
        $membership = IdentityProjectMembership::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'active',
            'is_admin' => $isAdmin,
        ]);
        IdentityProjectAccount::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'username' => $user->username,
            'password' => 'correct-password',
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);

        return [$user, $project, $client, $secret, $membership];
    }

    /** @return array<string, mixed> */
    private function login(User $user, IdentityProject $project, IdentityProjectClient $client, string $secret): array
    {
        return $this->postJson('/api/v1/identity/auth/login', [
            'project' => $project->slug,
            'client_id' => $client->id,
            'client_secret' => $secret,
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->json('data');
    }
}
