<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Persistence\Seeders;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProject;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectClient;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class IdentityConsoleSeeder extends Seeder
{
    public function run(): void
    {

        $email = trim((string) getenv('IDENTITY_BOOTSTRAP_EMAIL'));
        $password = (string) getenv('IDENTITY_BOOTSTRAP_PASSWORD');
        $clientSecret = (string) getenv('IDENTITY_CONSOLE_CLIENT_SECRET');
        if ($email === '' || $password === '' || strlen($clientSecret) < 32) {
            throw new RuntimeException('Identity Console bootstrap settings are incomplete.');
        }

        DB::transaction(function () use ($email, $password, $clientSecret): void {
            $user = User::query()->where('email', $email)->first()
                ?? User::query()->where('is_system_admin', true)->firstOrFail();
            $user->email_verified_at = $user->email_verified_at ?: now();
            $user->is_system_admin = true;
            $user->save();
            $project = IdentityProject::query()->where('slug', 'identity-console')->firstOrFail();
            $account = IdentityProjectAccount::query()->firstOrNew([
                'project_id' => $project->id,
                'user_id' => $user->id,
            ]);
            $accountAttributes = [
                'username' => $user->username,
                'email_verified_at' => now(),
                'status' => 'active',
            ];
            if (! $account->exists) {
                $accountAttributes['password'] = $password;
                $accountAttributes['password_changed_at'] = now();
            }
            $account->fill($accountAttributes)->save();
            $client = IdentityProjectClient::query()
                ->where('name', 'Identity Console BFF')
                ->where('project_id', $project->id)
                ->firstOrFail();
            $client->secret_hash = hash('sha256', $clientSecret);
            $client->secret_prefix = substr($clientSecret, 0, 8);
            $client->status = 'active';
            $client->save();
        });
    }
}
