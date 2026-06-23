<?php

/**
 * Grease cumulative-stack pipeline — narrative report.
 *
 * The flagship macro: a REAL full request pipeline — boot a configured Laravel app →
 * route to a controller → query the DB (the four realworld shapes) → serialize the
 * response (JSON *and* Blade-rendered HTML) → 200 — measured with progressively more
 * Grease layered in, in the order of *least-invasive opt-in*:
 *
 *   L0 vanilla · L1 +models · L2 +events · L3 +blade · L4 +container · L5 +request
 *
 * Each level is a strict superset of the one below, so the table reads as "what the next
 * opt-in buys on top of everything safer than it." Every level's response is hashed and
 * must be byte-identical to vanilla (the parity gate); peak + retained memory is captured
 * per level, so speed is weighed against the cache footprint it costs.
 *
 * Shares all fixtures (models, schema, seed, routes, views) with the CI-guarded
 * tests/Pipeline/StackPipelineParityTest and benchmarks/Bench/StackPipelineBench, via
 * {@see PipelineHarness}.
 *
 * Each level runs in its own subprocess (isolation + a clean per-level memory reading).
 *
 *   php benchmarks/stack_pipeline.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Tests\Fixtures\Pipeline\PipelineHarness;
use Grease\Tests\Fixtures\Pipeline\PipelineHarness as H;

// --- Arm: boot at one level, parity-probe + time every route, report memory. ------

if (($argv[1] ?? null) === '--arm') {
    $level = (int) $argv[2];
    $iterations = (int) $argv[3];

    $app = H::bootLevel($level);

    $results = H::parityProbe($app, $level);

    foreach (H::ROUTES as $route) {
        for ($i = 0; $i < 30; $i++) {
            H::handle($app, $level, $route); // warm
        }
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            gc_collect_cycles();
            $t0 = hrtime(true);
            H::handle($app, $level, $route);
            $samples[] = hrtime(true) - $t0;
        }
        sort($samples);
        $results[$route]['us'] = $samples[intdiv(count($samples), 2)] / 1e3; // median
    }

    gc_collect_cycles();

    echo json_encode([
        'level' => $level,
        'routes' => $results,
        // emalloc bytes (fine-grained): peak high-water + retained-after-gc. The retained
        // delta across levels is the honest cache-footprint cost.
        'mem_peak' => memory_get_peak_usage(false),
        'mem_retained' => memory_get_usage(false),
    ]);
    exit(0);
}

// --- Orchestrator. ---------------------------------------------------------------

// `--json[=path]` emits the stack matrix as the live `stack` section the docs render
// (written to <path>, or stdout), suppressing the human table — same measurement.
$cliArgs = array_slice($argv, 1);
$jsonOut = null;
$emitJson = false;
foreach ($cliArgs as $i => $a) {
    if ($a === '--json' || str_starts_with($a, '--json=')) {
        $emitJson = true;
        $jsonOut = str_contains($a, '=') ? substr($a, 7) : null;
        unset($cliArgs[$i]);
    }
}
$cliArgs = array_values($cliArgs);
$say = function (string $line) use ($emitJson): void {
    if (! $emitJson) {
        echo $line;
    }
};

$iterations = (int) ($cliArgs[0] ?? 120);
$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);

$say("Cumulative-stack pipeline — full request through the kernel, per Grease level.\n");
$say("Each level is a superset of the prior. Responses hashed for parity; memory tracked.\n\n");

$levels = [];
foreach (array_keys(H::LEVELS) as $level) {
    $out = shell_exec("$php $self --arm $level $iterations 2>&1");
    $row = json_decode((string) $out, true);
    if (! is_array($row)) {
        fwrite(STDERR, "ARM CRASHED (level $level):\n$out\n");
        exit(1);
    }
    $levels[$level] = $row;
}

// Parity gate: every level's response must byte-match vanilla (L0), per route.
$base = $levels[0]['routes'];
$parityOk = true;
foreach (H::ROUTES as $route) {
    foreach ($levels as $level => $data) {
        $r = $data['routes'][$route];
        if ($r['status'] !== 200) {
            echo "NON-200 — level $level $route: {$r['status']}\n";
            $parityOk = false;
        }
        if ($r['hash'] !== $base[$route]['hash']) {
            echo "PARITY FAIL — level $level $route differs from vanilla\n";
            $parityOk = false;
        }
    }
}
if (! $parityOk) {
    exit(1);
}
$say("Parity: OK — every level's response byte-identical to vanilla, all 200.\n\n");

// --- JSON emit (the live `stack` section the docs render). -----------------------

if ($emitJson) {
    $levelNames = array_values(H::LEVELS);
    $mv = $levels[0]['mem_retained'];

    $routes = [];
    foreach (H::ROUTES as $route) {
        $v = $levels[0]['routes'][$route]['us'];
        $deltas = [];
        foreach (array_keys(H::LEVELS) as $level) {
            $us = $levels[$level]['routes'][$route]['us'];
            $deltas[] = round(($us - $v) / $v * 100, 1);
        }
        $routes[] = ['route' => $route, 'vanilla_us' => round($v, 1), 'deltas' => $deltas];
    }

    $memory = ['retained_mb' => [], 'retained_delta_pct' => [], 'peak_mb' => []];
    foreach (array_keys(H::LEVELS) as $level) {
        $memory['retained_mb'][] = round($levels[$level]['mem_retained'] / 1048576, 2);
        $memory['retained_delta_pct'][] = round(($levels[$level]['mem_retained'] - $mv) / $mv * 100, 2);
        $memory['peak_mb'][] = round($levels[$level]['mem_peak'] / 1048576, 2);
    }

    $payload = ['levels' => $levelNames, 'routes' => $routes, 'memory' => $memory];

    if ($jsonOut !== null) {
        file_put_contents($jsonOut, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        fwrite(STDERR, "wrote $jsonOut (stack: ".count($routes).' routes × '.count($levelNames)." levels)\n");
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    return;
}

// --- Timing table: cumulative delta from vanilla, per route. ---------------------

$col = fn (string $s) => str_pad($s, 11, ' ', STR_PAD_LEFT);
printf('%-24s', 'route');
foreach (H::LEVELS as $name) {
    echo $col($name);
}
echo "\n".str_repeat('-', 24 + 11 * count(H::LEVELS))."\n";

foreach (H::ROUTES as $route) {
    printf('%-24s', $route);
    $v = $levels[0]['routes'][$route]['us'];
    foreach (array_keys(H::LEVELS) as $level) {
        $us = $levels[$level]['routes'][$route]['us'];
        echo $col($level === 0 ? sprintf('%.0fµs', $us) : sprintf('%+.1f%%', ($us - $v) / $v * 100));
    }
    echo "\n";
}

// --- Memory table. ---------------------------------------------------------------

echo "\n";
printf('%-24s', 'memory (emalloc)');
foreach (H::LEVELS as $name) {
    echo $col($name);
}
echo "\n".str_repeat('-', 24 + 11 * count(H::LEVELS))."\n";

printf('%-24s', 'retained MB');
foreach (array_keys(H::LEVELS) as $level) {
    echo $col(sprintf('%.2f', $levels[$level]['mem_retained'] / 1048576));
}
echo "\n";
printf('%-24s', 'retained Δ vs vanilla');
$mv = $levels[0]['mem_retained'];
foreach (array_keys(H::LEVELS) as $level) {
    echo $col(sprintf('%+.2f%%', ($levels[$level]['mem_retained'] - $mv) / $mv * 100));
}
echo "\n";
printf('%-24s', 'peak MB');
foreach (array_keys(H::LEVELS) as $level) {
    echo $col(sprintf('%.2f', $levels[$level]['mem_peak'] / 1048576));
}
echo "\n";

echo "\nmacOS figures distort magnitudes — reproduce on Linux via benchmarks/docker.\n";
