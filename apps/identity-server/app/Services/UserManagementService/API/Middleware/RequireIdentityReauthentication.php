<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Middleware;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireIdentityReauthentication
{
    public function __construct(private ManageIdentityAccountSecurity $security) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $grant = (string) $request->header('X-Identity-Reauthentication', '');
        if (strlen($grant) !== 64) {
            return response()->json(['message' => 'Fresh authentication is required.', 'code' => 'reauthentication_required'], 403);
        }
        try {
            $this->security->consumeGrant((string) $request->bearerToken(), $grant, $operation);
        } catch (IdentityAuthenticationException) {
            return response()->json(['message' => 'Fresh authentication is required.', 'code' => 'reauthentication_required'], 403);
        }

        return $next($request);
    }
}
