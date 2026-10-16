<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyAdministrationParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_receive_unauthorized_for_installation_user_administration(): void
    {
        $this->get('/api/users')->assertUnauthorized();
    }

    public function test_system_administrator_can_manage_installation_users(): void
    {
        $administrator = $this->user('administrator', true);
        $managedUser = $this->user('managed-user');
        $token = $administrator->createToken('admin-console')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/users')
            ->assertOk();

        $this->withToken($token)
            ->deleteJson("/api/users/{$managedUser->id}")
            ->assertOk();
    }

    public function test_regular_user_cannot_access_installation_user_administration(): void
    {
        $token = $this->user('regular-user')->createToken('browser')->plainTextToken;

        $this->withToken($token)->getJson('/api/users')->assertForbidden();
    }

    public function test_legacy_global_authorization_endpoints_are_not_registered(): void
    {
        $token = $this->user('administrator', true)->createToken('admin-console')->plainTextToken;

        $this->withToken($token)->getJson('/api/roles')->assertNotFound();
        $this->withToken($token)->getJson('/api/permissions')->assertNotFound();
        $this->withToken($token)->postJson('/api/users/provision-access')->assertNotFound();
    }

    public function test_regular_user_can_update_only_their_active_project_account_profile(): void
    {
        $user = $this->user('account-owner');
        $otherUser = $this->user('other-account');
        $firstProject = $this->project('first-project');
        $secondProject = $this->project('second-project');
        foreach ([$firstProject, $secondProject] as $project) {
            IdentityProjectAccount::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'username' => 'account-owner',
                'password' => 'correct-password',
                'profile_picture' => "https://example.com/{$project->slug}.png",
                'status' => 'active',
            ]);
        }
        $token = $user->createToken('browser');
        $token->accessToken->forceFill([
            'identity_project_id' => $firstProject->id,
            'identity_client_id' => (string) Str::uuid(),
        ])->save();

        $this->withToken($token->plainTextToken)
            ->putJson('/api/users/profile', [
                'user_id' => $otherUser->id,
                'project_id' => $secondProject->id,
                'username' => 'updated-owner',
                'email' => $user->email,
                'avatar_url' => 'https://example.com/avatar.png',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.username', 'updated-owner');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/users/profile/security', [
                'two_factor_enabled' => true,
                'login_alerts_enabled' => true,
                'backup_email' => 'backup@example.com',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'username' => 'account-owner',
            'two_factor_enabled' => true,
            'backup_email' => 'backup@example.com',
        ]);
        $this->assertDatabaseCount('identity_project_accounts', 2);
        $this->assertDatabaseHas('identity_project_accounts', [
            'project_id' => $firstProject->id,
            'user_id' => $user->id,
            'username' => 'updated-owner',
            'profile_picture' => 'https://example.com/avatar.png',
        ]);
        $this->assertDatabaseHas('identity_project_accounts', [
            'project_id' => $secondProject->id,
            'user_id' => $user->id,
            'username' => 'account-owner',
            'profile_picture' => 'https://example.com/second-project.png',
        ]);
        $this->assertSame('other-account', $otherUser->fresh()->username);
    }

    private function user(string $name, bool $systemAdministrator = false): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'username' => $name,
            'email' => "{$name}@example.com",
            'password' => 'correct-password',
            'terms' => 'accepted',
            'email_verified_at' => now(),
            'is_system_admin' => $systemAdministrator,
        ]);
    }

    private function project(string $slug): IdentityProject
    {
        return IdentityProject::query()->create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'status' => 'active',
            'mode' => 'live',
            'sandbox_ttl_minutes' => 60,
            'registration_mode' => 'invite_only',
        ]);
    }
}
