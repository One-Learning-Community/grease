<?php

/**
 * Summarize an Xdebug cachegrind file by self-time per function — the companion to
 * benchmarks/blade_profile.php. Xdebug distorts absolute timings (internal calls are
 * cheap, it overrides zend_execute_ex so JIT is off), but the ranking by self-cost and
 * the exact call counts are what point at the next hot spot.
 *
 *   php benchmarks/cachegrind_top.php /path/to/cachegrind.out.NNNN [topN]
 *
 * Call counts are the tell: e.g. ~9 Collection allocations per single component render
 * is what flagged ComponentAttributeBag::merge as the lever after @props.
 */
$file = $argv[1] ?? null;
if (! $file || ! is_file($file)) {
    fwrite(STDERR, "usage: php benchmarks/cachegrind_top.php <cachegrind.out.NNNN> [topN]\n");
    exit(1);
}
$topN = (int) ($argv[2] ?? 40);

$fp = fopen($file, 'r');
$names = [];          // compressed-id → function name
$self = [];           // function → self time units
$calls = [];          // function → invocation count
$cur = null;
$callee = null;
$inCall = false;

/** Resolve a compressed `(id) name` / `(id)` reference, caching the name table. */
$resolve = static function (string $rest) use (&$names): string {
    if (preg_match('/^\((\d+)\)(?:\s(.*))?$/', $rest, $m)) {
        if (isset($m[2]) && $m[2] !== '') {
            $names[$m[1]] = $m[2];
        }

        return $names[$m[1]] ?? "({$m[1]})";
    }

    return $rest;
};

while (($line = fgets($fp)) !== false) {
    $line = rtrim($line, "\n");

    if (str_starts_with($line, 'fn=')) {
        $cur = $resolve(substr($line, 3));
        $self[$cur] ??= 0;
        $calls[$cur] ??= 0;
        $inCall = false;
    } elseif (str_starts_with($line, 'cfn=')) {
        $callee = $resolve(substr($line, 4));
    } elseif (str_starts_with($line, 'calls=')) {
        $calls[$callee] = ($calls[$callee] ?? 0) + (int) explode(' ', substr($line, 6))[0];
        $inCall = true;   // the next cost line is the call's inclusive cost, not self
    } elseif (preg_match('/^\d+ (\d+) \d+$/', $line, $m)) {
        if ($inCall) {
            $inCall = false;
        } elseif ($cur !== null) {
            $self[$cur] += (int) $m[1];
        }
    } else {
        $inCall = false;
    }
}

arsort($self);
$total = array_sum($self) ?: 1;

printf("total self time: %d units (~%.1f ms, Xdebug-inflated)\n\n", $total, $total / 1e5);
printf("%-8s %-9s %s\n", 'self%', 'calls', 'function');

$i = 0;
foreach ($self as $fn => $cost) {
    if ($i++ >= $topN) {
        break;
    }
    printf("%6.2f%% %9d  %s\n", $cost / $total * 100, $calls[$fn] ?? 0, $fn);
}
