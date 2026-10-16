<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Domain\Aggregates;

use App\Services\UserManagementService\Domain\Enums\IdentityClientStatus;
use App\Services\UserManagementService\Domain\Exceptions\InvalidIdentityClientConfigurationException;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityClientId;
use App\Services\UserManagementService\Domain\ValueObjects\IdentityProjectId;
use DateTimeImmutable;
use Zolta\Domain\Aggregates\AggregateRoot;

final class IdentityClient extends AggregateRoot
{
    private function __construct(
        private readonly IdentityClientId $id,
        private readonly IdentityProjectId $projectId,
        private string $name,
        private string $secretHash,
        private string $secretPrefix,
        private IdentityClientStatus $status,
        private ?DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->assertName($name);
        $this->assertCredentials($secretHash, $secretPrefix);
    }

    public static function create(
        IdentityProjectId $projectId,
        string $name,
        string $secretHash,
        string $secretPrefix,
    ): self {
        $now = new DateTimeImmutable;

        return new self(
            new IdentityClientId,
            $projectId,
            $name,
            $secretHash,
            $secretPrefix,
            IdentityClientStatus::Active,
            null,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        IdentityClientId $id,
        IdentityProjectId $projectId,
        string $name,
        string $secretHash,
        string $secretPrefix,
        IdentityClientStatus $status,
        ?DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $projectId,
            $name,
            $secretHash,
            $secretPrefix,
            $status,
            $lastUsedAt,
            $createdAt,
            $updatedAt,
        );
    }

    public function rotateCredentials(string $secretHash, string $secretPrefix): void
    {
        $this->assertCredentials($secretHash, $secretPrefix);
        $this->secretHash = $secretHash;
        $this->secretPrefix = $secretPrefix;
        $this->touch();
    }

    public function changeStatus(IdentityClientStatus $status): void
    {
        if ($status === $this->status) {
            return;
        }

        $this->status = $status;
        $this->touch();
    }

    public function changeName(string $name): void
    {
        $name = trim($name);
        $this->assertName($name);

        if ($name === $this->name) {
            return;
        }

        $this->name = $name;
        $this->touch();
    }

    public function id(): IdentityClientId
    {
        return $this->id;
    }

    public function projectId(): IdentityProjectId
    {
        return $this->projectId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function secretHash(): string
    {
        return $this->secretHash;
    }

    public function secretPrefix(): string
    {
        return $this->secretPrefix;
    }

    public function status(): IdentityClientStatus
    {
        return $this->status;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidIdentityClientConfigurationException('Client name cannot be empty.');
        }
    }

    private function assertCredentials(string $secretHash, string $secretPrefix): void
    {
        if (strlen($secretHash) !== 64 || $secretPrefix === '') {
            throw new InvalidIdentityClientConfigurationException(
                'Client credentials are malformed.',
            );
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }
}
