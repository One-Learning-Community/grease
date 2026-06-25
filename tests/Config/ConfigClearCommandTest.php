<?php

namespace Grease\Tests\Config;

use Grease\Config\GreaseConfigServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * `grease:config-clear` — the clear twin of `grease:config-cache`. A superset of the framework's
 * `config:clear`: it runs that (dropping the config cache) and then unlinks the greased flat index.
 */
class ConfigClearCommandTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseConfigServiceProvider::class];
    }

    protected function tearDown(): void
    {
        @unlink(GreaseConfigServiceProvider::flatIndexPath($this->app));
        @unlink($this->app->getCachedConfigPath());
        parent::tearDown();
    }

    public function test_it_removes_both_the_flat_index_and_the_config_cache(): void
    {
        $this->artisan('grease:config-cache')->assertSuccessful();
        $flat = GreaseConfigServiceProvider::flatIndexPath($this->app);
        $this->assertFileExists($flat);
        $this->assertFileExists($this->app->getCachedConfigPath());

        $this->artisan('grease:config-clear')->assertSuccessful();
        $this->assertFileDoesNotExist($flat);
        $this->assertFileDoesNotExist($this->app->getCachedConfigPath());
    }

    public function test_it_is_registered(): void
    {
        $this->assertArrayHasKey('grease:config-clear', $this->app[Kernel::class]->all());
    }

    public function test_it_is_idempotent_when_no_index_exists(): void
    {
        @unlink(GreaseConfigServiceProvider::flatIndexPath($this->app));

        $this->artisan('grease:config-clear')->assertSuccessful();

        $this->assertFileDoesNotExist(GreaseConfigServiceProvider::flatIndexPath($this->app));
    }
}
