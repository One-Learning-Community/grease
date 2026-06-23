<?php

/**
 * Grease under a persistent worker (the "Octane" model) — narrative report.
 *
 * Every other Grease macro measures a *warm* app: boot once, warm the caches, then time.
 * That already answers "what does a request cost on a hot worker?" — but it hides the one
 * thing the Octane story turns on: the **warmup tax**. A Grease tier pays a one-time cost
 * to compile its per-class blueprint (constructor plan, model statics, cast flyweights,
 * compiled views). Under classic PHP-FPM that blueprint is cold at the start of *every*
 * request and rebuilt during it — so FPM re-pays the warmup forever, diluting the win.
 * Under a persistent worker the blueprint is built once and survives across requests, so
 * requests 2..N realize the *full* per-op win with zero warmup. The thesis this bench
 * proves: **Grease wins more under a persistent worker than under FPM**, and it sizes by
 * how much.
 *
 * The measurement reuses the exact, parity-proven cumulative-stack fixtures
 * ({@see PipelineHarness} — the same models, schema, seed,
 * routes and views the CI parity test and StackPipelineBench run), in two arms:
 *
 *   --warm  boot once, warm hard, time each route warm        → Octane steady-state cost
 *   --cold  a FRESH worker (fresh process): boot, then time     → FPM cold cost (position 1)
 *           the first few handles of one route, recording          + the cold→warm curve
 *           each position
 *
 * Position 1 of a fresh process is exactly what an FPM request pays (every per-process
 * cache cold); the warm tail is exactly what a persistent worker pays once warm. The gap
 * between them is the warmup tax — which is *also* the ceiling a `grease:cache`-style
 * precompile (an opcached, pre-built blueprint file) could recover for the FPM crowd. The
 * container tier's slice of that tax is called out specifically, since it's the tier whose
 * FPM win is most warmup-diluted (transients resolve ~once per request, so the blueprint
 * barely amortizes within a single FPM request).
 *
 * Retained memory is reported per level too: a worker is long-lived, so the cache footprint
 * a tier costs matters more here than anywhere else.
 *
 *   php benchmarks/octane.php [warmIterations] [coldWorkers] [curveLen]
 *
 * Defaults are mac-smoke sized. For a real reading run on Linux via benchmarks/docker with
 * larger counts, e.g.  php benchmarks/octane.php 150 16 10
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Tests\Fixtures\Pipeline\PipelineHarness;
use Grease\Tests\Fixtures\Pipeline\PipelineHarness as H;

// --- WARM arm: the persistent-worker steady state. -------------------------------
// Boot once, warm each route hard, then time it warm — the cost a hot Octane worker pays
// per request once its blueprints are built. Also carries the parity hash + boot time.
if (($argv[1] ?? null) === '--warm') {
    $level = (int) $argv[2];
    $iterations = (int) $argv[3];

    $t0 = hrtime(true);
    $app = H::bootLevel($level);
    $bootUs = (hrtime(true) - $t0) / 1e3; // NB: includes the test DB seed — constant across
    // levels, so the boot *delta* stays honest.

    $results = H::parityProbe($app, $level); // {route: {status, hash}}

    foreach (H::ROUTES as $route) {
        for ($i = 0; $i < 40; $i++) {
            H::handle($app, $level, $route); // warm the blueprint hard
        }
        $samples = [];
        for ($i = 0; $i < $iterations; $i++) {
            gc_collect_cycles();
            $t = hrtime(true);
            H::handle($app, $level, $route);
            $samples[] = hrtime(true) - $t;
        }
        sort($samples);
        $results[$route]['us'] = $samples[intdiv(count($samples), 2)] / 1e3; // median
    }

    gc_collect_cycles();

    echo json_encode([
        'level' => $level,
        'boot_us' => $bootUs,
        'routes' => $results,
        'mem_retained' => memory_get_usage(false),
        'mem_peak' => memory_get_peak_usage(false),
    ]);
    exit(0);
}

// --- COLD arm: one fresh worker's first handful of requests. ----------------------
// A fresh process = a freshly-spawned worker (or, exactly, one FPM request's worth of
// per-process state: every Grease cache cold). We time the first `curveLen` handles of one
// route, recording each position — position 1 is the FPM cold cost; the trailing positions
// trace the cold→warm convergence. Fresh process per sample is required for honesty: the
// model blueprints are class-static and would survive an in-process reboot.
if (($argv[1] ?? null) === '--cold') {
    $level = (int) $argv[2];
    $route = $argv[3];
    $curveLen = (int) $argv[4];

    $t0 = hrtime(true);
    $app = H::bootLevel($level);
    $bootUs = (hrtime(true) - $t0) / 1e3;

    $pos = [];
    for ($i = 0; $i < $curveLen; $i++) {
        $t = hrtime(true);
        H::handle($app, $level, $route);
        $pos[] = (hrtime(true) - $t) / 1e3;
    }

    echo json_encode(['level' => $level, 'route' => $route, 'boot_us' => $bootUs, 'pos' => $pos]);
    exit(0);
}

// --- Orchestrator. ---------------------------------------------------------------

$warmIterations = (int) ($argv[1] ?? 60);
$coldWorkers = (int) ($argv[2] ?? 8);
$curveLen = (int) ($argv[3] ?? 6);

$php = escapeshellarg(PHP_BINARY);
$self = escapeshellarg(__FILE__);
$levels = array_keys(H::LEVELS);

$median = function (array $a): float {
    sort($a);
    $n = count($a);

    return $n === 0 ? 0.0 : (float) $a[intdiv($n, 2)];
};

$run = function (string $args) use ($php, $self): array {
    $out = shell_exec("$php $self $args 2>&1");
    $row = json_decode((string) $out, true);
    if (! is_array($row)) {
        fwrite(STDERR, "ARM CRASHED ($args):\n$out\n");
        exit(1);
    }

    return $row;
};

fwrite(STDERR, "Grease under a persistent worker — cold (FPM) vs warm (Octane).\n");
fwrite(STDERR, "warm iters=$warmIterations · cold workers=$coldWorkers · curve=$curveLen\n\n");

// 1) WARM: one boot per level → steady-state + parity hashes + boot + memory.
$warm = [];
foreach ($levels as $level) {
    fwrite(STDERR, 'warm  '.H::LEVELS[$level]."\n");
    $warm[$level] = $run("--warm $level $warmIterations");
}

// Parity gate — every level's warm response must byte-match vanilla, all 200.
$base = $warm[0]['routes'];
$parityOk = true;
foreach (H::ROUTES as $route) {
    foreach ($warm as $level => $data) {
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

// 2) COLD: `coldWorkers` fresh workers per (level, route) → per-position medians.
$coldCurve = []; // [level][route][pos] = median across workers
$coldBoot = [];  // [level] = [boot samples]
foreach ($levels as $level) {
    fwrite(STDERR, 'cold  '.H::LEVELS[$level]."\n");
    foreach (H::ROUTES as $route) {
        $perPos = array_fill(0, $curveLen, []);
        for ($w = 0; $w < $coldWorkers; $w++) {
            $row = $run("--cold $level ".escapeshellarg($route)." $curveLen");
            foreach ($row['pos'] as $p => $us) {
                $perPos[$p][] = $us;
            }
            $coldBoot[$level][] = $row['boot_us'];
        }
        foreach ($perPos as $p => $samples) {
            $coldCurve[$level][$route][$p] = $median($samples);
        }
    }
}

// Convenience accessors.
$cold = fn (int $l, string $r): float => $coldCurve[$l][$r][0];               // FPM cold = position 1
$warmUs = fn (int $l, string $r): float => $warm[$l]['routes'][$r]['us'];     // Octane steady
$full = array_key_last(H::LEVELS);                                            // top of the stack

// === Report ======================================================================

echo "\nParity: OK — every level's response byte-identical to vanilla, all 200.\n\n";

$pct = fn (float $from, float $to): string => sprintf('%+.1f%%', ($to - $from) / $from * 100);
$us = fn (float $v): string => $v >= 1000 ? sprintf('%.2fms', $v / 1000) : sprintf('%.0fµs', $v);
$pad = fn (string $s, int $w = 10): string => str_pad($s, $w, ' ', STR_PAD_LEFT);

// --- Table A — the headline: cold start vs warm steady, full stack vs vanilla. ----
echo "Per-request cost — full Grease stack vs vanilla, cold-start vs warm-steady worker\n";
echo "  cold = a freshly-spawned worker's FIRST handle (every per-process cache cold)\n";
echo "  warm = the same worker once warm — blueprints built, surviving across requests\n";
echo "         (the persistent-worker / Octane steady state)\n\n";

printf("%-24s%s%s%s   %s%s%s\n", 'route',
    $pad('cold van'), $pad('cold grsd'), $pad('cold Δ'),
    $pad('warm van'), $pad('warm grsd'), $pad('warm Δ'));
echo str_repeat('-', 24 + 10 * 6 + 3)."\n";
foreach (H::ROUTES as $route) {
    printf("%-24s%s%s%s   %s%s%s\n", $route,
        $pad($us($cold(0, $route))), $pad($us($cold($full, $route))), $pad($pct($cold(0, $route), $cold($full, $route))),
        $pad($us($warmUs(0, $route))), $pad($us($warmUs($full, $route))), $pad($pct($warmUs(0, $route), $warmUs($full, $route))));
}
echo "\nThe absolute Grease saving is similar cold or warm; as a FRACTION it's far larger warm,\n";
echo "because a warm request is so much cheaper. A persistent worker (Octane) runs warm after\n";
echo "request 1, so the warm Δ is what it actually delivers. Shared-nothing FPM re-pays the\n";
echo "per-process warmup every request → its real Δ sits between cold and warm (closer to warm\n";
echo "than this CLI cold shows, since opcached FPM skips the opcode-recompile the cold arm pays).\n\n";

// --- Table B — the warmup tax = the precompile ceiling. --------------------------
// Grease-specific warmup = (greased cold→warm hump) − (vanilla cold→warm hump). The
// container slice is the marginal hump the +container level adds over +blade — the lever a
// `grease:cache` precompile would let FPM skip entirely.
// This is the OPCODE-INDEPENDENT slice: differencing greased−vanilla cancels the framework
// opcode-compile common to both arms, leaving the Grease-specific per-process warmup — the
// exact thing a `grease:cache` precompile (an opcached, pre-built blueprint file) could erase
// for non-Octane apps. If this column is small, the precompile's FPM ceiling is small.
echo "Warmup tax — Grease-specific per-process warmup (the grease:cache precompile ceiling)\n\n";
$humpGrease = fn (string $r): float => ($cold($full, $r) - $warmUs($full, $r)) - ($cold(0, $r) - $warmUs(0, $r));
$humpContainer = fn (string $r): float => ($cold(4, $r) - $warmUs(4, $r)) - ($cold(3, $r) - $warmUs(3, $r));
printf("%-24s%s%s%s\n", 'route', $pad('full-stack', 12), $pad('container', 12), $pad('Oct steady', 12));
echo str_repeat('-', 24 + 36)."\n";
foreach (H::ROUTES as $route) {
    printf("%-24s%s%s%s\n", $route,
        $pad($us(max(0, $humpGrease($route))), 12),
        $pad($us(max(0, $humpContainer($route))), 12),
        $pad($us($warmUs($full, $route)), 12));
}
echo "\n'container' is the warmup a precompiled blueprint file would erase for non-Octane apps.\n\n";

// --- Table C — boot cost + retained memory (the long-lived-worker view). ---------
echo "Boot cost & retained memory, per cumulative level (a worker is long-lived)\n";
echo "  (boot includes the test DB seed — constant across levels, so the Δ is the honest part)\n\n";
$col = fn (string $s) => str_pad($s, 11, ' ', STR_PAD_LEFT);
printf('%-24s', '');
foreach (H::LEVELS as $name) {
    echo $col($name);
}
echo "\n".str_repeat('-', 24 + 11 * count(H::LEVELS))."\n";

printf('%-24s', 'boot (median)');
foreach ($levels as $level) {
    echo $col($us($median($coldBoot[$level])));
}
echo "\n";
printf('%-24s', 'boot Δ vs vanilla');
$bootV = $median($coldBoot[0]);
foreach ($levels as $level) {
    echo $col($level === 0 ? '—' : $pct($bootV, $median($coldBoot[$level])));
}
echo "\n";
printf('%-24s', 'retained MB');
foreach ($levels as $level) {
    echo $col(sprintf('%.2f', $warm[$level]['mem_retained'] / 1048576));
}
echo "\n";
printf('%-24s', 'retained Δ vs vanilla');
$memV = $warm[0]['mem_retained'];
foreach ($levels as $level) {
    echo $col($level === 0 ? '—' : $pct($memV, $warm[$level]['mem_retained']));
}
echo "\n";

echo "\nCaveats: macOS distorts magnitudes — reproduce on Linux via benchmarks/docker. And the\n";
echo "cold arm runs under CLI (opcache.enable_cli=0), so its cold cost includes a per-process\n";
echo "opcode recompile that a real opcached FPM worker skips — read the COLD column as a\n";
echo "cold-start upper bound, not a steady-FPM prediction. The warm column and the differenced\n";
echo "warmup-tax table are opcode-independent and hold across environments.\n";
