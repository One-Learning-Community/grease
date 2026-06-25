<?php

namespace Grease\Tests;

use Grease\Config\GreaseConfigServiceProvider;
use Grease\Routing\GreaseRoutingServiceProvider;
use Grease\View\GreaseViewServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The grease cache/clear commands hook Laravel's native `optimize` / `optimize:clear` by
 * registering under the framework's own `config` / `views` / `routes` keys — so each grease
 * superset *shadows* the native task in that slot (single pass, no double-caching), and only for
 * the providers an app actually opted into.
 */
class GreaseOptimizeTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            GreaseConfigServiceProvider::class,
            GreaseViewServiceProvider::class,
            GreaseRoutingServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        // These framework statics accumulate across providers/tests — start clean so the
        // assertions see exactly what the grease providers register.
        ServiceProvider::$optimizeCommands = [];
        ServiceProvider::$optimizeClearCommands = [];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        @unlink(GreaseConfigServiceProvider::flatIndexPath($this->app));
        @unlink(GreaseViewServiceProvider::indexPath($this->app));
        @unlink(GreaseRoutingServiceProvider::indexPath($this->app));
        ServiceProvider::$optimizeCommands = [];
        ServiceProvider::$optimizeClearCommands = [];
        parent::tearDown();
    }

    public function test_grease_cache_commands_shadow_the_native_optimize_slots(): void
    {
        $this->assertSame('grease:config-cache', ServiceProvider::$optimizeCommands['config'] ?? null);
        $this->assertSame('grease:view-cache', ServiceProvider::$optimizeCommands['views'] ?? null);
        $this->assertSame('grease:route-cache', ServiceProvider::$optimizeCommands['routes'] ?? null);
    }

    public function test_grease_clear_commands_shadow_the_native_optimize_clear_slots(): void
    {
        $this->assertSame('grease:config-clear', ServiceProvider::$optimizeClearCommands['config'] ?? null);
        $this->assertSame('grease:view-clear', ServiceProvider::$optimizeClearCommands['views'] ?? null);
        $this->assertSame('grease:route-clear', ServiceProvider::$optimizeClearCommands['routes'] ?? null);
    }

    public function test_optimize_clear_fires_the_grease_clear_commands(): void
    {
        // Plant grease sibling artifacts that ONLY the grease clear commands know to remove —
        // a plain optimize:clear would leave them untouched.
        file_put_contents(GreaseConfigServiceProvider::flatIndexPath($this->app), '<?php return [];');
        file_put_contents(GreaseViewServiceProvider::indexPath($this->app), '<?php return [];');
        file_put_contents(GreaseRoutingServiceProvider::indexPath($this->app), '<?php return [];');

        $this->artisan('optimize:clear')->assertSuccessful();

        $this->assertFileDoesNotExist(GreaseConfigServiceProvider::flatIndexPath($this->app));
        $this->assertFileDoesNotExist(GreaseViewServiceProvider::indexPath($this->app));
        $this->assertFileDoesNotExist(GreaseRoutingServiceProvider::indexPath($this->app));
    }
}
