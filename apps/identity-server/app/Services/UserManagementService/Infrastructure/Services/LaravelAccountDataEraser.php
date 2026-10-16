<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\AccountDataEraserInterface;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zolta\Domain\ValueObjects\UserId;

final class LaravelAccountDataEraser implements AccountDataEraserInterface
{
    public function erase(UserId $userId, string $email): void
    {
        $id = (string) $userId->get('value');

        DB::transaction(function () use ($id, $email): void {
            $this->deleteByUserId('job_offers', $id);
            $this->deleteByUserId('user_jobs', $id);
            $this->deleteByUserId('chats', $id);
            $this->deleteByUserId('editor_documents', $id);
            $this->deleteByUserId('document_tags', $id);
            $this->deleteByUserId('notifications', $id);
            $this->deleteByUserId('notification_preferences', $id);
            $this->deleteByUserId('job_preferences', $id);
            $this->deleteByUserId('billing_transactions', $id);
            $this->deleteByUserId('credit_ledger_entries', $id);
            $this->deleteByUserId('credit_wallets', $id);
            $this->deleteByUserId('permission_user', $id);
            $this->deleteByUserId('social_accounts', $id);

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_id', $id)
                    ->where('tokenable_type', User::class)
                    ->delete();
            }

            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $email)->delete();
            }
        });
    }

    private function deleteByUserId(string $table, string $userId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
            DB::table($table)->where('user_id', $userId)->delete();
        }
    }
}
