<?php

/**
 * Grease middleware resolve+sort cache — tier-isolated A/B + parity gate.
 *
 * Vanilla `Illuminate\Routing\Router::resolveMiddleware()` vs `Grease\Routing\Router`, which
 * memoizes the EXACT returned sorted array, keyed by the literal (gathered, excluded) name
 * arrays. The resolve+sort — group/alias expansion via `MiddlewareNameResolver`, the
 * map/flatten/values Collection chain, and a `SortedMiddleware` pass that calls
 * `class_implements()`+`class_parents()` on every middleware string — is a pure function of
 * (those names, the process-constant alias/group/priority maps). The raw list is already
 * cached on the Route (`computedMiddleware`); the resolve+sort is NOT. (route:cache does not
 * help here — it caches URL matching + raw middleware NAMES, never the resolved/sorted list.)
 *
 * Per request the matched route is resolved ~2× (dispatch + terminate). Under Octane the
 * Router singleton persists, so the SAME signature is resolved across every request the
 * worker serves — N iterations here model that steady state. The per-call delta is the win.
 *
 *   php benchmarks/middleware_ab.php [iterations]
 *
 * Shares the {@see MiddlewareStack} fixture with
 * `RouterMiddlewareParityTest`, so this times exactly what those tests prove byte-identical.
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Routing\Router as GreasedRouter;
use Grease\Tests\Fixtures\Routing\MiddlewareStack;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router as VanillaRouter;

function makeRouter(string $class): VanillaRouter
{
    return MiddlewareStack::installInto(new $class(new Dispatcher, new Container));
}

$shapes = MiddlewareStack::shapes();

// ---- Parity gate ----------------------------------------------------------------

$van = makeRouter(VanillaRouter::class);
$gre = makeRouter(GreasedRouter::class);

foreach ($shapes as $label => [$mw, $ex]) {
    $a = $van->resolveMiddleware($mw, $ex);
    $b = $gre->resolveMiddleware($mw, $ex);   // miss → populates cache
    $c = $gre->resolveMiddleware($mw, $ex);   // hit
    if ($a !== $b || $a !== $c) {
        echo "PARITY FAILED — '$label' differs (vanilla vs greased miss vs greased hit).\n";
        echo 'vanilla: '.var_export($a, true)."\n";
        echo 'greased: '.var_export($b, true)."\n";
        exit(1);
    }
}

echo 'Parity: OK ('.count($shapes)." route shapes — list + order identical, miss == hit == vanilla)\n";

// ---- Benchmark ------------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 100_000);

// Warm autoload / opcache for both arms (also primes the greased cache, as Octane would).
for ($i = 0; $i < 2000; $i++) {
    foreach ($shapes as [$mw, $ex]) {
        $van->resolveMiddleware($mw, $ex);
        $gre->resolveMiddleware($mw, $ex);
    }
}

function timeArm(VanillaRouter $router, array $shapes, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        foreach ($shapes as [$mw, $ex]) {
            $router->resolveMiddleware($mw, $ex);
        }
    }

    return (hrtime(true) - $start) / 1e9;
}

$rounds = 5;
$vanTotal = 0.0;
$greTotal = 0.0;

for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $vanTotal += timeArm($van, $shapes, $iterations);
        $greTotal += timeArm($gre, $shapes, $iterations);
    } else {
        $greTotal += timeArm($gre, $shapes, $iterations);
        $vanTotal += timeArm($van, $shapes, $iterations);
    }
}

$vanAvg = $vanTotal / $rounds;
$greAvg = $greTotal / $rounds;
$delta = ($greAvg - $vanAvg) / $vanAvg * 100;
$perResolveVan = $vanAvg / $iterations / count($shapes) * 1e6;
$perResolveGre = $greAvg / $iterations / count($shapes) * 1e6;

printf("\nResolve %d route shapes, %s iters × %d rounds (steady-state / Octane model):\n", count($shapes), number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/resolve)\n", $vanAvg, $perResolveVan);
printf("  greased: %.4f s  (%.3f µs/resolve)\n", $greAvg, $perResolveGre);
printf("  delta:   %+.1f%%\n", $delta);
echo "\nFPM does this ~2×/request (dispatch + terminate) — cold-first, so the FPM win is the\n";
echo "hit-vs-miss delta amortized over 2 calls. The full steady-state win lands under Octane\n";
echo "(persistent Router → same signature resolved across every request). macOS — confirm on Linux.\n";
