<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Identity\IdentityProjectOperationRequest;
use App\Services\UserManagementService\Application\DTOs\Input\IdentityOperationDTO;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityProjectCatalogService;
use App\Services\UserManagementService\Application\Services\Identity\ExecuteIdentityProjectService;
use App\Services\UserManagementService\Application\Services\Identity\ReadIdentityProjectService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Service;

final class IdentityProjectController extends Controller
{
    private const MIDDLEWARE = ['api', 'auth:sanctum', 'identity.token'];

    #[Route('v1/identity/project-access-catalog', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.project_access_catalog.index')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Project access catalog retrieved.')]
    public function catalog(): void {}

    #[Route('v1/identity/project-access-catalog/permissions', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.project_access_catalog.permissions.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Catalog permission created.', 201)]
    public function storeCatalogPermission(): void {}

    #[Route('v1/identity/project-access-catalog/roles', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.project_access_catalog.roles.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Catalog role created.', 201)]
    public function storeCatalogRole(): void {}

    #[Route('v1/identity/projects', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.projects.index')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityProjectService::class, 'Projects retrieved.')]
    public function index(): void {}

    #[Route('v1/identity/applications', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.applications.index')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityProjectService::class, 'Applications retrieved.')]
    public function applicationDirectory(): void {}

    #[Route('v1/identity/projects', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project created.', 201)]
    public function store(): void {}

    #[Route('v1/identity/projects/{project}', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.projects.show')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityProjectService::class, 'Project retrieved.')]
    public function show(): void {}

    #[Route('v1/identity/projects/{project}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project deletion scheduled.', 202)]
    public function destroy(): void {}

    #[Route('v1/identity/projects/{project}/deletion/cancel', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.deletion.cancel')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project deletion cancelled.')]
    public function cancelDeletion(): void {}

    #[Route('v1/identity/projects/{project}/suspension', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.suspension.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project suspended.')]
    public function suspend(): void {}

    #[Route('v1/identity/projects/{project}/reactivation', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.reactivation.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project reactivated.')]
    public function reactivate(): void {}

    #[Route('v1/identity/projects/{project}/registration', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.registration.update')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Registration policy updated.')]
    public function updateRegistration(): void {}

    #[Route('v1/identity/projects/{project}/environment', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.environment.update')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Project environment updated.')]
    public function updateEnvironment(): void {}

    #[Route('v1/identity/projects/{project}/access-catalog/import', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.access_catalog.import')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Catalog items imported.')]
    public function importCatalogItems(): void {}

    #[Route('v1/identity/projects/{project}/permissions/{permission}/publish', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.permissions.publish')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Permission published to catalog.')]
    public function publishPermission(): void {}

    #[Route('v1/identity/projects/{project}/roles/{role}/publish', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.roles.publish')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectCatalogService::class, 'Role published to catalog.')]
    public function publishRole(): void {}

    #[Route('v1/identity/projects/{project}/webhooks', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.webhooks.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Webhook created.', 201)]
    public function storeWebhook(): void {}

    #[Route('v1/identity/projects/{project}/webhooks/{webhook}', methods: ['PUT'], middleware: self::MIDDLEWARE, name: 'identity.projects.webhooks.update')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Webhook updated.')]
    public function updateWebhook(): void {}

    #[Route('v1/identity/projects/{project}/webhooks/{webhook}/rotate-secret', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.webhooks.rotate')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Webhook secret rotated.')]
    public function rotateWebhookSecret(): void {}

    #[Route('v1/identity/projects/{project}/webhooks/{webhook}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.webhooks.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Webhook removed.')]
    public function destroyWebhook(): void {}

    #[Route('v1/identity/projects/{project}/clients', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Client created.', 201)]
    public function storeClient(): void {}

    #[Route('v1/identity/projects/{project}/clients/{client}/rotate-secret', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.rotate')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Client secret rotated.')]
    public function rotateClient(): void {}

    #[Route('v1/identity/projects/{project}/clients/{client}/name', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.name')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Client name updated.')]
    public function renameClient(): void {}

    #[Route('v1/identity/projects/{project}/clients/{client}', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.status')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Client status updated.')]
    public function setClientStatus(): void {}

    #[Route('v1/identity/projects/{project}/clients/{client}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Client deleted.')]
    public function destroyClient(): void {}

    #[Route('v1/identity/projects/{project}/clients/{client}/permission-manifest', methods: ['PUT'], middleware: self::MIDDLEWARE, name: 'identity.projects.clients.manifest')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Permission manifest synchronized.')]
    public function syncManifest(): void {}

    #[Route('v1/identity/projects/{project}/hosted-applications', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.hosted_applications.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Hosted application created.', 201)]
    public function storeHostedApplication(): void {}

    #[Route('v1/identity/projects/{project}/hosted-applications/{hosted_application}', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.hosted_applications.update')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Hosted application updated.')]
    public function updateHostedApplication(): void {}

    #[Route('v1/identity/projects/{project}/hosted-applications/{hosted_application}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.hosted_applications.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Hosted application removed.')]
    public function destroyHostedApplication(): void {}

    #[Route('v1/identity/projects/{project}/applications', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.projects.applications.index')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityProjectService::class, 'Applications retrieved.')]
    public function applications(): void {}

    #[Route('v1/identity/projects/{project}/applications', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.applications.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Application created.', 201)]
    public function storeApplication(): void {}

    #[Route('v1/identity/projects/{project}/applications/{hosted_application}', methods: ['PATCH'], middleware: self::MIDDLEWARE, name: 'identity.projects.applications.update')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Application updated.')]
    public function updateApplication(): void {}

    #[Route('v1/identity/projects/{project}/applications/{hosted_application}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.applications.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Application removed.')]
    public function destroyApplication(): void {}

    #[Route('v1/identity/projects/{project}/roles', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.roles.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Role created.', 201)]
    public function storeRole(): void {}

    #[Route('v1/identity/projects/{project}/roles/{role}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.roles.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Role deleted.')]
    public function destroyRole(): void {}

    #[Route('v1/identity/projects/{project}/permissions', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.permissions.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Permission created.', 201)]
    public function storePermission(): void {}

    #[Route('v1/identity/projects/{project}/permissions/{permission}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.permissions.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Permission deleted.')]
    public function destroyPermission(): void {}

    #[Route('v1/identity/projects/{project}/roles/{role}/permissions', methods: ['PUT'], middleware: self::MIDDLEWARE, name: 'identity.projects.roles.permissions')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Role permissions updated.')]
    public function setRolePermissions(): void {}

    #[Route('v1/identity/projects/{project}/invitations', methods: ['POST'], middleware: self::MIDDLEWARE, name: 'identity.projects.invitations.store')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Invitation created.', 201)]
    public function invite(): void {}

    #[Route('v1/identity/projects/{project}/memberships/{membership}/access', methods: ['PUT'], middleware: self::MIDDLEWARE, name: 'identity.projects.memberships.access')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Membership access updated.')]
    public function setMembershipAccess(): void {}

    #[Route('v1/identity/projects/{project}/memberships/{membership}', methods: ['DELETE'], middleware: self::MIDDLEWARE, name: 'identity.projects.memberships.destroy')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ExecuteIdentityProjectService::class, 'Membership removed.')]
    public function destroyMembership(): void {}

    #[Route('v1/identity/projects/{project}/audit', methods: ['GET'], middleware: self::MIDDLEWARE, name: 'identity.projects.audit')]
    #[Request(IdentityProjectOperationRequest::class, IdentityOperationDTO::class)]
    #[Service(ReadIdentityProjectService::class, 'Audit events retrieved.')]
    public function audit(): void {}
}
