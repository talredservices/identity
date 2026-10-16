<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Controllers\Authentication;

use App\Services\UserManagementService\API\Resources\Authentication\TemporaryAccountResource;
use App\Services\UserManagementService\Application\Services\Authentication\ProvisionTemporaryAccountService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Response\Attributes\Response;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Doc;
use Zolta\Http\Service\Attributes\Service;

#[Route(path: 'auth/demo-account', methods: ['POST'], middleware: ['api', 'throttle:5,1'], name: 'auth.demo-account.store')]
#[Service(ProvisionTemporaryAccountService::class, 'Temporary demo account created.', 201)]
#[Response(TemporaryAccountResource::class)]
#[Doc(
    summary: 'Create a temporary demo account',
    description: 'Creates short-lived credentials for an enabled demo account.',
    tags: ['Authentication']
)]
final class ProvisionTemporaryAccountController extends Controller {}
