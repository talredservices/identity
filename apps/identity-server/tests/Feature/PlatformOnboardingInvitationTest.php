<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformOnboardingInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_another_team_invitation_does_not_invalidate_a_pending_login_invitation(): void
    {
        [$client, $secret, $project] = $this->platformLoginFixture();
        $body = ['client_id' => $client->id, 'client_secret' => $secret, 'email' => 'Member@Example.com'];

        $firstResponse = $this->postJson('/api/v1/identity/platform/onboarding/invitations', $body)->assertCreated();
        $secondResponse = $this->postJson('/api/v1/identity/platform/onboarding/invitations', $body)->assertCreated();
        $firstToken = (string) $firstResponse->json('data.invitation_token');
        $secondToken = (string) $secondResponse->json('data.invitation_token');

        $this->assertNotSame('', $firstToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseCount('identity_project_invitations', 2);
        $this->assertDatabaseHas('identity_project_invitations', [
            'project_id' => $project->id,
            'email' => 'member@example.com',
            'token_hash' => hash('sha256', $firstToken),
            'accepted_at' => null,
        ]);
        $this->assertDatabaseHas('identity_project_invitations', [
            'project_id' => $project->id,
            'email' => 'member@example.com',
            'token_hash' => hash('sha256', $secondToken),
            'accepted_at' => null,
        ]);

        $this->postJson('/api/v1/identity/platform/onboarding/invitations/revoke', [
            'client_id' => $client->id,
            'client_secret' => $secret,
            'invitation_token' => $firstToken,
        ])->assertOk();

        $this->assertDatabaseMissing('identity_project_invitations', ['token_hash' => hash('sha256', $firstToken)]);
        $this->assertDatabaseHas('identity_project_invitations', ['token_hash' => hash('sha256', $secondToken)]);
    }

    /** @return array{IdentityProjectClient, string, IdentityProject} */
    private function platformLoginFixture(): array
    {
        $project = IdentityProject::query()->create([
            'name' => 'Platform Login',
            'slug' => 'platform-login-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'mode' => 'live',
            'sandbox_ttl_minutes' => 60,
            'registration_mode' => 'invite_only',
        ]);
        $secret = Str::random(64);
        $client = IdentityProjectClient::query()->create([
            'project_id' => $project->id,
            'name' => 'Platform API',
            'secret_hash' => hash('sha256', $secret),
            'secret_prefix' => Str::substr($secret, 0, 8),
            'status' => 'active',
        ]);
        IdentityHostedApplication::query()->create([
            'project_id' => $project->id,
            'primary_client_id' => $client->id,
            'key' => 'platform-login',
            'name' => 'Platform Login',
            'application_url' => 'http://localhost:3300',
            'callback_url' => 'http://localhost:3000/api/identity/platform-login/auth/callback',
            'status' => 'active',
        ]);

        config()->set('services.platform_onboarding.client_id', $client->id);
        config()->set('services.platform_onboarding.login_project_slug', $project->slug);

        return [$client, $secret, $project];
    }
}
