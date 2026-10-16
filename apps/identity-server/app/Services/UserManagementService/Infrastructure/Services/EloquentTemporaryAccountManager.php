<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\AccountDataEraserInterface;
use App\Services\UserManagementService\Application\Contracts\TemporaryAccountManagerInterface;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use App\Services\UserManagementService\Infrastructure\Webhooks\IdentityWebhookPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Zolta\Domain\ValueObjects\UserId;

final readonly class EloquentTemporaryAccountManager implements TemporaryAccountManagerInterface
{
    public function __construct(
        private AccountDataEraserInterface $dataEraser,
        private IdentityWebhookPublisher $webhooks,
    ) {}

    public function provision(): array
    {
        if (! config('zolta.demo.accounts_enabled')) {
            throw new RuntimeException('Temporary demo accounts are disabled.');
        }

        return DB::transaction(function (): array {
            $id = (string) Str::uuid();
            $suffix = Str::lower(Str::random(8));
            $password = 'Demo!'.Str::random(20);
            $expiresAt = now()->addMinutes((int) config('zolta.demo.account_lifetime_minutes'));

            User::query()->create([
                'id' => $id,
                'username' => "Demo Guest {$suffix}",
                'email' => "demo-{$id}@identity.invalid",
                'password' => $password,
                'terms' => 'accepted',
                'email_verified_at' => now(),
                'is_temporary' => true,
                'demo_expires_at' => $expiresAt,
                'login_alerts_enabled' => false,
            ]);

            return [
                'email' => "demo-{$id}@identity.invalid",
                'password' => $password,
                'expires_at' => $expiresAt->toAtomString(),
            ];
        });
    }

    public function purgeExpired(): int
    {
        $purged = 0;

        User::query()
            ->where('is_temporary', true)
            ->where('demo_expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$purged): void {
                foreach ($users as $user) {
                    $user->identityMemberships()->pluck('project_id')->each(function (string $projectId) use ($user): void {
                        $this->webhooks->publish($projectId, 'identity.user.expired', [
                            'user_id' => (string) $user->id,
                            'reason' => 'sandbox_ttl_elapsed',
                            'temporary_expires_at' => $user->demo_expires_at?->toIso8601String(),
                        ]);
                    });
                    $this->dataEraser->erase(new UserId((string) $user->id), (string) $user->email);
                    $user->delete();
                    $purged++;
                }
            });

        return $purged;
    }
}
