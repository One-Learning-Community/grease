<?php

/**
 * Grease serialization-helper benchmark — and parity guard.
 *
 * Stand-alone (no phpbench): measures the two hand-pick serialization helpers,
 * `greaseSerializeDate()` and `greaseSerializeOnly()`, against the idiomatic
 * patterns they replace — over the SAME fixtures the parity suite proves
 * byte-identical, so the bench times exactly the behaviour a test certifies.
 *
 * Both helpers earn their keep only when serialization is hand-picked (Scout
 * `toSearchableArray`, a `JsonResource`, an export), so every timed op hydrates a
 * FRESH model: a request serializes each row once, the Carbon parse is actually
 * paid, and the cast cache never pre-warms to flatter the result. Pinned to UTC —
 * the zone the date tier certifies its fast path under.
 *
 * Requires `composer install` first.
 *
 *   php benchmarks/serialize_helpers.php [rounds]
 *
 * Swapping in your own models: copy a `*Greased` fixture's shape, point the two
 * sections at it, and keep the parity assertion — if it ever fails on your PHP,
 * the bench refuses to report a (meaningless) delta.
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\GreasedTimestamps;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaSample;
use Illuminate\Support\Arr;

date_default_timezone_set('UTC');
BootsEloquent::capsule();

$rounds = max(3, (int) ($argv[1] ?? 9));

// ── tiny harness ──────────────────────────────────────────────────────────────

/** One round: µs per op over $revs iterations. */
function round_us(callable $fn, int $revs): float
{
    $t = hrtime(true);
    for ($i = 0; $i < $revs; $i++) {
        $fn();
    }

    return (hrtime(true) - $t) / $revs / 1000;
}

/** Median µs/op over $rounds rounds, two warmup rounds discarded. */
function bench(callable $fn, int $rounds, int $revs): float
{
    round_us($fn, $revs);
    round_us($fn, $revs);

    $samples = [];
    for ($r = 0; $r < $rounds; $r++) {
        $samples[] = round_us($fn, $revs);
    }
    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

function pct(float $base, float $new): string
{
    return sprintf('%+.1f%%', ($new - $base) / $base * 100);
}

function parity_or_die($expected, $actual, string $what): void
{
    if (json_encode($expected) !== json_encode($actual)) {
        fwrite(STDERR, "PARITY BROKEN ($what)\n  expected: ".json_encode($expected)."\n  actual:   ".json_encode($actual)."\n");
        exit(1);
    }
}

function row(string $label, float $us, ?float $base = null): void
{
    printf("  %-34s %8.2f µs%s\n", $label, $us, $base === null ? '' : '   '.pct($base, $us));
}

echo "Grease serialization helpers — $rounds rounds, fresh hydrate per op, UTC\n";
echo str_repeat('─', 64)."\n";

// ── 1. greaseSerializeDate() vs the idiomatic ?->toJSON() hand-pick ─────────────
//
// Same greased, date-heavy model on both sides, so hydration cancels and the delta
// is purely the eliminated per-field Carbon parse.

$tsRow = SampleData::timestampsRow();
$tsKeys = ['created_at', 'updated_at'];

// Prove byte-identity once, and show the runner the actual output.
$probe = (new GreasedTimestamps)->newFromBuilder($tsRow);
foreach ($tsKeys as $k) {
    parity_or_die($probe->{$k}?->toJSON(), $probe->greaseSerializeDate($k), "greaseSerializeDate:$k");
}

echo "\n1. greaseSerializeDate()  —  pick 2 timestamps off a thin model\n";
echo '   output: '.json_encode(array_map(fn ($k) => $probe->greaseSerializeDate($k), $tsKeys))."\n\n";

$revs = 20000;
$idiomatic = bench(function () use ($tsRow, $tsKeys) {
    $m = (new GreasedTimestamps)->newFromBuilder($tsRow);
    foreach ($tsKeys as $k) {
        $out = $m->{$k}?->toJSON();
    }
}, $rounds, $revs);

$primitive = bench(function () use ($tsRow, $tsKeys) {
    $m = (new GreasedTimestamps)->newFromBuilder($tsRow);
    foreach ($tsKeys as $k) {
        $out = $m->greaseSerializeDate($k);
    }
}, $rounds, $revs);

row('idiomatic  ?->toJSON()', $idiomatic);
row('greaseSerializeDate()', $primitive, $idiomatic);

// ── 2. greaseSerializeOnly() vs Arr::only(attributesToArray(), keys) ────────────
//
// A wide, cast-heavy model (23 columns: dates, enums, decimals, json) serialized
// down to a few keys — the curated-subset shape. The naive line serializes all 23
// and throws most away; the helper serializes only what was asked for.

$wideRow = SampleData::row();
$wideKeys = ['str_val', 'status_val', 'created_at'];

$g = (new GreasedSample)->newFromBuilder($wideRow);
$naiveExpected = Arr::only((new VanillaSample)->newFromBuilder($wideRow)->attributesToArray(), $wideKeys);
parity_or_die($naiveExpected, $g->greaseSerializeOnly($wideKeys), 'greaseSerializeOnly');

echo "\n2. greaseSerializeOnly()  —  pick 3 of 23 columns off a wide model\n";
echo '   output: '.json_encode($g->greaseSerializeOnly($wideKeys))."\n\n";

$revs = 10000;
$naive = bench(function () use ($wideRow, $wideKeys) {
    $m = (new GreasedSample)->newFromBuilder($wideRow);
    $out = Arr::only($m->attributesToArray(), $wideKeys);
}, $rounds, $revs);

$setVisible = bench(function () use ($wideRow, $wideKeys) {
    $m = (new GreasedSample)->newFromBuilder($wideRow);
    $out = $m->setVisible($wideKeys)->attributesToArray();
}, $rounds, $revs);

$only = bench(function () use ($wideRow, $wideKeys) {
    $m = (new GreasedSample)->newFromBuilder($wideRow);
    $out = $m->greaseSerializeOnly($wideKeys);
}, $rounds, $revs);

row('naive  Arr::only(toArray, keys)', $naive);
row('setVisible()->attributesToArray()', $setVisible, $naive);
row('greaseSerializeOnly()', $only, $naive);

echo "\n".str_repeat('─', 64)."\n";
echo "Parity held on this PHP build; deltas above are byte-identical swaps.\n";
