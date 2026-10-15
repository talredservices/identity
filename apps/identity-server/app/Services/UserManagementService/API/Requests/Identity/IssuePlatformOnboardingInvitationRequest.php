<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Requests\Identity;

use Zolta\Http\Request\BaseRequest;

final class IssuePlatformOnboardingInvitationRequest extends BaseRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'string', 'max:256'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ];
    }
}
