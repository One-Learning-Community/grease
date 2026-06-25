<?php

namespace Grease\Tests\View;

use Grease\View\GreaseViewServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * `grease:view-clear` — the clear twin of `grease:view-cache`. A superset of `view:clear`: it
 * runs that (dropping the compiled views) and then unlinks the greased view index.
 */
class ViewClearCommandTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseViewServiceProvider::class];
    }

    protected function tearDown(): void
    {
        @unlink(GreaseViewServiceProvider::indexPath($this->app));
        parent::tearDown();
    }

    public function test_it_removes_both_the_view_index_and_the_compiled_views(): void
    {
        $index = GreaseViewServiceProvider::indexPath($this->app);
        file_put_contents($index, '<?php return [];');

        $compiled = $this->app['config']['view.compiled'];
        @mkdir($compiled, 0777, true);
        $stale = $compiled.'/deadbeef.php';
        file_put_contents($stale, '<?php /* compiled view */');

        $this->artisan('grease:view-clear')->assertSuccessful();

        $this->assertFileDoesNotExist($index);
        $this->assertFileDoesNotExist($stale);
    }

    public function test_it_is_registered(): void
    {
        $this->assertArrayHasKey('grease:view-clear', $this->app[Kernel::class]->all());
    }

    public function test_it_is_idempotent_when_no_index_exists(): void
    {
        @unlink(GreaseViewServiceProvider::indexPath($this->app));

        $this->artisan('grease:view-clear')->assertSuccessful();

        $this->assertFileDoesNotExist(GreaseViewServiceProvider::indexPath($this->app));
    }
}
