<?php

namespace Grease\Tests\View;

use Grease\View\FileViewFinder as GreasedFinder;
use Grease\View\GreaseViewServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * `grease:view-cache` builds an index whose every entry equals what the live finder/compiler
 * resolve (byte-identical by construction), and the provider seeds a greased finder from a fresh
 * index. Covers the command build, the seed round-trip, and the freshness guard.
 */
class GreaseViewCacheTest extends Orchestra
{
    private string $viewDir;

    private string $nsDir;

    private string $compiledDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/grease_vc_'.getmypid().'_'.uniqid();
        $this->viewDir = $base.'/views';
        $this->nsDir = $base.'/pkg';
        $this->compiledDir = $base.'/compiled';

        @mkdir($this->viewDir.'/admin', 0777, true);
        @mkdir($this->nsDir, 0777, true);
        @mkdir($this->compiledDir, 0777, true);
        file_put_contents($this->viewDir.'/welcome.blade.php', 'hi {{ $name ?? "there" }}');
        file_put_contents($this->viewDir.'/admin/panel.blade.php', 'panel');
        file_put_contents($this->nsDir.'/widget.blade.php', 'widget');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->compiledDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @unlink(GreaseViewServiceProvider::indexPath($this->app));
        $this->rmrf(dirname($this->viewDir));

        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rmrf($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    protected function getPackageProviders($app): array
    {
        return [GreaseViewServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [$this->viewDir]);
        $app['config']->set('view.compiled', $this->compiledDir);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('grease:view-cache', $this->app[Kernel::class]->all());
    }

    public function test_command_builds_an_index_matching_live_resolution(): void
    {
        $this->app['view']->addNamespace('gt', $this->nsDir);

        $this->artisan('grease:view-cache')->assertSuccessful();

        $path = GreaseViewServiceProvider::indexPath($this->app);
        $this->assertFileExists($path);

        $index = require $path;
        $finder = $this->app['view']->getFinder();
        $compiler = $this->app['view']->getEngineResolver()->resolve('blade')->getCompiler();

        // Every discovered view is present, dotted + namespaced names derived correctly.
        $this->assertArrayHasKey('welcome', $index['finder']);
        $this->assertArrayHasKey('admin.panel', $index['finder']);
        $this->assertArrayHasKey('gt::widget', $index['finder']);

        // Each entry is byte-identical to what the live finder/compiler return.
        foreach ($index['finder'] as $name => $source) {
            $this->assertSame($finder->find($name), $source, "finder['$name'] mismatch");
            $this->assertSame($compiler->getCompiledPath($source), $index['compiled'][$source]);
        }
    }

    public function test_seeded_finder_resolves_identically_to_vanilla(): void
    {
        $this->artisan('grease:view-cache')->assertSuccessful();

        $index = require GreaseViewServiceProvider::indexPath($this->app);
        $live = $this->app['view']->getFinder();

        $greased = GreasedFinder::fromBase($live);
        $greased->useGreaseViewIndex($index['finder']);

        foreach ($index['finder'] as $name => $source) {
            $this->assertSame($live->find($name), $greased->find($name));
        }
    }

    public function test_index_freshness_guard(): void
    {
        $dir = sys_get_temp_dir().'/grease_vfresh_'.getmypid();
        @mkdir($dir, 0777, true);
        $index = "$dir/grease_views.php";
        $compiled = "$dir/compiled";
        $config = "$dir/config.php";
        @mkdir($compiled, 0777, true);

        // Missing index → not fresh.
        $this->assertFalse(GreaseViewServiceProvider::indexIsFresh($index, $compiled, $config));

        // Index newer than compiled dir + config → fresh.
        file_put_contents($index, '<?php return [];');
        touch($compiled, time() - 10);
        touch($index, time());
        $this->assertTrue(GreaseViewServiceProvider::indexIsFresh($index, $compiled, $config));

        // A later view:cache (compiled dir newer) → stale.
        touch($compiled, time() + 10);
        $this->assertFalse(GreaseViewServiceProvider::indexIsFresh($index, $compiled, $config));

        // Compiled fresh again, but a newer config:cache → stale.
        touch($compiled, time() - 10);
        file_put_contents($config, '<?php return [];');
        touch($config, time() + 10);
        $this->assertFalse(GreaseViewServiceProvider::indexIsFresh($index, $compiled, $config));

        @unlink($index);
        @unlink($config);
        @rmdir($compiled);
        @rmdir($dir);
    }
}
