<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\MappedLaravelEventDispatcher;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            EventDispatcherInterface::class,
            static function (Application $application): EventDispatcherInterface {
                $logger = $application->bound(LoggerInterface::class)
                    ? $application->make(LoggerInterface::class)
                    : null;

                return new MappedLaravelEventDispatcher(
                    laravelDispatcher: $application->make('events'),
                    eventMap: $application->make('event.map'),
                    logger: $logger,
                );
            }
        );
    }

    public function boot(): void
    {
        class_exists(User::class);
        Route::pattern('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

        RateLimiter::for('identity-hosted-login', static function (Request $request): array {
            return [
                Limit::perMinute(20)->by(self::hostedThrottleKey($request, 'login:email', (string) $request->input('email'))),
                Limit::perMinute(120)->by(self::hostedThrottleKey($request, 'login:application', $request->ip() ?? 'unknown')),
            ];
        });

        RateLimiter::for('identity-hosted-register', static function (Request $request): array {
            return [
                Limit::perMinute(10)->by(self::hostedThrottleKey($request, 'register:email', (string) $request->input('email'))),
                Limit::perMinute(40)->by(self::hostedThrottleKey($request, 'register:application', $request->ip() ?? 'unknown')),
            ];
        });

        RateLimiter::for('identity-hosted-password-recovery', static function (Request $request): Limit {
            return Limit::perMinute(5)->by(self::hostedThrottleKey($request, 'password-recovery:application', $request->ip() ?? 'unknown'));
        });

        RateLimiter::for('identity-sandbox-session', static function (Request $request): Limit {
            return Limit::perMinute(20)->by('identity-sandbox-session:'.hash('sha256', $request->ip() ?? 'unknown'));
        });

        RateLimiter::for('identity-authorization-intent', static function (Request $request): array {
            return self::identityIntentLimits($request, 'authorization');
        });

        RateLimiter::for('identity-logout-intent', static function (Request $request): array {
            return self::identityIntentLimits($request, 'logout');
        });

        Gate::before(static function (User $user): ?bool {
            return $user->is_system_admin ? true : null;
        });
    }

    private static function hostedThrottleKey(Request $request, string $scope, string $subject): string
    {
        $application = Str::lower(trim((string) $request->route('application')));
        $normalizedSubject = Str::lower(trim($subject));

        return 'identity-hosted:'.$scope.':'.hash('sha256', $application.'|'.$normalizedSubject);
    }

    /** @return list<Limit> */
    private static function identityIntentLimits(Request $request, string $scope): array
    {
        $client = Str::lower(trim((string) $request->input('client_id')));
        $application = Str::lower(trim((string) $request->input('hosted_application')));
        $ipAddress = $request->ip() ?? 'unknown';

        return [
            Limit::perMinute(30)->by('identity-intent:'.$scope.':client:'.hash('sha256', $client.'|'.$application)),
            Limit::perMinute(120)->by('identity-intent:'.$scope.':ip:'.hash('sha256', $ipAddress)),
        ];
    }
}
