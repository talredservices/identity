<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Commands\Identity\ExecuteIdentityAccountSecurity;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Payloads\Identity\IdentityOperationPayload;
use InvalidArgumentException;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Services\Result;

#[HandlesCommand(ExecuteIdentityAccountSecurityCommand::class)]
final readonly class ExecuteIdentityAccountSecurityCommandHandler
{
    public function __construct(private ManageIdentityAccountSecurity $security) {}

    public function __invoke(ExecuteIdentityAccountSecurityCommand $command): Result
    {
        if ($command->operation === 'auth.reauthenticate') {
            $result = $this->security->reauthenticate($command->accessToken, $command->input);
        } elseif ($command->operation === 'auth.account.password') {
            $this->security->changePassword($command->accessToken, $command->input['password']);
            $result = ['message' => 'Project password changed. Other project sessions were revoked.'];
        } else {
            throw new InvalidArgumentException('Unsupported account security operation.');
        }

        return Result::success(new IdentityOperationPayload($result));
    }
}
