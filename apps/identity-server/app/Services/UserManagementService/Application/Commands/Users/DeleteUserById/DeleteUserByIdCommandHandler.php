<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Application\Commands\Users\DeleteUserById;

use App\Services\UserManagementService\Application\Contracts\AccountDataEraserInterface;
use App\Services\UserManagementService\Application\Contracts\IdentityLifecyclePublisherInterface;
use App\Services\UserManagementService\Domain\Repositories\UserRepository;
use Zolta\Cqrs\Attributes\HandlesCommand;
use Zolta\Cqrs\Services\Result;

#[HandlesCommand(DeleteUserByIdCommand::class)]
final readonly class DeleteUserByIdCommandHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private AccountDataEraserInterface $accountDataEraser,
        private IdentityLifecyclePublisherInterface $lifecycle,
    ) {}

    public function __invoke(DeleteUserByIdCommand $deleteUserByIdCommand): Result
    {
        $user = $this->userRepository->findUserById($deleteUserByIdCommand->id);

        if ($user !== null) {
            $email = (string) $user->getEmail()->get('address');
            if ($this->lifecycle->requestUserDeletion((string) $user->getId()->get('value'))) {
                return Result::success(['message' => 'Account deletion is waiting for connected applications to erase user data.']);
            }
            $this->accountDataEraser->erase(
                $user->getId(),
                $email
            );
            $this->userRepository->deleteUser($user);
        }

        return Result::success(['message' => 'The user has been deleted successfully!']);
    }
}
