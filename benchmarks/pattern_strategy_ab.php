<?php

/**
 * "Is caching CompiledPatternSet necessary, and where does it win vs lose against Str::is?"
 *
 * CompiledPatternSet only pays off by AMORTIZING its build across matches. The two Grease call
 * sites amortize differently, so this measures both shapes:
 *
 *   IS() SHAPE      — one match per built set. A single request()->is('admin/*') matches the
 *                     path ONCE. Without a cache you rebuild per call, so build cost is paid
 *                     every time → compare to Str::is. The static cache is what amortizes across
 *                     repeated calls (a nav partial, or the same route across requests/Octane).
 *
 *   MIDDLEWARE SHAPE — many matches per built set. CleanRequestInput builds the except set ONCE
 *                     per request and matches it against every input leaf, so it amortizes WITHIN
 *                     the request — no static cache needed.
 *
 * Three strategies per shape: Str::is (rebuilds per match call), CompiledPatternSet built FRESH
 * per build (no cache), and a PREBUILT set (cache hit). Values are mostly non-matching (the
 * common case — most keys/paths miss the pattern), which is exactly where Str::is is worst: it
 * falls through to preg_match even for a literal pattern.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d opcache.enable_cli=1 -d memory_limit=1G \
 *       benchmarks/pattern_strategy_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Support\CompiledPatternSet;
use Illuminate\Support\Str;

$iters = (int) ($argv[1] ?? 40000);

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
$pct = fn (float $base, float $x) => sprintf('%+.1f%%', ($x - $base) / $base * 100);

// Pattern sets to test.
$sets = [
    'literal ×1 (miss)' => [['admin'], 'dashboard'],
    'literal ×1 (hit)' => [['admin'], 'admin'],
    'wildcard ×1 (miss)' => [['admin/*'], 'dashboard/index'],
    'wildcard ×1 (hit)' => [['admin/*'], 'admin/users'],
    'default except ×3 (miss)' => [['current_password', 'password', 'password_confirmation'], 'email'],
];

echo 'jit: '.((opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no')."   iters=$iters\n";

echo "\n=== IS() SHAPE — one match per build (the per-call question) ===\n";
printf("%-28s %10s %10s %10s\n", 'pattern set / value', 'Str::is', 'fresh', 'prebuilt');
foreach ($sets as $label => [$patterns, $value]) {
    $prebuilt = new CompiledPatternSet($patterns);

    $tStrIs = $best(fn () => Str::is($patterns, $value));
    $tFresh = $best(fn () => (new CompiledPatternSet($patterns))->matches($value));
    $tPre = $best(fn () => $prebuilt->matches($value));

    printf("%-28s %10s %10s %10s   fresh %s · prebuilt %s\n",
        $label, $us($tStrIs), $us($tFresh), $us($tPre), $pct($tStrIs, $tFresh), $pct($tStrIs, $tPre));
}

echo "\n=== MIDDLEWARE SHAPE — one build, N matches (per-request amortization) ===\n";
// 100 keys, mostly non-matching, against the default except set (3 literals).
$except = ['current_password', 'password', 'password_confirmation'];
$keys = [];
for ($i = 0; $i < 97; $i++) {
    $keys[] = "field_$i";
}
$keys[] = 'password';
$keys[] = 'current_password';
$keys[] = 'whatever';

$tStrIsN = $best(function () use ($keys, $except) {
    foreach ($keys as $k) {
        Str::is($except, $k);
    }
});
$tFreshN = $best(function () use ($keys, $except) {
    $set = new CompiledPatternSet($except);   // built ONCE, like the middleware does per request
    foreach ($keys as $k) {
        $set->matches($k);
    }
});
$prebuiltN = new CompiledPatternSet($except);
$tPreN = $best(function () use ($keys, $prebuiltN) {
    foreach ($keys as $k) {
        $prebuiltN->matches($k);
    }
});

printf("match %d keys vs default except set:\n", count($keys));
printf("  %-26s %s\n", 'Str::is per key', $us($tStrIsN));
printf("  %-26s %s   (%s — build amortized over the batch)\n", 'CompiledPatternSet build+N', $us($tFreshN), $pct($tStrIsN, $tFreshN));
printf("  %-26s %s   (%s — cache hit, no build)\n", 'prebuilt, N matches', $us($tPreN), $pct($tStrIsN, $tPreN));
