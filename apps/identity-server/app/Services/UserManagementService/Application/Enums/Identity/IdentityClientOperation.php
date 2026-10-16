<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Enums\Identity;

enum IdentityClientOperation: string
{
    case Create = 'projects.clients.store';
    case RotateSecret = 'projects.clients.rotate';
    case Rename = 'projects.clients.name';
    case SetStatus = 'projects.clients.status';
    case Delete = 'projects.clients.destroy';
    case SyncPermissionManifest = 'projects.clients.manifest';
}
