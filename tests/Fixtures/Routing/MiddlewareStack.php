<?php

namespace Grease\Tests\Fixtures\Routing;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The realistic Laravel 12/13 default middleware stack — aliases, the `web`/`api` groups,
 * and the priority map — plus the route signatures a real app resolves. Shared by
 * `benchmarks/middleware_ab.php` and `RouterMiddlewareParityTest` so the bench times exactly
 * what the tests prove byte-identical (the repo's bench/test-share-fixtures convention).
 */
final class MiddlewareStack
{
    /** @return array<string, class-string> */
    public static function aliases(): array
    {
        return [
            'auth' => Authenticate::class,
            'auth.basic' => AuthenticateWithBasicAuth::class,
            'auth.session' => AuthenticateSession::class,
            'cache.headers' => SetCacheHeaders::class,
            'can' => Authorize::class,
            'guest' => RedirectIfAuthenticated::class,
            'password.confirm' => RequirePassword::class,
            'signed' => ValidateSignature::class,
            'throttle' => ThrottleRequests::class,
            'verified' => EnsureEmailIsVerified::class,
        ];
    }

    /** @return array<string, array<int, class-string|string>> */
    public static function groups(): array
    {
        return [
            'web' => [
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
            ],
            'api' => [
                'throttle:api',
                SubstituteBindings::class,
            ],
        ];
    }

    /** @return array<int, class-string> */
    public static function priority(): array
    {
        return [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ];
    }

    /**
     * Realistic (gathered, excluded) middleware-name pairs — what
     * `Route::gatherMiddleware()`/`excludedMiddleware()` hand `resolveMiddleware()`.
     *
     * @return array<string, array{0: array, 1: array}>
     */
    public static function shapes(): array
    {
        return [
            'web authed' => [['web', 'auth', 'verified', 'throttle:60,1', 'can:update,post'], []],
            'web guest' => [['web', 'guest'], []],
            'api' => [['api', 'auth:sanctum'], []],
            'web + exclude' => [['web', 'auth'], [StartSession::class]],
            'bare' => [['throttle:60,1'], []],
            'duplicate names' => [['web', 'web', 'auth', 'auth'], []],
        ];
    }

    /** Register the aliases, groups, and priority onto a router (vanilla or greased). */
    public static function installInto(Router $router): Router
    {
        foreach (self::aliases() as $name => $class) {
            $router->aliasMiddleware($name, $class);
        }

        foreach (self::groups() as $name => $middleware) {
            $router->middlewareGroup($name, $middleware);
        }

        $router->middlewarePriority = self::priority();

        return $router;
    }
}
