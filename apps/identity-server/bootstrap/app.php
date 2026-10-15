<?php

use App\Services\UserManagementService\API\Middleware\ProtectIdentityAdministration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This application exposes an API, not a Laravel web login page. Guests
        // must receive 401 responses instead of a redirect to a nonexistent route.
        $middleware->redirectGuestsTo(null);
        $middleware->append(ProtectIdentityAdministration::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
