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

// `--json[=path]` → machine-readable payload for the docs (same measurement, no table).
$args = array_slice($argv, 1);
$jsonOut = null;
$emitJson = false;
foreach ($args as $i => $a) {
    if ($a === '--json' || str_starts_with($a, '--json=')) {
        $emitJson = true;
        $jsonOut = str_contains($a, '=') ? substr($a, 7) : null;
        unset($args[$i]);
    }
}
$args = array_values($args);
$say = function (string $line) use ($emitJson): void {
    if (! $emitJson) {
        echo $line;
    }
};

$VIEWS = __DIR__.'/blade/views';
$count = (int) ($args[0] ?? 1000);
$rounds = (int) ($args[1] ?? 30);
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
    'simple avatar, @foreach (realistic loop)' => 'page-foreach',
    'rich avatar (5 props, @php, conditionals, slots)' => 'page-rich',
    'rich avatar, @foreach (realistic loop)' => 'page-rich-foreach',
    'app page (class components, slots, @include/@each, composer)' => 'page-app',
    'data table (nested @foreach, heavy $loop use)' => 'page-table',
    'layout inheritance (@extends/@section/@yield/@push)' => 'page-layout',
    'asset stacks (@push/@prepend per row, @stack)' => 'page-stacks',
    'full page (extends layout, 5 sections, 100-row @foreach table, components)' => 'page-full',
];

$say("Taylor's challenge: render $count anonymous components, vanilla vs greased Blade.\n");
$say(str_repeat('-', 72)."\n");

$variantsOut = [];
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

    $percentiles = [];
    foreach ([50, 90] as $pct) {
        $p = percentile($t['plain'], $pct) / 1e6;   // ns → ms
        $g = percentile($t['grease'], $pct) / 1e6;
        $percentiles[$pct] = [
            'vanilla_ms' => round($p, 2),
            'grease_ms' => round($g, 2),
            'delta_pct' => round(($g - $p) / $p * 100, 1),
        ];
    }

    $variantsOut[] = [
        'key' => $page,
        'label' => $label,
        'vanilla_ms' => $percentiles[50]['vanilla_ms'],
        'grease_ms' => $percentiles[50]['grease_ms'],
        'delta_pct' => $percentiles[50]['delta_pct'],
        'percentiles' => $percentiles,
    ];

    $say("$label  (parity ✔)\n");
    foreach ($percentiles as $pct => $row) {
        $say(sprintf(
            "  p%-2d  vanilla %7.2f ms   grease %7.2f ms   Δ %+6.1f%%\n",
            $pct, $row['vanilla_ms'], $row['grease_ms'], $row['delta_pct'],
        ));
    }
    $say("\n");
}

if ($emitJson) {
    $payload = [
        'count' => $count,
        'rounds' => $rounds,
        'parity' => 'pass',
        'source' => 'benchmarks/blade.php',
        'blade' => $variantsOut,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

    if ($jsonOut !== null) {
        if (! is_dir($dir = dirname($jsonOut))) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($jsonOut, $json);
        fwrite(STDERR, "wrote $jsonOut (".count($variantsOut)." variants)\n");
    } else {
        echo $json;
    }
}
