<?php

namespace Grease\Tests\Routing;

use Grease\Routing\Router as GreasedRouter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use ReflectionProperty;

/**
 * Greased arm: the `router` singleton is swapped via {@see GreasedRouter::swap()} in
 * `resolveApplication()` — exactly the one-line opt-in a real app makes in `bootstrap/app.php`,
 * before the kernel is resolved. The kernel, route loader, and URL generator must all pick up
 * the greased router, route registration/dispatch must work, and the served response must be
 * byte-identical to {@see BootVanillaTest}.
 */
class BootGreasedTest extends BootParityTestCase
{
    protected function resolveApplication()
    {
        $app = (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(function ($middleware) {
                //
            })
            ->withCommands()
            ->create();

        // The documented opt-in: swap the router before anything resolves it.
        GreasedRouter::swap($app);

        return $app;
    }

    public function test_router_is_greased(): void
    {
        $this->assertInstanceOf(GreasedRouter::class, $this->app['router']);
    }

    public function test_resolve_cache_is_exercised_during_dispatch(): void
    {
        // Driving a real request must flow through the greased router's resolveMiddleware().
        $this->get('/grease-mw')->assertOk();

        $cache = (new ReflectionProperty(GreasedRouter::class, 'greaseResolvedMiddleware'))
            ->getValue($this->app['router']);

        $this->assertNotEmpty($cache, 'dispatch did not populate the greased resolve cache');
    }
}
