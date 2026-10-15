<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UserManagementService\Domain\Aggregates\IdentityMembership as DomainIdentityMembership;
use App\Services\UserManagementService\Domain\Repositories\IdentityMembershipRepository;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityClientProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Zolta\Domain\ValueObjects\UserId;

final class BootstrapIdentity extends Command
{
    protected $signature = 'identity:bootstrap
        {email : Installation owner email}
        {--name= : Installation owner display name}
        {--password= : Owner password; omit to enter it securely}
        {--project=Identity Console : Initial management project name}
        {--client=Identity Console BFF : Initial confidential client name}
        {--client-id= : UUID to assign to the initial confidential client}
        {--client-secret= : Secret to assign to the initial confidential client}
        {--hide-client-secret : Do not print the initial client secret to the terminal}
        {--if-needed : Exit successfully when the installation is already bootstrapped}';

    protected $description = 'Create the first identity installation owner, project, and confidential console client';

    public function handle(
        IdentityClientProvisioner $clients,
        IdentityMembershipRepository $memberships,
    ): int {
        $existingOwner = User::query()->where('is_system_admin', true)->first();
        if ($existingOwner) {
            if ($this->option('if-needed')) {
                $projectSlug = Str::slug((string) $this->option('project'));
                $clientExists = IdentityProjectClient::query()
                    ->where('name', (string) $this->option('client'))
                    ->whereHas('project', fn ($query) => $query->where('slug', $projectSlug))
                    ->exists();

                if (! IdentityProject::query()->where('slug', $projectSlug)->exists() || ! $clientExists) {
                    $this->repairExistingInstallation($existingOwner, $clients, $memberships);
                    $this->info('Identity installation was incomplete; the owner project and console client were reconciled.');

                    return self::SUCCESS;
                }

                $this->info('Identity installation is already bootstrapped.');

                return self::SUCCESS;
            }

            $this->error('This identity installation has already been bootstrapped.');

            return self::FAILURE;
        }

        $email = Str::lower((string) $this->argument('email'));
        $name = (string) ($this->option('name') ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: $this->secret('Owner password'));
        if (mb_strlen($password) < 12) {
            $this->error('The owner password must be at least 12 characters.');

            return self::FAILURE;
        }

        $clientId = $this->option('client-id');
        if ($clientId !== null && ! Str::isUuid((string) $clientId)) {
            $this->error('The client ID must be a UUID.');

            return self::FAILURE;
        }

        $clientSecret = $this->option('client-secret');
        if ($clientSecret !== null && mb_strlen((string) $clientSecret) < 32) {
            $this->error('The client secret must be at least 32 characters.');

            return self::FAILURE;
        }

        [$user, $project, $client, $secret] = DB::transaction(function () use ($clients, $memberships, $email, $name, $password, $clientId, $clientSecret): array {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill([
                'id' => $user->id ?: (string) Str::uuid(),
                'username' => $name,
                'password' => $password,
                'terms' => 'accepted',
                'email_verified_at' => $user->email_verified_at ?: now(),
                'is_system_admin' => true,
            ])->save();

            $projectName = (string) $this->option('project');
            $project = IdentityProject::query()->firstOrCreate(
                ['slug' => Str::slug($projectName)],
                ['name' => $projectName, 'status' => 'active'],
            );
            IdentityProjectAccount::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'username' => $name,
                'password' => $password,
                'email_verified_at' => now(),
                'status' => 'active',
                'password_changed_at' => now(),
            ]);
            $projectId = IdentityProjectId::fromString($project->id);
            $userId = new UserId((string) $user->id);
            $membership = $memberships->findForProjectUser($projectId, $userId)
                ?? DomainIdentityMembership::create($projectId, $userId, true);
            $membership->acceptInvitation(true);
            $memberships->save($membership);
            [$client, $secret] = $clients->create(
                $project->id,
                (string) $this->option('client'),
                $clientId === null ? null : (string) $clientId,
                $clientSecret === null ? null : (string) $clientSecret,
            );

            return [$user, $project, $client, $secret];
        });

        if ($this->option('hide-client-secret')) {
            $this->info('Identity installation bootstrapped. The client secret was not displayed.');
            $this->table(['Owner', 'Project ID', 'Client ID'], [[$user->email, $project->id, $client->id]]);

            return self::SUCCESS;
        }

        $this->info('Identity installation bootstrapped. Store this client secret now; it will not be shown again.');
        $this->table(['Owner', 'Project ID', 'Client ID', 'Client secret'], [[$user->email, $project->id, $client->id, $secret]]);

        return self::SUCCESS;
    }

    private function repairExistingInstallation(
        User $owner,
        IdentityClientProvisioner $clients,
        IdentityMembershipRepository $memberships,
    ): void {
        $email = Str::lower((string) $this->argument('email'));
        $name = (string) ($this->option('name') ?: $owner->username ?: Str::before($email, '@'));
        $password = (string) ($this->option('password') ?: $this->secret('Owner password'));
        if (mb_strlen($password) < 12) {
            throw new \InvalidArgumentException('The owner password must be at least 12 characters.');
        }

        DB::transaction(function () use ($owner, $clients, $memberships, $email, $name, $password): void {
            $owner->fill([
                'email' => $email ?: $owner->email,
                'username' => $name,
                'password' => $password,
                'email_verified_at' => $owner->email_verified_at ?: now(),
                'is_system_admin' => true,
            ])->save();

            $projectName = (string) $this->option('project');
            $project = IdentityProject::query()->firstOrCreate(
                ['slug' => Str::slug($projectName)],
                ['name' => $projectName, 'status' => 'active'],
            );
            $account = IdentityProjectAccount::query()->firstOrNew([
                'project_id' => $project->id,
                'user_id' => $owner->id,
            ]);
            $account->fill([
                'username' => $name,
                'password' => $password,
                'email_verified_at' => now(),
                'status' => 'active',
                'password_changed_at' => now(),
            ])->save();

            $projectId = IdentityProjectId::fromString($project->id);
            $membership = $memberships->findForProjectUser($projectId, new UserId((string) $owner->id))
                ?? DomainIdentityMembership::create($projectId, new UserId((string) $owner->id), true);
            $membership->acceptInvitation(true);
            $memberships->save($membership);

            $client = IdentityProjectClient::query()
                ->where('project_id', $project->id)
                ->where('name', (string) $this->option('client'))
                ->first();
            if (! $client) {
                $clients->create(
                    $project->id,
                    (string) $this->option('client'),
                    $this->option('client-id') === null ? null : (string) $this->option('client-id'),
                    $this->option('client-secret') === null ? null : (string) $this->option('client-secret'),
                );
            }
        });
    }
}
