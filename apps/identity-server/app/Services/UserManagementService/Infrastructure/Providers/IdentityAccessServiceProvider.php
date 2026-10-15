<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Providers;

use App\Services\UserManagementService\API\Middleware\IdentityIntrospectionMiddleware;
use App\Services\UserManagementService\API\Middleware\NitroInternalMiddleware;
use App\Services\UserManagementService\API\Middleware\RequireIdentityAccessToken;
use App\Services\UserManagementService\API\Middleware\RequireIdentityReauthentication;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\AcceptIdentityInvitation;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\IssueIdentityAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityGoogleAuthorization;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityHandoffs;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentityAccessContext;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ReadIdentitySessions;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\RecoverIdentityPassword;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\SyncIdentityClientManifest;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\VerifyIdentityEmail;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectEnvironment;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ConfigureIdentityProjectRegistration;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\CreateIdentityProject;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\IssuePlatformOnboardingInvitation;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityClients;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityHostedApplications;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectAccess;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectCatalog;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectDeletion;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityProjectSuspension;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ManageIdentityWebhooks;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ReadIdentityProjects;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ResolveIdentityHostedApplicationContext;
use App\Services\UserManagementService\Application\Contracts\Identity\Projects\ResolveIdentityHostedApplications;
use App\Services\UserManagementService\Application\Contracts\IdentityInstallationServiceInterface;
use App\Services\UserManagementService\Application\Contracts\IdentityLifecyclePublisherInterface;
use App\Services\UserManagementService\Infrastructure\Repositories\EloquentIdentityHostedApplicationContextRepository;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityAccountSecurity;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityAuthenticationService;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityInstallationService;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityProjectService;
use App\Services\UserManagementService\Infrastructure\Services\Identity\EloquentPlatformOnboardingInvitationIssuer;
use App\Services\UserManagementService\Infrastructure\Services\Identity\IdentityGoogleAuthorization;
use App\Services\UserManagementService\Infrastructure\Webhooks\IdentityWebhookPublisher;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class IdentityAccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ManageIdentityAccountSecurity::class, EloquentIdentityAccountSecurity::class);
        $this->app->bind(ManageIdentityGoogleAuthorization::class, IdentityGoogleAuthorization::class);
        $this->app->bind(IssueIdentityAccess::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(ReadIdentityAccessContext::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(VerifyIdentityEmail::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(RecoverIdentityPassword::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(ManageIdentitySessions::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(ManageIdentityHandoffs::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(ReadIdentitySessions::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(AcceptIdentityInvitation::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(SyncIdentityClientManifest::class, EloquentIdentityAuthenticationService::class);
        $this->app->bind(IdentityInstallationServiceInterface::class, EloquentIdentityInstallationService::class);
        $this->app->bind(CreateIdentityProject::class, EloquentIdentityProjectService::class);
        $this->app->bind(ConfigureIdentityProjectRegistration::class, EloquentIdentityProjectService::class);
        $this->app->bind(ConfigureIdentityProjectEnvironment::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityWebhooks::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityClients::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityHostedApplications::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityProjectAccess::class, EloquentIdentityProjectService::class);
        $this->app->bind(IssuePlatformOnboardingInvitation::class, EloquentPlatformOnboardingInvitationIssuer::class);
        $this->app->bind(ManageIdentityProjectDeletion::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityProjectSuspension::class, EloquentIdentityProjectService::class);
        $this->app->bind(ManageIdentityProjectCatalog::class, EloquentIdentityProjectService::class);
        $this->app->bind(ReadIdentityProjects::class, EloquentIdentityProjectService::class);
        $this->app->bind(ResolveIdentityHostedApplications::class, EloquentIdentityProjectService::class);
        $this->app->bind(ResolveIdentityHostedApplicationContext::class, EloquentIdentityHostedApplicationContextRepository::class);
        $this->app->bind(IdentityLifecyclePublisherInterface::class, IdentityWebhookPublisher::class);

        $configPath = config_path('zolta.php');
        $this->mergeConfigFrom($configPath, 'zolta');

        $defaults = (array) require $configPath;
        $configured = array_replace_recursive($defaults, (array) config('zolta', []));

        $this->app['config']->set('zolta', $configured);
        $this->app['config']->set(
            'identity',
            array_replace_recursive(
                (array) ($configured['identity'] ?? []),
                ['consumer' => (array) ($configured['identity_consumer'] ?? [])],
            ),
        );
        $this->app['config']->set('identity.consumer', (array) ($configured['identity_consumer'] ?? []));
        $this->app['config']->set('demo', (array) ($configured['demo'] ?? []));
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('identity.reauthenticate', RequireIdentityReauthentication::class);
        $this->app->make(Router::class)->aliasMiddleware('identity.introspect', IdentityIntrospectionMiddleware::class);
        $this->app->make(Router::class)->aliasMiddleware('identity.token', RequireIdentityAccessToken::class);
        $this->app->make(Router::class)->aliasMiddleware('identity.hosted-internal', NitroInternalMiddleware::class);
    }
}
