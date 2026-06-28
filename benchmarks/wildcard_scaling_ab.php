<?php

/**
 * Does CompiledPatternSet's regex MERGING win as the number of actual wildcard patterns grows?
 *
 * The thesis: merging N wildcard patterns into ONE alternation regex `#^(?:a.*|b.*|…)\z#` is a
 * single preg_match per value, whereas Str::is and a per-pattern WildcardPattern[] loop do up to
 * N preg_match calls (with PHP-land call overhead each, and Str::is also recompiles per call).
 * So merging should pull ahead as N grows — most on a MISS (every pattern must be tried) and on
 * a HIT at the END of the list (no early exit helps the loop).
 *
 * Three strategies, all PREBUILT (the amortized/cached scenario CompiledPatternSet targets),
 * plus Str::is which always rebuilds (vanilla):
 *   str_is        Str::is($patterns, $value)              rebuild + up to N preg_match
 *   wildcard_arr  N pre-compiled WildcardPattern, looped  up to N preg_match, no rebuild
 *   merged        CompiledPatternSet (one alternation)    ONE preg_match
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d opcache.enable_cli=1 -d memory_limit=1G \
 *       benchmarks/wildcard_scaling_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Support\CompiledPatternSet;
use Grease\Support\WildcardPattern;
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

echo 'jit: '.((opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no')."   iters=$iters\n";

foreach ([4, 10, 25, 50] as $n) {
    // N genuine wildcard patterns, e.g. "webhooks-3/*".
    $patterns = [];
    for ($i = 0; $i < $n; $i++) {
        $patterns[] = "segment-$i/*";
    }
    $wps = array_map(fn ($p) => new WildcardPattern($p), $patterns);
    $merged = new CompiledPatternSet($patterns);

    $matchArr = function (string $v) use ($wps): bool {
        foreach ($wps as $wp) {
            if ($wp->matches($v)) {
                return true;
            }
        }

        return false;
    };

    foreach ([
        'miss' => 'dashboard/index',          // matches none → loop must try all N
        'hit@end' => 'segment-'.($n - 1).'/x', // matches the LAST pattern → no early exit
    ] as $kind => $value) {
        // sanity: all agree
        $oracle = Str::is($patterns, $value);
        if ($matchArr($value) !== $oracle || $merged->matches($value) !== $oracle) {
            fwrite(STDERR, "PARITY FAIL n=$n $kind\n");
            exit(1);
        }

        $tStr = $best(fn () => Str::is($patterns, $value));
        $tArr = $best(fn () => $matchArr($value));
        $tMrg = $best(fn () => $merged->matches($value));

        printf("n=%-3d %-8s  str_is %s   wildcard_arr %s (%s)   merged %s (%s vs str_is, %s vs arr)\n",
            $n, $kind, $us($tStr), $us($tArr), $pct($tStr, $tArr),
            $us($tMrg), $pct($tStr, $tMrg), $pct($tArr, $tMrg));
    }
}
