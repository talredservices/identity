<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\AcceptIdentityInvitation;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\IssueIdentityAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentityAccessContext;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\RecoverIdentityPassword;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\SyncIdentityClientManifest;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\VerifyIdentityEmail;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectEnvironment;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectRegistration;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\CreateIdentityProject;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityClients;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectSuspension;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityWebhooks;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ReadIdentityProjects;
use App\Services\UserManagementService\Application\Contracts\IdentityInstallationServiceInterface;
use App\Services\UserManagementService\Application\Contracts\OAuthGateway;
use App\Services\UserManagementService\Application\Contracts\SecretGenerator;
use App\Services\UserManagementService\Domain\Repositories\IdentityClientRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityMembershipRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityPermissionRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityProjectRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityRoleRepository;
use App\Services\UserManagementService\Domain\Repositories\IdentityWebhookRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityClientRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityMembershipRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityPermissionRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityProjectRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityRoleRepository;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityWebhookRepository;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityAuthenticationService;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityInstallationService;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityProjectService;
use App\Services\UserManagementService\Infrastructure\Services\LaravelSecretGenerator;
use App\Services\UserManagementService\Infrastructure\Services\SocialiteOAuthGateway;
use Illuminate\Routing\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;
use Zolta\Cqrs\Repositories\BaseRepository;
use Zolta\Http\Router\Laravel\Bootstrap\AutoInvokeProxyController;

final class IdentityArchitectureTest extends TestCase
{
    private const IDENTITY_ROUTE_SNAPSHOT = '86b23d67cb4e7ffef81d9b51c7da63128b20396f0e15f70ba42d5c90bb7b7643';

    public function test_identity_api_routes_are_owned_by_the_zolta_attribute_pipeline(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/identity/'));

        $this->assertCount(91, $routes);

        $routes->each(function (Route $route): void {
            $this->assertSame(
                AutoInvokeProxyController::class.'@__invoke',
                $route->getActionName(),
                "Identity route [{$route->uri()}] bypasses the Zolta attribute pipeline.",
            );
            $this->assertNotEmpty($route->getName());
        });
    }

    public function test_identity_route_contract_matches_the_compatibility_snapshot(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/identity/'))
            ->map(static fn (Route $route): array => [
                'methods' => $route->methods(),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'middleware' => $route->gatherMiddleware(),
            ])
            ->sortBy('name')
            ->values()
            ->all();

        $this->assertSame(
            self::IDENTITY_ROUTE_SNAPSHOT,
            hash('sha256', (string) json_encode($routes, JSON_UNESCAPED_SLASHES)),
            'The public Identity route contract changed. Review compatibility before updating the snapshot.',
        );
    }

    public function test_clean_layer_dependency_direction_is_enforced(): void
    {
        $serviceRoot = app_path('Services/UserManagementService');

        $this->assertLayerHasNoForbiddenImports("{$serviceRoot}/Domain", [
            'App\\Services\\UserManagementService\\Application',
            'App\\Services\\UserManagementService\\API',
            'App\\Services\\UserManagementService\\Infrastructure',
            'Illuminate\\',
            'Symfony\\',
        ]);
        $this->assertLayerHasNoForbiddenImports("{$serviceRoot}/Application", [
            'App\\Services\\UserManagementService\\API',
            'App\\Services\\UserManagementService\\Infrastructure',
            'Illuminate\\',
            'Symfony\\',
        ]);
        $this->assertLayerHasNoForbiddenImports("{$serviceRoot}/API", [
            'App\\Services\\UserManagementService\\Infrastructure',
            'Illuminate\\Database\\Eloquent',
        ]);
    }

    public function test_identity_aggregate_repositories_use_the_zolta_repository_base(): void
    {
        foreach ([
            EloquentIdentityProjectRepository::class,
            EloquentIdentityClientRepository::class,
            EloquentIdentityMembershipRepository::class,
            EloquentIdentityPermissionRepository::class,
            EloquentIdentityRoleRepository::class,
            EloquentIdentityWebhookRepository::class,
        ] as $repository) {
            $this->assertTrue(is_subclass_of($repository, BaseRepository::class), "{$repository} must use the Zolta repository base.");
        }
    }

    public function test_identity_controllers_are_declarative_http_definitions(): void
    {
        $directory = app_path('Services/UserManagementService/API/Controllers/Identity');
        $violations = [];
        $forbidden = [
            'use Illuminate\\Http\\Request;',
            'use Illuminate\\Http\\JsonResponse;',
            'use Illuminate\\Support\\Facades\\Validator;',
            'response()->',
            'Validator::',
        ];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $expression) {
                if (str_contains($contents, $expression)) {
                    $violations[] = $file->getFilename()." contains {$expression}";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_identity_contracts_resolve_to_focused_infrastructure_adapters(): void
    {
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(IssueIdentityAccess::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(ReadIdentityAccessContext::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(VerifyIdentityEmail::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(RecoverIdentityPassword::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(ManageIdentitySessions::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(ReadIdentitySessions::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(AcceptIdentityInvitation::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityAuthenticationService::class,
            app(SyncIdentityClientManifest::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityInstallationService::class,
            app(IdentityInstallationServiceInterface::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(CreateIdentityProject::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ConfigureIdentityProjectRegistration::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ConfigureIdentityProjectEnvironment::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ManageIdentityWebhooks::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ManageIdentityClients::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ManageIdentityProjectAccess::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ManageIdentityProjectSuspension::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectService::class,
            app(ReadIdentityProjects::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityProjectRepository::class,
            app(IdentityProjectRepository::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityClientRepository::class,
            app(IdentityClientRepository::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityMembershipRepository::class,
            app(IdentityMembershipRepository::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityPermissionRepository::class,
            app(IdentityPermissionRepository::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityRoleRepository::class,
            app(IdentityRoleRepository::class),
        );
        $this->assertInstanceOf(
            EloquentIdentityWebhookRepository::class,
            app(IdentityWebhookRepository::class),
        );
        $this->assertInstanceOf(SocialiteOAuthGateway::class, app(OAuthGateway::class));
        $this->assertInstanceOf(LaravelSecretGenerator::class, app(SecretGenerator::class));
    }

    /** @param list<string> $forbiddenNamespaces */
    private function assertLayerHasNoForbiddenImports(string $directory, array $forbiddenNamespaces): void
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            foreach ($forbiddenNamespaces as $namespace) {
                if (str_contains($contents, "use {$namespace}")) {
                    $violations[] = str_replace(app_path().'/', '', $file->getPathname())." imports {$namespace}";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }
}
