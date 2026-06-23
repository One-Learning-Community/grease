<?php

/**
 * Grease Blade macro — Taylor's 2024 challenge, measured.
 *
 *   "Rendering 1,000 anonymous components takes about 14ms on my MBP. Can we cut
 *    this in half?  @for($i=0;$i<1000;$i++) <x-avatar :name="'Taylor'" .../> @endfor"
 *
 * Renders that exact loop through the stock Blade compiler vs Grease's compiler
 * (faster `@props` resolution), on two avatar shapes so the result is honest about
 * how much `@props` moves the *whole* render, not just the block it optimizes:
 *
 *   - **simple** — initials + one attribute merge. `@props` is a big slice of the work.
 *   - **rich**   — 5 props, a @php block, conditionals, an <img>/initials branch, a
 *                  status dot, slots. `@props` is a small slice; real render dominates.
 *
 * Each arm is a separate booted app with its own compiled-view cache, so the greased
 * arm genuinely recompiles through Grease\View\Compiler. Output is asserted byte-
 * identical between arms before timing. Steady-state (compiled views warm), which is
 * what Taylor measured. Reports ms — his unit.
 *
 *   php benchmarks/blade.php [count] [rounds]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\View\GreaseViewServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;
use Orchestra\Testbench\Foundation\Application;

$VIEWS = __DIR__.'/blade/views';
$count = (int) ($argv[1] ?? 1000);
$rounds = (int) ($argv[2] ?? 30);
$warmup = 5;

// Registers the page-app class components + view composer onto an app.
$register = require __DIR__.'/blade/register.php';

/** Boot an isolated app for one arm, with its own compiled-view cache. */
$boot = function (bool $greased) use ($VIEWS, $register): \Illuminate\Foundation\Application {
    // Clear the framework's process-global component statics so two apps don't bleed.
    Component::flushCache();
    Component::forgetComponentsResolver();
    Component::forgetFactory();
    Facade::clearResolvedInstances();

    $cache = sys_get_temp_dir().'/grease-blade-'.($greased ? 'grease' : 'plain');
    @mkdir($cache, 0777, true);
    array_map('unlink', glob("$cache/*.php") ?: []);

    $app = Application::create(
        basePath: null,
        resolvingCallback: null,
        options: [
            'extra' => ['providers' => $greased ? [GreaseViewServiceProvider::class] : []],
            'enabled_package_discoveries' => false,
        ],
    );

    $app['config']->set('view.compiled', $cache);
    $app['view']->addLocation($VIEWS);
    $register($app);

    return $app;
};

/** Make $app the active app for the framework's static component machinery. */
$activate = function (\Illuminate\Foundation\Application $app): void {
    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    Facade::clearResolvedInstances();
    Component::forgetFactory();
    Component::forgetComponentsResolver();
    Component::flushCache();
};

function percentile(array $xs, float $p): float
{
    sort($xs);
    $n = count($xs);
    if ($n === 1) {
        return $xs[0];
    }
    $rank = $p / 100 * ($n - 1);
    $lo = (int) floor($rank);
    $hi = (int) ceil($rank);

    return $xs[$lo] + ($xs[$hi] - $xs[$lo]) * ($rank - $lo);
}

$variants = [
    'simple avatar (initials + one merge)' => 'page-simple',
    'rich avatar (5 props, @php, conditionals, slots)' => 'page-rich',
    'app page (class components, slots, @include/@each, composer)' => 'page-app',
    'data table (nested @foreach, heavy $loop use)' => 'page-table',
];

echo "Taylor's challenge: render $count anonymous components, vanilla vs greased Blade.\n";
echo str_repeat('-', 72)."\n";

foreach ($variants as $label => $page) {
    $plain = $boot(false);
    $grease = $boot(true);

    // Parity gate: identical HTML, or the numbers are meaningless.
    $activate($plain);
    $htmlPlain = $plain['view']->make($page, ['count' => $count])->render();
    $activate($grease);
    $htmlGrease = $grease['view']->make($page, ['count' => $count])->render();

    if ($htmlPlain !== $htmlGrease) {
        fwrite(STDERR, "PARITY FAIL on $label\n");
        exit(1);
    }

    $t = ['plain' => [], 'grease' => []];
    $arms = ['plain' => $plain, 'grease' => $grease];

    for ($r = 0; $r < $warmup + $rounds; $r++) {
        foreach ($r % 2 ? ['grease', 'plain'] : ['plain', 'grease'] as $arm) {
            $activate($arms[$arm]);
            gc_collect_cycles();
            $t0 = hrtime(true);
            $arms[$arm]['view']->make($page, ['count' => $count])->render();
            $dt = hrtime(true) - $t0;
            if ($r >= $warmup) {
                $t[$arm][] = $dt;
            }
        }
    }

    echo "$label  (parity ✔)\n";
    foreach ([50, 90] as $pct) {
        $p = percentile($t['plain'], $pct) / 1e6;   // ns → ms
        $g = percentile($t['grease'], $pct) / 1e6;
        printf("  p%-2d  vanilla %7.2f ms   grease %7.2f ms   Δ %+6.1f%%\n", $pct, $p, $g, ($g - $p) / $p * 100);
    }
    echo "\n";
}
