<?php

namespace Grease\Routing;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;

/**
 * `grease:route-cache` — Laravel's `route:cache`, plus an eager resolved-middleware index.
 *
 * For each route it precomputes the final resolved+sorted middleware list and emits a sibling
 * `grease_routes_mw.php` holding a `signature => [classes]` map, keyed by the exact
 * {@see Router::greaseMiddlewareSignature()} the request path uses. The file is a constant
 * `return [...]`, so opcache interns it into shared memory — every FPM request loads it ~free
 * and the greased router's resolve cache starts PRE-SEEDED, so both the dispatch and terminate
 * middleware passes are hits from request 1 (the win the lazy cache can't reach under FPM, where
 * the Router is rebuilt per request). {@see GreaseRoutingServiceProvider} loads it at boot when
 * it is at least as fresh as the route (and config) cache.
 *
 * Drop-in twin of `route:cache`: use it instead, and `route:clear` / a plain `route:cache`
 * leaves the (now-staler) index unused via the provider's freshness check. Because the index is
 * served only on an exact signature match, a route whose middleware is assigned dynamically at
 * runtime simply misses and resolves live — the one added contract is that the alias/group/
 * priority maps are the same at build and run time (rebuild on deploy).
 */
class RouteMiddlewareCacheCommand extends Command
{
    protected $signature = 'grease:route-cache';

    protected $description = 'Cache routes (route:cache) plus an eager resolved-middleware index for the dispatch path';

    public function handle(): int
    {
        // Build the indexes from a fresh, UNCACHED app so getRoutes() yields the full collection.
        $this->callSilent('route:clear');

        $app = $this->freshApplication();
        ['index' => $index, 'skipped' => $skipped] = $this->buildIndex($app);
        $urlIndex = $this->buildUrlIndex($app);

        // Now write the route cache (its own fresh boot), then the indexes LAST so their mtime is
        // >= the route cache's — which the provider's freshness check relies on.
        $exit = $this->call('route:cache');
        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        file_put_contents(
            GreaseRoutingServiceProvider::indexPath($this->laravel),
            '<?php return '.var_export($index, true).';'.PHP_EOL
        );

        file_put_contents(
            GreaseRoutingServiceProvider::urlIndexPath($this->laravel),
            '<?php return '.var_export($urlIndex, true).';'.PHP_EOL
        );

        if ($skipped > 0) {
            $this->warn("$skipped route signature(s) skipped (closure middleware — no stable key); those resolve live.");
        }

        $this->components->info('Greased route indexes cached ('.count($index).' middleware signatures, '.count($urlIndex).' URL shapes).');

        return self::SUCCESS;
    }

    /**
     * Enumerate every route's resolved+sorted middleware into a signature-keyed index.
     *
     * @return array{index: array<string, array>, skipped: int}
     */
    protected function buildIndex($app): array
    {
        $router = $app['router'];

        $index = [];
        $skipped = 0;

        foreach ($router->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $excluded = $route->excludedMiddleware();

            $key = Router::greaseMiddlewareSignature($middleware, $excluded);

            if ($key === null) {
                $skipped++; // closure in the name list — defers at runtime too

                continue;
            }

            if (isset($index[$key])) {
                continue; // another route shares this signature — already computed
            }

            $resolved = $router->resolveMiddleware($middleware, $excluded);

            if (! self::isSerializable($resolved)) {
                $skipped++; // an alias resolved to a closure — not a constant-file value

                continue;
            }

            $index[$key] = $resolved;
        }

        return ['index' => $index, 'skipped' => $skipped];
    }

    /**
     * Enumerate every named route's static URL shape into a `name => [segments, params]` index,
     * via the same {@see UrlGenerator::greaseCompileEntry()} the lazy path uses — so a seeded
     * entry is byte-identical to one compiled on first `route()`. Routes the fast path cannot
     * cover (domain / optional / scoped / route-defaults) compile to `false` and are omitted;
     * those simply resolve through vanilla at runtime.
     *
     * @return array<string, array{segments: array<int, string>, params: array<int, string>}>
     */
    protected function buildUrlIndex($app): array
    {
        $index = [];

        foreach ($app['router']->getRoutes()->getRoutesByName() as $name => $route) {
            $entry = UrlGenerator::greaseCompileEntry($route);

            if ($entry !== false) {
                $index[$name] = $entry;
            }
        }

        return $index;
    }

    /**
     * Boot a fresh app and force the HTTP kernel to resolve, so the router's middleware
     * alias/group/priority maps are populated ({@see \Illuminate\Foundation\Configuration\
     * ApplicationBuilder::withMiddleware()} registers them via an `afterResolving(HttpKernel)`
     * hook — the console kernel alone does not trigger it).
     *
     * @return Application
     */
    protected function freshApplication()
    {
        return tap(require $this->laravel->bootstrapPath('app.php'), function ($app) {
            $app->make(ConsoleKernelContract::class)->bootstrap();
            $app->make(HttpKernelContract::class);
        });
    }

    /** A resolved list is cacheable only if every entry is a plain string (no closures). */
    protected static function isSerializable(array $resolved): bool
    {
        foreach ($resolved as $middleware) {
            if (! is_string($middleware)) {
                return false;
            }
        }

        return true;
    }
}
