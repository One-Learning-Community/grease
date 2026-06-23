<?php

namespace Grease\Routing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Opt into the eager route-middleware index. The greased router itself is installed in
 * `bootstrap/app.php` via {@see Router::swap()} (the kernel takes the router by constructor
 * injection, so a provider rebind would be too late) — this provider is the second half: it
 * loads a fresh `grease:route-cache` artifact so the resolve cache starts pre-seeded.
 *
 *   // bootstrap/providers.php
 *   Grease\Routing\GreaseRoutingServiceProvider::class,
 *
 * Without the index (or without the swap) it is inert — the lazy {@see CachesResolvedMiddleware}
 * cache still applies. "Fresh" means the index is at least as new as the route cache (and the
 * config cache, if present, since alias resolution can read config), so a plain `route:cache` /
 * `route:clear` / `config:cache` after the fact transparently disables the now-stale index.
 */
class GreaseRoutingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RouteMiddlewareCacheCommand::class]);
        }

        $this->loadMiddlewareIndex();
    }

    /**
     * Seed the greased router's resolve cache from the eager index, when one is present and
     * fresh. A no-op unless the router was swapped for the greased class.
     */
    protected function loadMiddlewareIndex(): void
    {
        $router = $this->app->make('router');

        if (! $router instanceof Router) {
            return; // not swapped in bootstrap/app.php — the eager index needs the greased router
        }

        if (! method_exists($this->app, 'getCachedRoutesPath')) {
            return; // non-standard container without a route cache path
        }

        $path = self::indexPath($this->app);

        if (self::indexIsFresh($path, $this->app)) {
            $router->useGreaseRouteMiddlewareCache(require $path);
        }
    }

    /**
     * The index is usable only when it exists and is at least as new as the route cache it was
     * built from — and, if config is cached, at least as new as that too (alias resolution such
     * as `throttle`→redis can depend on config). `grease:route-cache` writes it last, so it
     * passes; a later `route:cache`/`route:clear`/`config:cache` makes it fail.
     */
    public static function indexIsFresh(string $path, Application $app): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $routesPath = $app->getCachedRoutesPath();

        if (! is_file($routesPath) || filemtime($path) < filemtime($routesPath)) {
            return false;
        }

        if (method_exists($app, 'getCachedConfigPath')) {
            $configPath = $app->getCachedConfigPath();

            if (is_file($configPath) && filemtime($path) < filemtime($configPath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The index file path — a sibling of the framework's route cache, so it lives and clears
     * alongside it. Shared by {@see RouteMiddlewareCacheCommand} (writer) and the loader above.
     */
    public static function indexPath(Application $app): string
    {
        return dirname($app->getCachedRoutesPath()).'/grease_routes_mw.php';
    }
}
