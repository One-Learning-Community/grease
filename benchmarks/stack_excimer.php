<?php

/**
 * Excimer profiler over the *cumulative-stack* pipeline — a REAL request through the HTTP
 * kernel with every tier the flagship layers in place, not a single-tier proxy. This is the
 * profiler companion the stack benchmark never had: `stack_pipeline.php` times the levels and
 * `StackPipelineParityTest` gates byte-identity, but neither shows *where the remaining time
 * goes* once the baseline is greased. After six tiers shrink the request, the top self-time
 * frames are a different list than they are on vanilla — this prints that list.
 *
 * Boots the fully-greased {@see PipelineHarness} at level 6 (the same fixtures/schema/seed/
 * routes/views the stack benchmark and the parity test share), warms each route, then samples
 * a tight loop of `$kernel->handle()` per route. Prints top frames by self + inclusive time
 * and writes a speedscope flamegraph per route (open at https://speedscope.app).
 *
 * Tiers active at level 6 (see PipelineHarness::LEVELS / LevelResolver): greased **models**,
 * **events** dispatcher, **Blade** view tier, **container**, **request** class, **URL**
 * generator. NOT wired into this harness (they under-represent on these fixtures, by design —
 * see the foundation-axis notes): the config Repository, the router middleware cache, the
 * query-grammar wrap memo, grease:view-cache, and the validation parse memo. So the flamegraph
 * is the model+request-lifecycle stack on the realworld shapes — the bulk of a real request.
 *
 * Run with opcache.jit=off: under tracing JIT, inlined callers misattribute self-time to tiny
 * leaves (the enum_value phantom — see NOTES #11). jit=off self-times are truthful; call counts
 * and inclusive time are reliable either way. opcache itself on is fine and realistic.
 *
 *   php -d xdebug.mode=off -d opcache.enable_cli=1 -d opcache.jit=off -d memory_limit=1G \
 *       benchmarks/stack_excimer.php [route|all] [secs] [--level=N] [--no-speedscope]
 *
 * route: one of PipelineHarness::ROUTES (e.g. api_resource.json), or "all" (default).
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Tests\Fixtures\Pipeline\PipelineHarness as H;
use Illuminate\Support\Carbon;

if (! extension_loaded('excimer')) {
    fwrite(STDERR, "excimer not loaded. Install it (pecl install excimer) and retry.\n");
    exit(1);
}

// --- Args: positional [route] [secs], plus --level=N / --no-speedscope flags. ----------

$level = 6;
$writeSpeedscope = true;
$positional = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--level=')) {
        $level = (int) substr($a, 8);
    } elseif ($a === '--no-speedscope') {
        $writeSpeedscope = false;
    } else {
        $positional[] = $a;
    }
}
$only = $positional[0] ?? 'all';
$seconds = (float) ($positional[1] ?? 3.0);

$routes = $only === 'all' ? H::ROUTES : [$only];
foreach ($routes as $route) {
    if (! in_array($route, H::ROUTES, true)) {
        fwrite(STDERR, "unknown route '$route'. One of: ".implode(', ', H::ROUTES)." (or 'all').\n");
        exit(1);
    }
}

// --- Boot the fully-greased stack once; profile each route against it. ------------------

$app = H::bootLevel($level);
$db = $app['db']->connection();
Carbon::setTestNow('2026-01-01 12:00:00');

$speedDir = sys_get_temp_dir().'/grease_stack_excimer';
if ($writeSpeedscope && ! is_dir($speedDir)) {
    @mkdir($speedDir, 0777, true);
}

echo "Cumulative-stack Excimer — level $level (".(H::LEVELS[$level] ?? '?')."), full kernel request.\n";
// Excimer overrides zend_execute_ex, which forces JIT off whenever it's loaded — so self-time
// is truthful here regardless of the opcache.jit setting. Report the *active* state ('on'),
// not the configured one ('enabled'), so the banner doesn't cry wolf.
echo 'jit: '.(function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false) ? 'ON (self-times suspect — see docblock)' : 'off (truthful self-time — excimer forces it off)')."\n";

foreach ($routes as $route) {
    $write = in_array($route, H::WRITE_ROUTES, true);

    // A write route mutates the seed; wrap each dispatch in a rolled-back transaction so the
    // workload shape is identical every iteration (mirrors PipelineHarness::parityProbe).
    $run = function () use ($app, $level, $route, $write, $db) {
        if ($write) {
            $db->beginTransaction();
        }
        try {
            H::handle($app, $level, $route);
        } finally {
            if ($write) {
                $db->rollBack();
            }
        }
    };

    for ($i = 0; $i < 30; $i++) {
        $run(); // warm: compile views, fill every per-class/-instance cache
    }

    $profiler = new ExcimerProfiler;
    $profiler->setPeriod(0.0001);
    $profiler->setEventType(EXCIMER_REAL);

    $deadline = hrtime(true) + (int) ($seconds * 1e9);
    $runs = 0;
    $profiler->start();
    while (hrtime(true) < $deadline) {
        $run();
        $runs++;
    }
    $profiler->stop();

    $log = $profiler->getLog();

    echo "\n".str_repeat('=', 78)."\n";
    echo "$route — {$runs}× through the kernel\n";
    echo 'samples: '.count($log);
    if ($writeSpeedscope) {
        $path = $speedDir."/stack-L$level-$route.speedscope.json";
        file_put_contents($path, json_encode($log->getSpeedscopeData()));
        echo "   speedscope: $path";
    }
    echo "\n".str_repeat('-', 78)."\n";

    $agg = $log->aggregateByFunction();
    uasort($agg, static fn ($a, $b) => $b['self'] - $a['self']);
    $total = array_sum(array_map(static fn ($e) => $e['self'], $agg)) ?: 1;

    printf("%-7s %-7s  %s\n", 'self%', 'incl%', 'function');
    $i = 0;
    foreach ($agg as $fn => $e) {
        if ($i++ >= 25) {
            break;
        }
        printf("%6.2f%% %6.2f%%  %s\n", $e['self'] / $total * 100, $e['inclusive'] / $total * 100, $fn);
    }
}

Carbon::setTestNow();
