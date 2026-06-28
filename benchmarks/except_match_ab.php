<?php

/**
 * A/B for the except-key match strategy inside the (proposed) fused input cleaner — the
 * per-leaf `Str::is($except, $key)` that TrimStrings runs on EVERY string node.
 *
 * Str::is fed an array preg_quote()s + str_replace()s + preg_match()es PER PATTERN, and for a
 * non-matching key (the overwhelming common case — most fields aren't password fields) it does
 * that for ALL N patterns before returning false. Four strategies, all faithful to Str::is
 * semantics (only `*` is a wildcard; everything else is a literal exact-match):
 *
 *   str_is        vanilla Str::is($patterns, $key)                      (rebuilds regex/leaf)
 *   wildcard_arr  pre-compiled WildcardPattern[] (regex built once), looped per key
 *   merged_regex  ALL patterns OR'd into ONE #^(?:a|b|c)\z#su, one preg_match/key
 *   hybrid        literals → isset() hash (O(1)); wildcard patterns → one merged regex
 *
 * Two except sets: the framework default (3 literals, no wildcard) and a wildcard-heavy set.
 * Parity-gated: every strategy must agree with real Str::is on every (key × set) before timing.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d opcache.enable_cli=1 -d memory_limit=1G \
 *       benchmarks/except_match_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Support\WildcardPattern;
use Illuminate\Support\Str;

$iters = (int) ($argv[1] ?? 20000);

// --- The keys a 100-leaf nested payload presents to the except match (dotted, as cleanArray
//     builds them), with a few real password-field hits mixed in. ---
function buildKeys(): array
{
    $keys = [];
    for ($i = 0; $i < 30; $i++) {
        $keys[] = "field_$i";
    }
    for ($a = 0; $a < 5; $a++) {
        foreach (['line1', 'line2', 'city', 'state', 'zip', 'country'] as $f) {
            $keys[] = "addresses.$a.$f";
        }
    }
    for ($n = 0; $n < 10; $n++) {
        foreach (['sku', 'name', 'qty', 'note'] as $f) {
            $keys[] = "items.$n.$f";
        }
    }
    // a few keys that DO hit the except sets
    $keys[] = 'password';
    $keys[] = 'password_confirmation';
    $keys[] = 'api_token';
    $keys[] = 'billing.secret';

    return $keys;
}

$keys = buildKeys();
$sets = [
    'literal-default' => ['current_password', 'password', 'password_confirmation'],
    'wildcard-heavy' => ['password', '*_token', '*.secret', 'api_*'],
];

// --- Strategies. ---
$compileWildcardArr = fn (array $pats) => array_map(fn ($p) => new WildcardPattern($p), $pats);
$matchWildcardArr = function (array $wps, string $key): bool {
    foreach ($wps as $wp) {
        if ($wp->matches($key)) {
            return true;
        }
    }

    return false;
};

$compileMerged = function (array $pats): string {
    $alts = array_map(fn ($p) => $p === '*' ? '.*' : str_replace('\*', '.*', preg_quote($p, '#')), $pats);

    return '#^(?:'.implode('|', $alts).')\z#su';
};
$matchMerged = fn (string $regex, string $key): bool => preg_match($regex, $key) === 1;

$compileHybrid = function (array $pats): array {
    $lit = [];
    $wild = [];
    foreach ($pats as $p) {
        if (! str_contains($p, '*')) {
            $lit[$p] = true;                          // literal → exact match
        } else {
            $wild[] = str_replace('\*', '.*', preg_quote($p, '#'));
        }
    }
    $regex = $wild ? '#^(?:'.implode('|', $wild).')\z#su' : null;

    return [$lit, $regex];
};
$matchHybrid = function (array $compiled, string $key): bool {
    [$lit, $regex] = $compiled;

    return isset($lit[$key]) || ($regex !== null && preg_match($regex, $key) === 1);
};

// --- Parity: every strategy === Str::is on every (set × key). ---
foreach ($sets as $name => $pats) {
    $wa = $compileWildcardArr($pats);
    $mr = $compileMerged($pats);
    $hy = $compileHybrid($pats);
    foreach ($keys as $k) {
        $oracle = Str::is($pats, $k);
        $got = [
            'wildcard_arr' => $matchWildcardArr($wa, $k),
            'merged_regex' => $matchMerged($mr, $k),
            'hybrid' => $matchHybrid($hy, $k),
        ];
        foreach ($got as $strat => $v) {
            if ($v !== $oracle) {
                fwrite(STDERR, "PARITY FAIL [$name] $strat on '$k': got ".var_export($v, true).' want '.var_export($oracle, true)."\n");
                exit(1);
            }
        }
    }
}
echo "Parity: OK — wildcard_arr / merged_regex / hybrid all match Str::is on every key × set.\n";
echo count($keys)." keys/op · iters=$iters · jit: ".((opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no')."\n";

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

$us = fn (float $ns) => sprintf('%.2fµs', $ns / 1000);
$pct = fn (float $a, float $b) => sprintf('%+.1f%%', ($b - $a) / $a * 100);

foreach ($sets as $name => $pats) {
    $wa = $compileWildcardArr($pats);
    $mr = $compileMerged($pats);
    $hy = $compileHybrid($pats);

    // warm
    foreach ($keys as $k) {
        Str::is($pats, $k);
        $matchWildcardArr($wa, $k);
        $matchMerged($mr, $k);
        $matchHybrid($hy, $k);
    }

    $tStrIs = $best(function () use ($keys, $pats) {
        foreach ($keys as $k) {
            Str::is($pats, $k);
        }
    });
    $tWa = $best(function () use ($keys, $wa, $matchWildcardArr) {
        foreach ($keys as $k) {
            $matchWildcardArr($wa, $k);
        }
    });
    $tMr = $best(function () use ($keys, $mr, $matchMerged) {
        foreach ($keys as $k) {
            $matchMerged($mr, $k);
        }
    });
    $tHy = $best(function () use ($keys, $hy, $matchHybrid) {
        foreach ($keys as $k) {
            $matchHybrid($hy, $k);
        }
    });

    echo "\n== except set: $name (".implode(', ', $pats).') — match all '.count($keys)." keys ==\n";
    printf("  %-14s %s\n", 'str_is', $us($tStrIs));
    printf("  %-14s %s   (%s vs Str::is)\n", 'wildcard_arr', $us($tWa), $pct($tStrIs, $tWa));
    printf("  %-14s %s   (%s vs Str::is)\n", 'merged_regex', $us($tMr), $pct($tStrIs, $tMr));
    printf("  %-14s %s   (%s vs Str::is)\n", 'hybrid', $us($tHy), $pct($tStrIs, $tHy));
}
