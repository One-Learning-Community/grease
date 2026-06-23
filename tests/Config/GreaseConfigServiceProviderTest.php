<?php

namespace Grease\Tests\Config;

use Grease\Config\GreaseConfigServiceProvider;
use Grease\Config\Repository as GreasedRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The provider swaps `config` to the greased repository (preserving values + redirecting the
 * facade) and registers `grease:config-cache`; the flat-index freshness guard gates loading.
 */
class GreaseConfigServiceProviderTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseConfigServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Set on the vanilla repo, BEFORE the provider swaps — must survive the fromBase() copy.
        $app['config']->set('grease.sentinel', 'carried-over');
    }

    public function test_config_is_swapped_and_values_preserved(): void
    {
        $this->assertInstanceOf(GreasedRepository::class, $this->app->make('config'));
        $this->assertInstanceOf(GreasedRepository::class, Config::getFacadeRoot());

        // Pre-swap value carried over verbatim, and reads work through the greased path.
        $this->assertSame('carried-over', config('grease.sentinel'));
        $this->assertSame(config('app.name'), $this->app['config']->get('app.name'));
    }

    public function test_grease_config_cache_command_is_registered(): void
    {
        $this->assertArrayHasKey('grease:config-cache', $this->app[Kernel::class]->all());
    }

    public function test_flat_index_freshness_guard(): void
    {
        $dir = sys_get_temp_dir().'/grease_fresh_'.getmypid();
        @mkdir($dir);
        $config = "$dir/config.php";
        $flat = "$dir/grease_config_flat.php";

        // Missing flat → not fresh.
        file_put_contents($config, '<?php return [];');
        $this->assertFalse(GreaseConfigServiceProvider::flatIndexIsFresh($flat, $config));

        // Flat newer than config → fresh.
        file_put_contents($flat, '<?php return [];');
        touch($config, time() - 10);
        touch($flat, time());
        $this->assertTrue(GreaseConfigServiceProvider::flatIndexIsFresh($flat, $config));

        // A later plain config:cache (config.php newer than flat) → stale.
        touch($config, time() + 10);
        $this->assertFalse(GreaseConfigServiceProvider::flatIndexIsFresh($flat, $config));

        // config:clear (config.php gone) → not fresh.
        @unlink($config);
        $this->assertFalse(GreaseConfigServiceProvider::flatIndexIsFresh($flat, $config));

        @unlink($flat);
        @rmdir($dir);
    }
}
