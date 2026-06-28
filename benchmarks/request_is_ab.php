<?php

/**
 * A/B for Grease\Http\Request::is() — vanilla vs the shipped greased override.
 *
 * Vanilla Request::is(...$patterns) does, per call:
 *   (new Collection($patterns))->contains(fn ($p) => Str::is($p, $this->decodedPath()))
 * so it allocates a Collection, recomputes decodedPath() (rawurldecode(path())) PER pattern,
 * and Str::is recompiles its regex PER pattern. In a nav partial that calls request()->is(...)
 * on every link, that recompile + re-decode runs for every link, every render.
 *
 * The greased override memoizes decodedPath() per instance, flattens the varargs once, and
 * reuses a statically-cached CompiledPatternSet keyed by the patterns (so repeated calls — same
 * nav, and across requests under Octane — skip the recompile). Byte-identical to Str::is-based
 * is(): CompiledPatternSet ORs the flattened patterns exactly as Collection::contains +
 * Str::is(iterable) does. Parity is gated below and in tests/Http/RequestInputParityTest.php.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d opcache.enable_cli=1 -d memory_limit=1G \
 *       benchmarks/request_is_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Http\Request as GreasedRequestIs;
use Illuminate\Http\Request;

$iters = (int) ($argv[1] ?? 50000);

// The greased arm is the SHIPPED Grease\Http\Request — its is() override (memoized
// decodedPath + a CompiledPatternSet cached per pattern args) is what this times, so the
// bench runs exactly what RequestInputParityTest proves byte-identical.

// --- Parity gate: greased is() === vanilla is() across patterns × paths. ---
$paths = ['admin/users', 'admin', 'dashboard', 'api/v1/posts/42', 'login', ''];
$patternArgs = [
    ['admin/*'], ['admin'], ['dashboard'], ['api/*'], ['admin/*', 'dashboard'],
    [['admin/*', 'api/*']], ['*'], ['nope'], ['api/v1/*', 'admin/*', 'login'],
];
foreach ($paths as $p) {
    $van = Request::create('/'.$p, 'GET');
    $grs = GreasedRequestIs::create('/'.$p, 'GET');
    foreach ($patternArgs as $args) {
        $a = $van->is(...$args);
        $b = $grs->is(...$args);
        if ($a !== $b) {
            fwrite(STDERR, "PARITY FAIL path='/$p' patterns=".json_encode($args).' vanilla='.var_export($a, true).' greased='.var_export($b, true)."\n");
            exit(1);
        }
    }
}
echo "Parity: OK — greased is() matches vanilla is() across paths × patterns.\n";
echo 'jit: '.((opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no')."   iters=$iters\n";

$best = function (callable $f) use ($iters): float {
    $m = PHP_FLOAT_MAX;
    for ($r = 0; $r < 7; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            $f();
        }
        $m = min($m, (hrtime(true) - $t) / $iters);
    }

    return $m;
};
$us = fn (float $ns) => sprintf('%.3fµs', $ns / 1000);
$pct = fn (float $a, float $b) => sprintf('%+.1f%%', ($b - $a) / $a * 100);

$vanReq = Request::create('/admin/users/42', 'GET');
$grsReq = GreasedRequestIs::create('/admin/users/42', 'GET');

// Scenario 1 — a nav partial: 12 links, each a distinct single-pattern is() check.
$nav = ['admin/*', 'dashboard*', 'users/*', 'posts/*', 'settings/*', 'billing/*',
    'reports/*', 'api/*', 'profile/*', 'help/*', 'search/*', 'login'];
$navVan = function () use ($vanReq, $nav) {
    foreach ($nav as $p) {
        $vanReq->is($p);
    }
};
$navGrs = function () use ($grsReq, $nav) {
    foreach ($nav as $p) {
        $grsReq->is($p);
    }
};

// Scenario 2 — one is() with a single pattern (the simplest, most common call).
$oneVan = fn () => $vanReq->is('admin/*');
$oneGrs = fn () => $grsReq->is('admin/*');

// Scenario 3 — one is() with several patterns.
$multiVan = fn () => $vanReq->is('admin/*', 'dashboard', 'api/v1/*', 'reports/*');
$multiGrs = fn () => $grsReq->is('admin/*', 'dashboard', 'api/v1/*', 'reports/*');

// warm (also primes the pattern cache, as a long-lived worker would have)
for ($i = 0; $i < 1000; $i++) {
    $navVan();
    $navGrs();
    $multiVan();
    $multiGrs();
}

foreach ([
    'nav partial (12 link checks)' => [$navVan, $navGrs],
    'single is(one pattern)' => [$oneVan, $oneGrs],
    'single is(4 patterns)' => [$multiVan, $multiGrs],
] as $label => [$v, $g]) {
    $tv = $best($v);
    $tg = $best($g);
    printf("%-30s vanilla %s   greased %s   (%s)\n", $label, $us($tv), $us($tg), $pct($tv, $tg));
}
