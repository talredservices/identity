<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Webhooks;

use App\Services\UserManagementService\Application\Contracts\IdentityLifecyclePublisherInterface;
use App\Services\UserManagementService\Infrastructure\Jobs\DeliverIdentityWebhook;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityProjectMembership;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookDelivery;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityWebhookEndpoint;
use Illuminate\Support\Str;

final class IdentityWebhookPublisher implements IdentityLifecyclePublisherInterface
{
    public function requestUserDeletion(
        string $userId,
        string $reason = 'account_deletion_requested',
    ): bool {
        $deliveries = 0;
        IdentityProjectMembership::query()
            ->where('user_id', $userId)
            ->pluck('project_id')
            ->each(function (string $projectId) use ($userId, $reason, &$deliveries): void {
                $deliveries += $this->publish($projectId, 'identity.user.deletion_requested', [
                    'user_id' => $userId,
                    'reason' => $reason,
                ]);
            });

        return $deliveries > 0;
    }

    /** @param array<string, mixed> $data */
    public function publish(string $projectId, string $event, array $data): int
    {
        $eventId = (string) Str::uuid();
        $payload = [
            'id' => $eventId,
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'project_id' => $projectId,
            'data' => $data,
        ];

        $deliveries = 0;
        IdentityWebhookEndpoint::query()
            ->where('project_id', $projectId)
            ->where('status', 'active')
            ->get()
            ->filter(static fn (IdentityWebhookEndpoint $endpoint): bool => in_array($event, $endpoint->events ?? [], true))
            ->each(function (IdentityWebhookEndpoint $endpoint) use ($eventId, $event, $payload, $data, &$deliveries): void {
                $delivery = IdentityWebhookDelivery::query()->create([
                    'event_id' => $eventId,
                    'subject_id' => $data['user_id'] ?? null,
                    'endpoint_id' => $endpoint->id,
                    'event' => $event,
                    'payload' => $payload,
                    'status' => 'queued',
                ]);
                $deliveries++;
                DeliverIdentityWebhook::dispatch($delivery->id)->afterCommit();
            });

        return $deliveries;
    }
}
