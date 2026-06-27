<?php

namespace Grease\Tests\Routing;

use Grease\Routing\GreaseRoutingServiceProvider;
use Grease\Routing\Router as GreasedRouter;
use Grease\Routing\UrlGenerator as GreasedUrlGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The provider registers `grease:route-cache`, gates the eager index on freshness, and seeds the
 * greased router from a fresh index (round-tripping the exact `var_export` file the command
 * writes). The router itself is swapped via {@see GreasedRouter::swap()} in `resolveApplication`,
 * mirroring the one-line `bootstrap/app.php` opt-in.
 */
class GreaseRoutingServiceProviderTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseRoutingServiceProvider::class];
    }

    protected function resolveApplication()
    {
        $app = (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(function ($middleware) {
                //
            })
            ->withCommands()
            ->create();

        GreasedRouter::swap($app);

        return $app;
    }

    public function test_router_is_greased_and_command_registered(): void
    {
        $this->assertInstanceOf(GreasedRouter::class, $this->app->make('router'));
        $this->assertArrayHasKey('grease:route-cache', $this->app[Kernel::class]->all());
    }

    public function test_fresh_index_round_trips_and_seeds_the_router(): void
    {
        $router = $this->app->make('router');

        $path = GreaseRoutingServiceProvider::indexPath($this->app);
        $routesCache = $this->app->getCachedRoutesPath();
        @mkdir(dirname($path), 0777, true);

        // Stand in for `grease:route-cache`: a route cache plus a newer index, written in the
        // command's exact format so this exercises the real var_export round-trip.
        file_put_contents($routesCache, '<?php return [];'.PHP_EOL);
        touch($routesCache, time() - 10);

        $key = GreasedRouter::greaseMiddlewareSignature(['web', 'auth'], []);
        $entries = [$key => ['App\\Mw\\A', 'App\\Mw\\B']];
        file_put_contents($path, '<?php return '.var_export($entries, true).';'.PHP_EOL);
        touch($path, time());

        $this->assertTrue(GreaseRoutingServiceProvider::indexIsFresh($path, $this->app));

        $router->useGreaseRouteMiddlewareCache(require $path);

        $cache = (new ReflectionProperty(GreasedRouter::class, 'greaseResolvedMiddleware'))->getValue($router);
        $this->assertArrayHasKey($key, $cache);
        $this->assertSame(['App\\Mw\\A', 'App\\Mw\\B'], $cache[$key]);

        @unlink($path);
        @unlink($routesCache);
    }

    public function test_url_generator_is_greased_and_still_signs(): void
    {
        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));

        $url = $this->app->make('url');
        $this->assertInstanceOf(GreasedUrlGenerator::class, $url);

        $this->app['router']->get('/things/{id}', ['as' => 'things.show', fn () => '']);

        // Fast path produces an ordinary absolute URL.
        $this->assertSame('http://localhost/things/5', $url->route('things.show', ['id' => 5]));

        // Signed URLs prove the framework's key resolver survived the singleton rebind — and a
        // signed URL takes vanilla's path (query string), so it must validate end-to-end.
        $signed = $url->signedRoute('things.show', ['id' => 5]);
        $this->assertStringContainsString('signature=', $signed);

        $request = Request::create($signed);
        $this->assertTrue($url->hasValidSignature($request));
    }

    public function test_url_index_round_trips_and_seeds_the_generator(): void
    {
        $url = $this->app->make('url');

        $path = GreaseRoutingServiceProvider::urlIndexPath($this->app);
        $routesCache = $this->app->getCachedRoutesPath();
        @mkdir(dirname($path), 0777, true);

        file_put_contents($routesCache, '<?php return [];'.PHP_EOL);
        touch($routesCache, time() - 10);

        // The command's exact var_export shape: name => [segments, params].
        $entries = ['things.show' => ['segments' => ['things/', ''], 'params' => ['id']]];
        file_put_contents($path, '<?php return '.var_export($entries, true).';'.PHP_EOL);
        touch($path, time());

        $this->assertTrue(GreaseRoutingServiceProvider::indexIsFresh($path, $this->app));

        $url->useGreaseRouteUrlIndex(require $path);

        $index = (new ReflectionProperty(GreasedUrlGenerator::class, 'greaseRouteUrlIndex'))->getValue($url);
        $this->assertArrayHasKey('things.show', $index);
        $this->assertSame(['segments' => ['things/', ''], 'params' => ['id']], $index['things.show']);

        @unlink($path);
        @unlink($routesCache);
    }

    /**
     * The hazard the booted-callback seeding guards against: a cached-routes load re-binds
     * `routes`, firing the framework's `rebinding('routes')` → `UrlGenerator::setRoutes()`, which
     * flushes the URL index. Seeding in `boot()` would be wiped by that later booted-phase load;
     * the provider instead seeds on a `booted` callback (after the route load), so loading the
     * index AFTER a `setRoutes()` flush correctly repopulates it. Regression for a prewarm bug
     * caught in review.
     */
    public function test_url_index_seed_survives_a_routes_rebind(): void
    {
        $url = $this->app->make('url');

        $path = GreaseRoutingServiceProvider::urlIndexPath($this->app);
        $routesCache = $this->app->getCachedRoutesPath();
        @mkdir(dirname($path), 0777, true);
        file_put_contents($routesCache, '<?php return [];'.PHP_EOL);
        touch($routesCache, time() - 10);
        $entries = ['things.show' => ['segments' => ['things/', ''], 'params' => ['id']]];
        file_put_contents($path, '<?php return '.var_export($entries, true).';'.PHP_EOL);
        touch($path, time());

        $indexProp = new ReflectionProperty(GreasedUrlGenerator::class, 'greaseRouteUrlIndex');

        // A boot()-time seed would be wiped here: the compiled-routes load rebinds 'routes'.
        $url->useGreaseRouteUrlIndex($entries);
        $url->setRoutes($this->app['router']->getRoutes());
        $this->assertSame([], $indexProp->getValue($url), 'sanity: a routes rebind flushes the index');

        // The provider seeds on app->booted(), which fires AFTER that load — replay its body.
        (new ReflectionMethod(GreaseRoutingServiceProvider::class, 'loadUrlIndex'))
            ->invoke(new GreaseRoutingServiceProvider($this->app));

        $this->assertArrayHasKey('things.show', $indexProp->getValue($url), 'seed must land after the route load');

        @unlink($path);
        @unlink($routesCache);
    }

    public function test_index_freshness_guard(): void
    {
        $dir = sys_get_temp_dir().'/grease_rt_'.getmypid();
        @mkdir($dir);

        // A fake app exposing only the cached-path methods the guard touches.
        $app = new class($dir) extends Application
        {
            public function __construct(private string $dir)
            {
                //
            }

            public function getCachedRoutesPath(): string
            {
                return $this->dir.'/routes-v7.php';
            }

            public function getCachedConfigPath(): string
            {
                return $this->dir.'/config.php';
            }
        };

        $routes = "$dir/routes-v7.php";
        $index = "$dir/grease_routes_mw.php";

        // Missing index → not fresh.
        file_put_contents($routes, '<?php return [];');
        $this->assertFalse(GreaseRoutingServiceProvider::indexIsFresh($index, $app));

        // Index newer than the route cache → fresh.
        file_put_contents($index, '<?php return [];');
        touch($routes, time() - 10);
        touch($index, time());
        $this->assertTrue(GreaseRoutingServiceProvider::indexIsFresh($index, $app));

        // A later plain route:cache (route cache newer than index) → stale.
        touch($routes, time() + 10);
        $this->assertFalse(GreaseRoutingServiceProvider::indexIsFresh($index, $app));

        // route:clear (route cache gone) → not fresh.
        @unlink($routes);
        $this->assertFalse(GreaseRoutingServiceProvider::indexIsFresh($index, $app));

        // Route cache fresh again, but a newer config:cache → stale (alias resolution reads config).
        file_put_contents($routes, '<?php return [];');
        touch($routes, time() - 10);
        touch($index, time());
        file_put_contents("$dir/config.php", '<?php return [];');
        touch("$dir/config.php", time() + 10);
        $this->assertFalse(GreaseRoutingServiceProvider::indexIsFresh($index, $app));

        @unlink($index);
        @unlink($routes);
        @unlink("$dir/config.php");
        @rmdir($dir);
    }
}
