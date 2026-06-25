<?php

namespace Grease\Tests\Routing;

use Grease\Routing\GreaseRoutingServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * `grease:route-clear` — the clear twin of `grease:route-cache`. A superset of `route:clear`: it
 * runs that (dropping the route cache) and then unlinks the greased route-middleware index.
 */
class RouteMiddlewareClearCommandTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseRoutingServiceProvider::class];
    }

    protected function tearDown(): void
    {
        @unlink(GreaseRoutingServiceProvider::indexPath($this->app));
        @unlink($this->app->getCachedRoutesPath());
        parent::tearDown();
    }

    public function test_it_removes_both_the_middleware_index_and_the_route_cache(): void
    {
        $routesCache = $this->app->getCachedRoutesPath();
        file_put_contents($routesCache, '<?php return [];');

        $index = GreaseRoutingServiceProvider::indexPath($this->app);
        file_put_contents($index, '<?php return [];');

        $this->artisan('grease:route-clear')->assertSuccessful();

        $this->assertFileDoesNotExist($index);
        $this->assertFileDoesNotExist($routesCache);
    }

    public function test_it_is_registered(): void
    {
        $this->assertArrayHasKey('grease:route-clear', $this->app[Kernel::class]->all());
    }

    public function test_it_is_idempotent_when_no_index_exists(): void
    {
        @unlink(GreaseRoutingServiceProvider::indexPath($this->app));

        $this->artisan('grease:route-clear')->assertSuccessful();

        $this->assertFileDoesNotExist(GreaseRoutingServiceProvider::indexPath($this->app));
    }
}
