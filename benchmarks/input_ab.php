<?php

/**
 * Grease request-input memo — tier-isolated A/B + parity gate.
 *
 * Vanilla `Illuminate\Http\Request` vs `Grease\Http\Request`. Each iteration builds a
 * FRESH request (requests are per-request in real life) and runs a representative mix of
 * ~17 accessor calls — the input()/__get/has/only/all churn a controller + middleware +
 * validation generate. Construction cost is identical across both arms, so the delta is
 * the repeated-merge work the memo eliminates. Parity-gated before timing.
 *
 *   php benchmarks/input_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Http\Request as GreasedRequest;
use Illuminate\Http\Request as VanillaRequest;

/** A realistic POST request: ~15 input keys (some nested) + a few query params. */
function makeRequest(string $class)
{
    return $class::create(
        '/users/42?page=2&sort=name&q=alice',
        'POST',
        [
            'name' => 'Alice', 'email' => 'a@example.com', 'age' => 30,
            'active' => true, 'role' => 'admin', 'bio' => 'hello',
            'address' => ['city' => 'NYC', 'zip' => '10001', 'geo' => ['lat' => 1, 'lng' => 2]],
            'tags' => ['x', 'y', 'z'], 'score' => 9.5, 'nickname' => '',
        ],
    );
}

/**
 * The per-request access mix. Returns a canonical result vector (also used as the parity
 * oracle). ~17 reads, the shape a real controller + form-request validation produces.
 */
function accessMix($r): array
{
    return [
        $r->input('name'),
        $r->input('email'),
        $r->input('address.city'),
        $r->input('address.geo.lat'),
        $r->input('missing', 'def'),
        $r->name,
        $r->role,
        $r->has('email'),
        $r->has(['name', 'age']),
        $r->filled('bio'),
        $r->filled('nickname'),
        $r->only(['name', 'email', 'role']),
        $r->except(['bio', 'tags']),
        isset($r['active']),
        $r['score'],
        $r->all(),
        $r->all(['name', 'address']),
    ];
}

// ---- Parity gate ----------------------------------------------------------------

$van = accessMix(makeRequest(VanillaRequest::class));
$gre = accessMix(makeRequest(GreasedRequest::class));

if (var_export($van, true) !== var_export($gre, true)) {
    echo "PARITY FAILED — access mix differs between vanilla and greased.\n";
    exit(1);
}

echo 'Parity: OK ('.count($van)." accessor results identical)\n";

// ---- Benchmark ------------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 50_000);

// Warm autoload / opcache for both arms.
for ($i = 0; $i < 1000; $i++) {
    accessMix(makeRequest(VanillaRequest::class));
    accessMix(makeRequest(GreasedRequest::class));
}

function timeArm(string $class, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        accessMix(makeRequest($class));
    }

    return (hrtime(true) - $start) / 1e9;
}

$rounds = 5;
$vanTotal = 0.0;
$greTotal = 0.0;

for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $vanTotal += timeArm(VanillaRequest::class, $iterations);
        $greTotal += timeArm(GreasedRequest::class, $iterations);
    } else {
        $greTotal += timeArm(GreasedRequest::class, $iterations);
        $vanTotal += timeArm(VanillaRequest::class, $iterations);
    }
}

$van = $vanTotal / $rounds;
$gre = $greTotal / $rounds;
$delta = ($gre - $van) / $van * 100;

printf("\nFresh request + ~17-accessor mix, %s iters × %d rounds:\n", number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/request)\n", $van, $van / $iterations * 1e6);
printf("  greased: %.4f s  (%.3f µs/request)\n", $gre, $gre / $iterations * 1e6);
printf("  delta:   %+.1f%%\n", $delta);
echo "\nIncludes identical per-request construction cost in both arms, so this is the\n";
echo "honest per-request delta (the isolated input-access win is larger). macOS — confirm on Linux.\n";
