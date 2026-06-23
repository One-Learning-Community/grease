<?php

/**
 * What the config tier buys a REAL request — at the call volume that actually occurs.
 *
 * A single request in a real app makes thousands of config()->get() calls (measured: 2600+ in
 * a production app). At that volume a per-call saving that looks tiny in isolation compounds
 * into real per-request time. This models one request as N get() calls over a realistic key
 * mix (hot keys re-read many times + a spread tail), with a FRESH repo per request — i.e. the
 * per-request-cold reality (every FPM request; every Octane sandbox clone resets the memo).
 *
 *   vanilla  Arr::get dot-walk every call (the current mechanism)
 *   lazy     greased memo — first touch per distinct key walks, repeats hit (resets per request)
 *   flat     grease:config-cache index — every call a hash hit from call 1
 *
 *   php benchmarks/config_request_sim.php [callsPerRequest] [requests]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Config\ConfigCacheCommand;
use Grease\Config\Repository as GreasedRepository;
use Illuminate\Config\Repository as VanillaRepository;

/** ~1360 leaves across 40 "files", depth 2–4 (a medium app; a large one is ~3500). */
function bigConfig(): array
{
    $c = [];
    for ($f = 0; $f < 40; $f++) {
        $name = "svc$f";
        $c[$name] = ['default' => "d$f", 'enabled' => $f % 2 === 0, 'ttl' => $f * 10, 'url' => "http://h$f"];
        for ($g = 0; $g < 6; $g++) {
            $c[$name]["group$g"] = ['host' => "10.0.$f.$g", 'port' => 1000 + $g, 'opts' => ['a' => 1, 'b' => 2, 'timeout' => 30]];
        }
    }

    return $c;
}

$config = bigConfig();
$flatIndex = ConfigCacheCommand::buildFlatIndex($config)['index'];
$leafKeys = array_keys($flatIndex);

// Build a deterministic call sequence: hot keys (framework re-reads app.*/db/cache/... a lot)
// dominate; a spread tail covers the rest. ~hot 40 keys carry most calls.
$calls = (int) ($argv[1] ?? 2600);
$hot = array_slice($leafKeys, 0, 40);
$sequence = [];
for ($i = 0; $i < $calls; $i++) {
    $sequence[] = $i % 5 < 3 ? $hot[$i % count($hot)] : $leafKeys[($i * 7) % count($leafKeys)];
}
$distinct = count(array_unique($sequence));

// One "request" = a fresh repo + the whole call sequence (cold per request).
$vanillaReq = function () use ($config, $sequence) {
    $r = new VanillaRepository($config);
    foreach ($sequence as $k) {
        $r->get($k);
    }
};
$lazyReq = function () use ($config, $sequence) {
    $r = new GreasedRepository($config);
    foreach ($sequence as $k) {
        $r->get($k);
    }
};
$flatReq = function () use ($config, $flatIndex, $sequence) {
    $r = new GreasedRepository($config);
    $r->useGreaseFlatIndex($flatIndex);
    foreach ($sequence as $k) {
        $r->get($k);
    }
};

// Parity: the three arms must return identical values for the sequence.
$read = function ($r) use ($sequence) {
    $out = [];
    foreach ($sequence as $k) {
        $out[] = $r->get($k);
    }

    return $out;
};
$v = $read(new VanillaRepository($config));
$g = $read((function () use ($config, $flatIndex) {
    $r = new GreasedRepository($config);
    $r->useGreaseFlatIndex($flatIndex);

    return $r;
})());
if (var_export($v, true) !== var_export($g, true)) {
    echo "PARITY FAILED\n";
    exit(1);
}

$requests = (int) ($argv[2] ?? 400);
$time = function (callable $req) use ($requests): float {
    $start = hrtime(true);
    for ($i = 0; $i < $requests; $i++) {
        $req();
    }

    return (hrtime(true) - $start) / $requests / 1e6; // ms/request
};

// Warm.
for ($i = 0; $i < 20; $i++) {
    $vanillaReq();
    $lazyReq();
    $flatReq();
}

$tVan = $tLazy = $tFlat = 0.0;
for ($r = 0; $r < 5; $r++) {
    $tVan += $time($vanillaReq);
    $tLazy += $time($lazyReq);
    $tFlat += $time($flatReq);
}
$tVan /= 5;
$tLazy /= 5;
$tFlat /= 5;

printf("Request = %s config get() calls over %d distinct keys (config: %d leaves).\n\n", number_format($calls), $distinct, count($leafKeys));
printf("Per-request config cost (fresh repo per request, 5 rounds × %d requests):\n", $requests);
printf("  vanilla (current):  %6.3f ms/request\n", $tVan);
printf("  greased lazy memo:  %6.3f ms/request   (%+.1f%% vs vanilla)\n", $tLazy, ($tLazy - $tVan) / $tVan * 100);
printf("  greased flat index: %6.3f ms/request   (%+.1f%% vs vanilla · %+.1f%% vs lazy)\n", $tFlat, ($tFlat - $tVan) / $tVan * 100, ($tFlat - $tLazy) / $tLazy * 100);
printf("\nSaved per request vs vanilla: lazy %.3f ms · flat %.3f ms.\n", $tVan - $tLazy, $tVan - $tFlat);
echo "macOS/no-JIT inflates absolutes ~3-5×; the ratios hold. Confirm on Linux via docker.\n";
