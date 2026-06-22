<?php

/**
 * Profiling harness for the Blade render path — boots the greased arm and renders
 * Taylor's loop once under a profiler, so we can see where the per-component render cost
 * actually goes before optimizing it (the measure-first companion to benchmarks/blade.php,
 * which only times the A/B). Single-arm and not parity-gated by design: it exists to
 * point at the next hot spot, not to prove anything identical.
 *
 *   php -d xdebug.mode=profile -d xdebug.start_with_request=yes \
 *       -d xdebug.output_dir=/tmp/grease-prof -d xdebug.use_compression=0 \
 *       benchmarks/blade_profile.php [count] [page-simple|page-rich]
 *
 * Summarize the resulting cachegrind by self-time (one-time boot/autoload entries float
 * to the top at low counts — raise [count] to amortize them and rank the steady state):
 *
 *   php benchmarks/cachegrind_top.php /tmp/grease-prof/cachegrind.out.*
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\View\GreaseViewServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;
use Orchestra\Testbench\Foundation\Application;

$VIEWS = __DIR__.'/blade/views';
$count = (int) ($argv[1] ?? 1000);
$page = $argv[2] ?? 'page-rich';

Component::flushCache();
Component::forgetComponentsResolver();
Component::forgetFactory();
Facade::clearResolvedInstances();

$cache = sys_get_temp_dir().'/grease-blade-prof';
@mkdir($cache, 0777, true);
array_map('unlink', glob("$cache/*.php") ?: []);

$app = Application::create(
    basePath: null,
    resolvingCallback: null,
    options: [
        'extra' => ['providers' => [GreaseViewServiceProvider::class]],
        'enabled_package_discoveries' => false,
    ],
);
$app['config']->set('view.compiled', $cache);
$app['view']->addLocation($VIEWS);

Container::setInstance($app);
Facade::setFacadeApplication($app);

// Warm the compiled cache (compile once, outside the measured loop).
$app['view']->make($page, ['count' => 1])->render();

// The profiled render.
$app['view']->make($page, ['count' => $count])->render();

echo "rendered $count × $page\n";
