<?php

/**
 * Grease config-repository memo — tier-isolated A/B + parity gate.
 *
 * Vanilla `Illuminate\Config\Repository` vs `Grease\Config\Repository`. The config repo is a
 * single long-lived singleton, so unlike the request bench this hammers ONE instance with a
 * realistic per-request read mix repeatedly — the same handful of keys the framework reads
 * from all over (the `config('app.x')` multiplier). The greased arm pays one dot-walk per
 * key, then serves hash hits; warm, that's the steady-state read cost a worker pays once the
 * memo is built — within a request, under FPM and Octane alike (Octane sandboxes config by
 * cloning it per request, so the memo amortizes per request, not across them). Parity-gated
 * before timing — including the null-value, missing-with-default, closure-default, getMany,
 * and write-invalidation edges.
 *
 * BASELINE = `config:cache` (the production standard). This is deliberate: `config:cache`
 * optimizes BOOT (it pre-merges the config files into one opcached array), but it does NOT
 * touch the runtime read path — `LoadConfiguration` still builds a plain `Repository` over a
 * nested array, and `get()` → `Arr::get()` dot-walks on every read regardless. So we construct
 * the repository from an already-merged `$items` array (exactly what `config:cache` produces)
 * and time reads only — no merge in the loop. The delta below is the win ON TOP OF
 * `config:cache`, on the read path it leaves untouched. (The same within-request win applies
 * under Octane — it clones config per request, so the memo amortizes per request just as under
 * FPM, not across requests.)
 *
 *   php benchmarks/config_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Config\Repository as GreasedRepository;
use Illuminate\Config\Repository as VanillaRepository;

/** A realistic loaded-config tree: a few files, nested 2–3 deep. */
function configItems(): array
{
    return [
        'app' => [
            'name' => 'Grease', 'env' => 'production', 'debug' => false,
            'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en',
            'key' => 'base64:abcdef', 'cipher' => 'AES-256-CBC', 'url' => 'http://localhost',
            'maintenance' => ['driver' => 'file'],
            'providers' => ['A', 'B', 'C'],
        ],
        'database' => [
            'default' => 'mysql',
            'connections' => [
                'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'forge', 'prefix' => ''],
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
            'redis' => ['default' => ['host' => '127.0.0.1', 'port' => 6379]],
        ],
        'cache' => ['default' => 'redis', 'prefix' => 'grease_cache', 'stores' => ['redis' => ['driver' => 'redis']]],
        'session' => ['driver' => 'redis', 'lifetime' => 120, 'expire_on_close' => false, 'cookie' => 'grease_session'],
        'logging' => ['default' => 'stack', 'channels' => ['stack' => ['driver' => 'stack']]],
        'mail' => ['default' => 'smtp', 'from' => ['address' => 'hello@example.com', 'name' => 'Grease']],
        'queue' => ['default' => 'redis', 'connections' => ['redis' => ['driver' => 'redis', 'queue' => 'default']]],
        'services' => ['stripe' => ['key' => null, 'secret' => null]], // genuinely-null stored values
    ];
}

/**
 * The per-request read mix — the keys a real request touches many times from across the
 * framework. Returns a canonical result vector (also the parity oracle). Mixes deep keys,
 * top-level files, a stored null, missing keys with scalar + closure defaults, and getMany.
 */
function readMix($c): array
{
    return [
        $c->get('app.name'),
        $c->get('app.env'),
        $c->get('app.debug'),
        $c->get('app.timezone'),
        $c->get('app.locale'),
        $c->get('database.default'),
        $c->get('database.connections.mysql.host'),
        $c->get('database.connections.mysql.port'),
        $c->get('cache.default'),
        $c->get('cache.prefix'),
        $c->get('session.lifetime'),
        $c->get('session.driver'),
        $c->get('logging.default'),
        $c->get('queue.default'),
        $c->get('mail.from.address'),
        $c->get('services.stripe.key'),                 // stored null — must memoize as null
        $c->get('app.missing_flag', 'fallback'),         // missing + scalar default — never memoized
        $c->get('database.connections.pgsql', fn () => ['driver' => 'pgsql']), // missing + closure default
        $c->get(['app.name', 'cache.default']),          // getMany (array key)
        $c->has('app.debug'),
        $c->get('app.providers'),
    ];
}

// ---- Parity gate ----------------------------------------------------------------

$vanRepo = new VanillaRepository(configItems());
$greRepo = new GreasedRepository(configItems());

if (var_export(readMix($vanRepo), true) !== var_export(readMix($greRepo), true)) {
    echo "PARITY FAILED — read mix differs between vanilla and greased.\n";
    exit(1);
}

// Write-invalidation parity: a set() must drop the stale memo, and prepend/push/offsetSet/
// offsetUnset all funnel through it. Read (warm the memo), mutate, re-read — must still match.
foreach (['set', 'push', 'prepend', 'offsetSet', 'offsetUnset'] as $op) {
    $van = new VanillaRepository(configItems());
    $gre = new GreasedRepository(configItems());
    readMix($van);
    readMix($gre); // warm both
    foreach ([$van, $gre] as $c) {
        match ($op) {
            'set' => $c->set('app.name', 'Changed'),
            'push' => $c->push('app.providers', 'D'),
            'prepend' => $c->prepend('app.providers', 'Z'),
            'offsetSet' => $c['cache.default'] = 'file',
            'offsetUnset' => $c->offsetUnset('app.debug'),
        };
    }
    if (var_export(readMix($van), true) !== var_export(readMix($gre), true)) {
        echo "PARITY FAILED — post-$op re-read differs (stale memo).\n";
        exit(1);
    }
}

echo 'Parity: OK ('.count(readMix($vanRepo))." reads identical + 5 write-invalidation paths)\n";

// ---- Benchmark ------------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 200_000);

// One persistent instance per arm (config is a singleton); warm autoload + the greased memo.
$vanRepo = new VanillaRepository(configItems());
$greRepo = new GreasedRepository(configItems());
for ($i = 0; $i < 2000; $i++) {
    readMix($vanRepo);
    readMix($greRepo);
}

function timeArm($repo, int $iterations): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        readMix($repo);
    }

    return (hrtime(true) - $start) / 1e9;
}

$rounds = 5;
$vanTotal = 0.0;
$greTotal = 0.0;

for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $vanTotal += timeArm($vanRepo, $iterations);
        $greTotal += timeArm($greRepo, $iterations);
    } else {
        $greTotal += timeArm($greRepo, $iterations);
        $vanTotal += timeArm($vanRepo, $iterations);
    }
}

$van = $vanTotal / $rounds;
$gre = $greTotal / $rounds;
$delta = ($gre - $van) / $van * 100;

printf("\nPersistent repo + ~21-read mix, %s iters × %d rounds:\n", number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/mix)\n", $van, $van / $iterations * 1e6);
printf("  greased: %.4f s  (%.3f µs/mix)\n", $gre, $gre / $iterations * 1e6);
printf("  delta:   %+.1f%%\n", $delta);
echo "\nWarm steady-state config-read cost — what a worker pays once the memo is built, within a\n";
echo "request (FPM and Octane alike; Octane clones config per request). Baseline is a config:cache'd\n";
echo "(pre-merged) repo, so this is the win on top of the production standard. macOS — confirm on Linux.\n";
