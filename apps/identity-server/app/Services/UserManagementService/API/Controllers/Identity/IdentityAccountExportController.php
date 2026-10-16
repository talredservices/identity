<?php

namespace App\Services\UserManagementService\API\Controllers\Identity;

use App\Services\UserManagementService\API\Requests\Authentication\ExportAccountDataRequest;
use App\Services\UserManagementService\API\Resources\Authentication\AccountDataExportResource;
use App\Services\UserManagementService\Application\DTOs\Input\ExportAccountDataDTO;
use App\Services\UserManagementService\Application\Services\Authentication\ExportAccountDataService;
use Zolta\Http\Controller\Controller;
use Zolta\Http\Request\Attributes\Request;
use Zolta\Http\Response\Attributes\Response;
use Zolta\Http\Router\Attributes\Route;
use Zolta\Http\Service\Attributes\Doc;
use Zolta\Http\Service\Attributes\Service;

#[Route(path: 'v1/identity/auth/account/export', methods: ['POST'], middleware: ['api', 'auth:sanctum', 'identity.token', 'throttle:5,1', 'identity.reauthenticate:export'], name: 'identity.auth.account.export')]
#[Request(ExportAccountDataRequest::class, ExportAccountDataDTO::class)]
#[Service(ExportAccountDataService::class, 'Account data exported.', 200)]
#[Response(AccountDataExportResource::class)]
#[Doc(summary: 'Export account data', description: 'Export the authenticated user account and workspace data.', tags: ['Authentication'])]
final class IdentityAccountExportController extends Controller {}
