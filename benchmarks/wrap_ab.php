<?php

/**
 * Grease query-grammar wrap() memo — A/B + parity gate.
 *
 * Identifier wrapping (`Grammar::wrap('posts.id')` → `` `posts`.`id` ``) is a pure string transform
 * run ~30+× per query, on EVERY query — the per-column-per-query multiplier that makes this the
 * original Grease leverage shape (like per-row hydration), not a once-per-request thinner. The
 * result is a pure function of (raw string, table prefix); the distinct-string set an app uses is
 * small and bounded, so a per-grammar memo keyed by the raw string turns the re-walk into a hash hit.
 *
 * Vanilla `Illuminate\Database\MySqlConnection`'s grammar vs `Grease\Database\MySqlConnection`'s
 * (which memoizes wrap and flushes on prefix change). A throwaway in-memory PDO stands in for the
 * real driver — wrapping never touches the PDO, only the connection's table prefix.
 *
 *   php benchmarks/wrap_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Database\MySqlConnection as GreasedConnection;
use Illuminate\Database\MySqlConnection as VanillaConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

function connect(string $class)
{
    return new $class(new PDO('sqlite::memory:'), '', '', []);
}

/** A realistic "posts + author" read: joins, qualified columns, an alias, a where, an order. */
function sampleQuery($conn): Builder
{
    return $conn->query()
        ->from('posts')
        ->select('posts.id', 'posts.title', 'posts.created_at', 'authors.name as author_name')
        ->join('authors', 'authors.id', '=', 'posts.author_id')
        ->where('posts.published', '=', 1)
        ->whereIn('posts.status', ['live', 'featured'])
        ->orderBy('posts.created_at', 'desc')
        ->limit(20);
}

// ---- Parity gate ----------------------------------------------------------------

$vanillaConn = connect(VanillaConnection::class);
$greasedConn = connect(GreasedConnection::class);
$vanilla = $vanillaConn->getQueryGrammar();
$greased = $greasedConn->getQueryGrammar();

$q = sampleQuery($greasedConn);
$van = $vanilla->compileSelect($q);
$miss = $greased->compileSelect($q);
$hit = $greased->compileSelect($q);
if ($van !== $miss || $van !== $hit) {
    echo "PARITY FAILED — compileSelect differs.\n  vanilla: $van\n  greased: $miss\n";
    exit(1);
}

foreach (['id', 'posts.id', 'posts.id as pid', '*', 'a.b.c', ''] as $s) {
    if ($vanilla->wrap($s) !== $greased->wrap($s)) {
        echo "PARITY FAILED — wrap('$s'): '{$vanilla->wrap($s)}' vs '{$greased->wrap($s)}'\n";
        exit(1);
    }
}
if ($vanilla->wrap(new Expression('count(*)')) !== $greased->wrap(new Expression('count(*)'))) {
    echo "PARITY FAILED — Expression wrap diverged.\n";
    exit(1);
}

// The invariant: prefix change must flush the memo (the greased connection does this).
$greasedConn->setTablePrefix('pfx_');
$vanillaConn->setTablePrefix('pfx_');
if ($greased->wrap('posts.id') !== $vanilla->wrap('posts.id')) {
    echo "PARITY FAILED — after prefix change: '{$greased->wrap('posts.id')}' vs '{$vanilla->wrap('posts.id')}'\n";
    exit(1);
}

echo "Parity: OK (compileSelect byte-identical · all wrap shapes + Expression · prefix-flush invariant)\n";

// ---- Benchmark ------------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 200_000);
$vanilla = connect(VanillaConnection::class)->getQueryGrammar();
$greasedConn = connect(GreasedConnection::class);
$greased = $greasedConn->getQueryGrammar();
$q = sampleQuery($greasedConn);

for ($i = 0; $i < 2000; $i++) {
    $vanilla->compileSelect($q);
    $greased->compileSelect($q);
}

function timeArm($grammar, Builder $q, int $n): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $grammar->compileSelect($q);
    }

    return (hrtime(true) - $start) / 1e9;
}

$rounds = 5;
$v = $g = 0.0;
for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $v += timeArm($vanilla, $q, $iterations);
        $g += timeArm($greased, $q, $iterations);
    } else {
        $g += timeArm($greased, $q, $iterations);
        $v += timeArm($vanilla, $q, $iterations);
    }
}
$v /= $rounds;
$g /= $rounds;

printf("\ncompileSelect (posts+author join, ~10 wrap + ~22 wrapValue), %s iters × %d rounds:\n", number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/compile)\n", $v, $v / $iterations * 1e6);
printf("  greased: %.4f s  (%.3f µs/compile)\n", $g, $g / $iterations * 1e6);
printf("  delta:   %+.1f%%\n", ($g - $v) / $v * 100);
echo "\nwrap() is per-column-per-query — a true multiplier. compileSelect is one slice of a request,\n";
echo "so expect single-digit %% end-to-end, but it compounds per query and across every request/worker.\n";
