<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services;

use App\Services\UserManagementService\Application\Contracts\AccountDataExporterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Zolta\Domain\ValueObjects\UserId;

final class LaravelAccountDataExporter implements AccountDataExporterInterface
{
    public function export(UserId $userId): array
    {
        $id = (string) $userId->get('value');
        $accountColumns = array_values(array_intersect([
            'id',
            'username',
            'email',
            'email_verified_at',
            'profile_picture',
            'theme_preference',
            'language_preference',
            'provider_id',
            'created_at',
            'updated_at',
        ], Schema::hasTable('users') ? Schema::getColumnListing('users') : []));
        $account = in_array('id', $accountColumns, true)
            ? DB::table('users')->where('id', $id)->first($accountColumns)
            : null;

        $jobOffers = $this->rowsByUserId('job_offers', $id);
        $documentIds = collect($this->rowsByUserId('editor_documents', $id))
            ->pluck('id')
            ->all();
        $chatIds = collect($this->rowsByUserId('chats', $id))
            ->pluck('id')
            ->all();

        return [
            'version' => 1,
            'exported_at' => now()->toAtomString(),
            'account' => $account ? (array) $account : null,
            'jobs' => [
                'tracked' => $jobOffers,
                'manual_sources' => $this->rowsByUserId('user_jobs', $id),
            ],
            'documents' => [
                'items' => $this->rowsByUserId('editor_documents', $id),
                'tags' => $this->rowsByUserId('document_tags', $id),
                'tag_assignments' => $this->rowsWhereIn('editor_document_tag', 'document_id', $documentIds),
            ],
            'assistant_conversations' => [
                'chats' => $this->rowsByUserId('chats', $id),
                'user_messages' => $this->rowsWhereIn('user_messages', 'chat_id', $chatIds),
                'assistant_messages' => $this->rowsWhereIn('system_messages', 'chat_id', $chatIds),
            ],
            'preferences' => [
                'jobs' => $this->firstByUserId('job_preferences', $id),
                'notifications' => $this->firstByUserId('notification_preferences', $id),
            ],
            'notifications' => $this->rowsByUserId('notifications', $id),
        ];
    }

    private function rowsByUserId(string $table, string $userId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return [];
        }

        return DB::table($table)
            ->where('user_id', $userId)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    private function firstByUserId(string $table, string $userId): ?array
    {
        return $this->rowsByUserId($table, $userId)[0] ?? null;
    }

    private function rowsWhereIn(string $table, string $column, array $values): array
    {
        if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)
            ->whereIn($column, $values)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
