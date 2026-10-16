<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UserManagementService\Domain\Aggregates\IdentityProject;
use App\Services\UserManagementService\Domain\Enums\IdentityProjectMode;
use App\Services\UserManagementService\Domain\Enums\IdentityProjectRegistrationMode;
use App\Services\UserManagementService\Domain\Enums\IdentityProjectStatus;
use App\Services\UserManagementService\Domain\Exceptions\InvalidIdentityProjectConfigurationException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class IdentityProjectTest extends TestCase
{
    public function test_new_project_starts_with_safe_production_defaults(): void
    {
        $project = IdentityProject::create('Example Application', 'example-application', 'Identity boundary');

        $this->assertSame('Example Application', $project->name());
        $this->assertSame('example-application', $project->slug());
        $this->assertSame(IdentityProjectStatus::Active, $project->status());
        $this->assertSame(IdentityProjectMode::Live, $project->mode());
        $this->assertSame(60, $project->sandboxTtlMinutes());
        $this->assertSame(
            IdentityProjectRegistrationMode::InviteOnly,
            $project->registrationMode(),
        );
        $this->assertNull($project->registrationRoleId());
        $this->assertTrue($project->emailVerificationRequired());
    }

    public function test_registration_and_environment_are_changed_through_domain_behavior(): void
    {
        $project = IdentityProject::create('Example Application', 'example-application');

        $project->configureRegistration(IdentityProjectRegistrationMode::Public, 'role-id', false);
        $project->configureEnvironment(IdentityProjectMode::Sandbox, 90);

        $this->assertSame(IdentityProjectRegistrationMode::Public, $project->registrationMode());
        $this->assertSame('role-id', $project->registrationRoleId());
        $this->assertFalse($project->emailVerificationRequired());
        $this->assertSame(IdentityProjectMode::Sandbox, $project->mode());
        $this->assertSame(90, $project->sandboxTtlMinutes());
    }

    public function test_sandbox_lifetime_is_enforced_inside_the_domain(): void
    {
        $project = IdentityProject::create('Example Application', 'example-application');

        $this->expectException(InvalidIdentityProjectConfigurationException::class);
        $project->configureEnvironment(IdentityProjectMode::Sandbox, 4);
    }

    public function test_project_deletion_can_be_scheduled_and_cancelled_without_losing_prior_status(): void
    {
        $project = IdentityProject::create('Example Application', 'example-application');
        $project->configureEnvironment(IdentityProjectMode::Sandbox, 90);
        $deadline = new DateTimeImmutable('+30 days');

        $project->scheduleDeletion($deadline);

        $this->assertSame(IdentityProjectStatus::PendingDeletion, $project->status());
        $this->assertEquals($deadline, $project->deletionScheduledAt());
        $this->assertSame(IdentityProjectStatus::Active, $project->deletionPreviousStatus());

        $project->cancelDeletion();

        $this->assertSame(IdentityProjectStatus::Active, $project->status());
        $this->assertNull($project->deletionScheduledAt());
        $this->assertNull($project->deletionPreviousStatus());
    }

    public function test_project_suspension_is_reversible_and_cannot_override_scheduled_deletion(): void
    {
        $project = IdentityProject::create('Example Application', 'example-application');

        $this->assertTrue($project->suspend());
        $this->assertSame(IdentityProjectStatus::Suspended, $project->status());
        $this->assertFalse($project->suspend());
        $this->assertTrue($project->reactivate());
        $this->assertSame(IdentityProjectStatus::Active, $project->status());

        $project->scheduleDeletion(new DateTimeImmutable('+30 days'));

        $this->expectException(InvalidIdentityProjectConfigurationException::class);
        $project->suspend();
    }
}
