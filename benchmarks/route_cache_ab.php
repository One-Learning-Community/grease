<?php

/**
 * Grease eager route-middleware index — FPM-cold A/B SPIKE (measure before building).
 *
 * The lazy `Grease\Routing\Router` cache wins big under Octane (persistent Router → every
 * signature resolved once, ever) but only banks the terminate-pass hit under FPM, where the
 * Router is rebuilt per request so the cache starts empty and the dispatch-pass resolve is a
 * cold miss every single request.
 *
 * The eager index (the `grease:route-cache` idea, mirroring config:cache → grease:config-cache)
 * precomputes every route's resolved+sorted middleware at build time and opcache-interns it, so
 * the lazy cache starts PRE-SEEDED — both the dispatch and terminate passes are hits from
 * request 1. Because it keys by the SAME (gathered, excluded) signature the lazy path uses, it
 * is just a pre-populated lazy cache: it only ever serves an exact-match hit (else defers), and
 * a runtime map mutation still flushes it. The only added contract is build==runtime map
 * stability (rebuild on deploy) — the config:cache caveat shape.
 *
 * This spike models ONE FPM request as: reset/seed the cache (the per-request cold state), then
 * resolve the matched route's middleware twice (dispatch + terminate). Three arms:
 *   vanilla  — 2 full resolves
 *   lazy     — empty cache → 1 full + 1 hit
 *   eager    — seeded cache → 2 hits  (+ the per-request seed assignment, COW-cheap)
 *
 *   php benchmarks/route_cache_ab.php [requests]
 *
 * SPIKE: the seed/reset live in an inline subclass; promote to the trait + a `grease:route-cache`
 * command only if the FPM delta clears the bar.
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Routing\Router as GreasedRouter;
use Grease\Tests\Fixtures\Routing\MiddlewareStack;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router as VanillaRouter;

/**
 * Spike subclass: exposes a cache reset to model a fresh FPM request. Seeding itself uses the
 * SHIPPED `useGreaseRouteMiddlewareCache()` path (what the provider calls on the opcached file).
 */
class EagerRouterSpike extends GreasedRouter
{
    public function spikeReset(): void
    {
        $this->greaseResolvedMiddleware = [];
    }
}

function makeRouter(string $class): VanillaRouter
{
    return MiddlewareStack::installInto(new $class(new Dispatcher, new Container));
}

$shapes = array_values(MiddlewareStack::shapes());

// Build the eager index ONCE (as `grease:route-cache` would at build time): signature => resolved.
$builder = makeRouter(GreasedRouter::class);
$index = [];
foreach ($shapes as [$mw, $ex]) {
    // Reuse the shipped signature so the seeded entries land as hits at request time.
    $key = GreasedRouter::greaseMiddlewareSignature($mw, $ex);
    $index[$key] = $builder->resolveMiddleware($mw, $ex);
}

// ---- Parity gate: eager hit == lazy == vanilla, list + order -------------------

$van = makeRouter(VanillaRouter::class);
$eager = makeRouter(EagerRouterSpike::class);
$eager->useGreaseRouteMiddlewareCache($index);

foreach ($shapes as $i => [$mw, $ex]) {
    $a = $van->resolveMiddleware($mw, $ex);
    $b = $eager->resolveMiddleware($mw, $ex);
    if ($a !== $b) {
        echo "PARITY FAILED — shape #$i: eager-seeded result differs from vanilla.\n";
        exit(1);
    }
}

echo 'Parity: OK ('.count($shapes)." shapes — eager-seeded hit == vanilla, list + order)\n";

// ---- Benchmark: FPM-cold model -------------------------------------------------

$requests = (int) ($argv[1] ?? 200_000);

$vanR = makeRouter(VanillaRouter::class);
$lazyR = makeRouter(EagerRouterSpike::class);
$eagerR = makeRouter(EagerRouterSpike::class);

// Warm.
for ($i = 0; $i < 2000; $i++) {
    [$mw, $ex] = $shapes[$i % count($shapes)];
    $vanR->resolveMiddleware($mw, $ex);
    $lazyR->spikeReset();
    $lazyR->resolveMiddleware($mw, $ex);
    $eagerR->spikeReset();
    $eagerR->useGreaseRouteMiddlewareCache($index);
    $eagerR->resolveMiddleware($mw, $ex);
}

function timeVanilla(VanillaRouter $r, array $shapes, int $n): float
{
    $c = count($shapes);
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        [$mw, $ex] = $shapes[$i % $c];
        $r->resolveMiddleware($mw, $ex);   // dispatch
        $r->resolveMiddleware($mw, $ex);   // terminate
    }

    return (hrtime(true) - $start) / 1e9;
}

function timeLazy(EagerRouterSpike $r, array $shapes, int $n): float
{
    $c = count($shapes);
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        [$mw, $ex] = $shapes[$i % $c];
        $r->spikeReset();                  // fresh FPM request → empty cache
        $r->resolveMiddleware($mw, $ex);   // dispatch (miss)
        $r->resolveMiddleware($mw, $ex);   // terminate (hit)
    }

    return (hrtime(true) - $start) / 1e9;
}

function timeEager(EagerRouterSpike $r, array $index, array $shapes, int $n): float
{
    $c = count($shapes);
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        [$mw, $ex] = $shapes[$i % $c];
        $r->spikeReset();                  // fresh FPM request → empty cache
        $r->useGreaseRouteMiddlewareCache($index); // load opcache-interned index
        $r->resolveMiddleware($mw, $ex);   // dispatch (hit)
        $r->resolveMiddleware($mw, $ex);   // terminate (hit)
    }

    return (hrtime(true) - $start) / 1e9;
}

$rounds = 5;
$v = $l = $e = 0.0;
for ($r = 0; $r < $rounds; $r++) {
    $v += timeVanilla($vanR, $shapes, $requests);
    $l += timeLazy($lazyR, $shapes, $requests);
    $e += timeEager($eagerR, $index, $shapes, $requests);
}
$v /= $rounds;
$l /= $rounds;
$e /= $rounds;

$usV = $v / $requests * 1e6;
$usL = $l / $requests * 1e6;
$usE = $e / $requests * 1e6;

printf("\nFPM-cold model: per request = reset/seed + 2 resolves (dispatch+terminate), %s requests × %d rounds:\n", number_format($requests), $rounds);
printf("  vanilla:         %.4f s  (%.3f µs/request)\n", $v, $usV);
printf("  lazy (shipped):  %.4f s  (%.3f µs/request)  %+.1f%% vs vanilla\n", $l, $usL, ($l - $v) / $v * 100);
printf("  eager (index):   %.4f s  (%.3f µs/request)  %+.1f%% vs vanilla,  %+.1f%% vs lazy\n", $e, $usE, ($e - $v) / $v * 100, ($e - $l) / $l * 100);
echo "\nThe eager→lazy gap is the FPM-specific win the index buys (Octane already banks it via\n";
echo "the persistent Router). macOS — confirm magnitudes on Linux.\n";
