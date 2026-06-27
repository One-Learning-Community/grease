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
    /**
     * Swap the `url` singleton for the greased {@see UrlGenerator}. Unlike the router (taken by
     * the kernel via constructor injection, so it must be swapped in `bootstrap/app.php`), the
     * URL generator is resolved lazily — a provider rebind is in time. The framework's
     * `extend('url')` (session/key resolvers + the `routes` rebinding callback) survives a
     * re-bind and still decorates this instance, so signed URLs and route-cache rebinding work
     * unchanged. The closure mirrors the framework's `registerUrlGenerator()` verbatim except
     * for the class instantiated.
     */
    public function register(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new UrlGenerator(
                $routes, $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }), $app['config']['app.asset_url']
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RouteMiddlewareCacheCommand::class, RouteMiddlewareClearCommand::class]);

            // Shadow the native `routes` slot in `optimize` / `optimize:clear` with the greased
            // supersets, so a standard deploy picks them up for opted-in apps — no double-cache.
            $this->optimizes(optimize: 'grease:route-cache', clear: 'grease:route-clear', key: 'routes');
        }

        $this->loadMiddlewareIndex();

        // Seed the URL index on a `booted` callback, NOT here. A cached-routes load
        // (`RouteServiceProvider`'s own booted callback) re-binds `routes`, firing the
        // framework's `rebinding('routes')` → `UrlGenerator::setRoutes()`, which flushes the
        // index. That runs in the booted phase, strictly after every provider's `boot()` — so a
        // seed here would be wiped. Registered now (after `RouteServiceProvider::boot()` queued
        // its callback), this fires after the route load, so the seed survives into requests.
        // The middleware index has no such hazard: `setCompiledRoutes()` swaps the route
        // collection without touching the router's resolved-middleware cache.
        $this->app->booted(fn () => $this->loadUrlIndex());
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
     * Seed the greased URL generator's per-name shape index from the eager artifact, when one is
     * present and fresh. A no-op unless `url` was swapped for the greased class (always the case
     * once this provider is registered). Without the index the tier still works — it just
     * compiles each route's shape lazily on first `route()` instead of at deploy time.
     */
    protected function loadUrlIndex(): void
    {
        if (! method_exists($this->app, 'getCachedRoutesPath')) {
            return;
        }

        $url = $this->app->make('url');

        if (! $url instanceof UrlGenerator) {
            return;
        }

        $path = self::urlIndexPath($this->app);

        if (self::indexIsFresh($path, $this->app)) {
            $url->useGreaseRouteUrlIndex(require $path);
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

    /**
     * The URL shape-index path — a sibling of the route cache, alongside the middleware index, so
     * the same freshness check ({@see indexIsFresh()}) governs both. Shared by
     * {@see RouteMiddlewareCacheCommand} (writer) and {@see loadUrlIndex()}.
     */
    public static function urlIndexPath(Application $app): string
    {
        return dirname($app->getCachedRoutesPath()).'/grease_routes_url.php';
    }
}
