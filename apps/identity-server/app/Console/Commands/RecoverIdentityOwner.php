<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectAccount;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityAuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RecoverIdentityOwner extends Command
{
    protected $signature = 'identity:recover-owner {--email= : Owner email; required when more than one system administrator exists}';

    protected $description = 'Rotate an installation owner password without deleting identity data';

    public function handle(IdentityAuditRecorder $audit): int
    {
        $configuredKey = (string) (config('zolta.identity.recovery_key') ?: '');
        $providedKey = (string) $this->secret('Recovery key');
        if ($configuredKey === '' || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            $this->error('Recovery authorization failed.');

            return self::FAILURE;
        }

        $owners = User::query()->where('is_system_admin', true);
        $email = Str::lower(trim((string) ($this->option('email') ?: '')));
        if ($email !== '') {
            $owners->where('email', $email);
        }
        $matches = $owners->get();
        if ($matches->count() === 0) {
            $this->error('No system administrator matched the supplied selector.');

            return self::FAILURE;
        }
        if ($matches->count() > 1) {
            $this->error('More than one system administrator exists; provide --email.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New owner password');
        $confirmation = (string) $this->secret('Confirm new owner password');
        if ($password === '' || ! hash_equals($password, $confirmation) || mb_strlen($password) < 12) {
            $this->error('The new password must match its confirmation and be at least 12 characters.');

            return self::FAILURE;
        }

        $owner = $matches->first();
        DB::transaction(function () use ($owner, $password, $audit): void {
            $owner->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'locked' => false,
                'lock_expiry' => null,
            ])->save();

            IdentityProjectAccount::query()->where('user_id', $owner->getKey())->get()->each(function (IdentityProjectAccount $account) use ($password): void {
                $account->forceFill([
                    'password' => Hash::make($password),
                    'password_changed_at' => now(),
                    'status' => 'active',
                ])->save();
            });

            $owner->tokens()->delete();
            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $owner->email)->delete();
            }
            $audit->record('auth.identity_owner_recovered', null, null, null, 'user', (string) $owner->getKey(), [
                'channel' => 'break_glass_cli',
                'credentials_rotated' => true,
            ]);
        });

        $this->info('Identity owner password rotated successfully. Existing sessions were revoked; identity data was preserved.');

        return self::SUCCESS;
    }
}
