<?php

/**
 * Honest profiling harness for the Blade render path — uses Excimer, a *sampling*
 * profiler, instead of Xdebug. The difference matters: Xdebug overrides zend_execute_ex
 * (disabling JIT) and over-attributes internal-op cost to the calling PHP frame — which is
 * how its cachegrind told us `extract()` was ~14% of a render when a micro-A/B proved it
 * was ~0.6%. Excimer interrupts on a wall-clock timer and reads the stack, so JIT stays on
 * and C builtins aren't penalized; self-time percentages are trustworthy here.
 *
 * Boots the greased arm (the shipped @props + merge compiler tier), warms the compiled
 * views, then samples a tight loop of full renders to accumulate enough samples. Writes a
 * speedscope flamegraph (open at https://speedscope.app) and prints the top frames by self
 * time. Run with JIT on / Xdebug off, the way the app really executes:
 *
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M \
 *       -d opcache.jit=tracing benchmarks/blade_excimer.php [count] [seconds] [page]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\View\GreaseViewServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;
use Orchestra\Testbench\Foundation\Application;

if (! extension_loaded('excimer')) {
    fwrite(STDERR, "excimer not loaded — run without -n, or install it (pecl install excimer).\n");
    exit(1);
}

$VIEWS = __DIR__.'/blade/views';
$count = (int) ($argv[1] ?? 1000);
$seconds = (float) ($argv[2] ?? 8.0);
$page = $argv[3] ?? 'page-simple';
$period = 0.0001;   // 0.1 ms sampling period

Component::flushCache();
Component::forgetComponentsResolver();
Component::forgetFactory();
Facade::clearResolvedInstances();

$cache = sys_get_temp_dir().'/grease-blade-excimer';
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

// Warm: compile + first render outside the sampled window.
$app['view']->make($page, ['count' => $count])->render();

$profiler = new ExcimerProfiler;
$profiler->setPeriod($period);
$profiler->setEventType(EXCIMER_REAL);

$deadline = hrtime(true) + (int) ($seconds * 1e9);
$renders = 0;

$profiler->start();
while (hrtime(true) < $deadline) {
    $app['view']->make($page, ['count' => $count])->render();
    $renders++;
}
$profiler->stop();

$log = $profiler->getLog();
$samples = count($log);

// Speedscope flamegraph for the browser.
$speedscope = $cache.'/grease-'.$page.'.speedscope.json';
file_put_contents($speedscope, json_encode($log->getSpeedscopeData()));

echo "Excimer (sampling, JIT on): $renders renders × $count components of $page\n";
echo "samples: $samples @ {$period}s   speedscope: $speedscope\n";
echo str_repeat('-', 72)."\n";

// Top frames by SELF time (the trustworthy ranking).
$agg = $log->aggregateByFunction();
uasort($agg, static fn ($a, $b) => $b['self'] - $a['self']);

$total = array_sum(array_map(static fn ($e) => $e['self'], $agg)) ?: 1;
printf("%-7s %-7s  %s\n", 'self%', 'incl%', 'function');
$i = 0;
foreach ($agg as $fn => $e) {
    if ($i++ >= 30) {
        break;
    }
    printf("%6.2f%% %6.2f%%  %s\n", $e['self'] / $total * 100, $e['inclusive'] / $total * 100, $fn);
}
