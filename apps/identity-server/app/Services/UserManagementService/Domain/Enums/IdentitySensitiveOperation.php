<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Domain\Enums;

enum IdentitySensitiveOperation: string
{
    case Profile = 'profile';
    case Password = 'password';
    case Export = 'export';
    case DeleteAccount = 'delete_account';
    case RevokeSession = 'revoke_session';
}
