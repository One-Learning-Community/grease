<?php

namespace Grease\Routing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router as BaseRouter;

/**
 * Greased router that caches resolved+sorted route middleware. See {@see CachesResolvedMiddleware}.
 *
 * Behaviour-identical to {@see BaseRouter}: the cached middleware list and its
 * order are byte-for-byte what vanilla returns.
 *
 * Like the container and request, the router is wired into the HTTP kernel by constructor
 * injection *before any provider runs* — so a provider rebind of the `router` singleton is too
 * late (the kernel keeps its own reference). Opt in where the binding is first defined, in
 * `bootstrap/app.php`, before the kernel is resolved:
 *
 *     $app = Application::configure(basePath: dirname(__DIR__))
 *         ->withRouting(...)
 *         ->withMiddleware(...)
 *         ->create();
 *
 *     \Grease\Routing\Router::swap($app);
 *
 *     return $app;
 *
 * At that point the router has not been resolved yet (route loading and middleware sync both
 * happen later), so the rebind is clean — no state to carry over.
 */
class Router extends BaseRouter
{
    use CachesResolvedMiddleware;

    /**
     * Rebind the application's `router` singleton to the greased router.
     *
     * Safe to call only before the router is first resolved (i.e. in `bootstrap/app.php`,
     * before `$app->make(Kernel::class)`); the closure below is what the kernel, route
     * loader, and URL generator all resolve.
     */
    public static function swap(Application $app): void
    {
        $app->singleton('router', fn ($app) => new static($app['events'], $app));
    }
}
