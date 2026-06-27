<?php

/**
 * Tier-isolated A/B for the decimal-cast identity short-circuit in HasGreasedDecimalCasts.
 *
 * `decimal:N` casts run `asDecimal($v,$N)` = (string) BigDecimal::of($v)->toScale($N, HalfUp)
 * — Brick\Math, per cast per row, on every read. The trait returns an already-canonical scaled
 * string verbatim (toScale is a no-op) and defers everything else to Brick.
 *
 * Fixture = a real TRANSACTIONS/ledger row: several decimal columns (amount/fee/tax/balance),
 * no JSON column (money tables don't carry one), the usual fk/strings/timestamps. That's where
 * decimal casts actually cluster — a User-with-a-`settings`-json row is the wrong test (its
 * json_decode + datetime dwarf the decimals). Reported as TWO arms, because the win is
 * conditional:
 *
 *   A) plain model        — vanilla vs +HasGreasedDecimalCasts. Small: the datetime Carbon cost
 *                           still dominates the un-greased row, so decimal is a thin slice.
 *   B) greased model      — HasGrease vs HasGrease+HasGreasedDecimalCasts. The REAL deploy: with
 *                           the datetime serialization tier already removing the Carbon cost,
 *                           decimal is a much bigger share, so the trait compounds. This is the
 *                           number that matters — nobody opts into the decimal trait for perf
 *                           without HasGrease underneath.
 *
 * IMPORTANT — the fast path only fires when the driver returns a canonical decimal STRING.
 * MySQL (DECIMAL) and PostgreSQL (NUMERIC) both do — that's where money lives. SQLite returns
 * `decimal` columns as a float, so it defers there (byte-identical, no win) — which is why a
 * SQLite-backed bench reads ~0. We hydrate from canonical strings directly (the MySQL/PG return
 * shape). Correctness is driver-independent; only the WIN is driver-dependent.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/decimal_ab.php [rows] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedDecimalCasts;
use Illuminate\Database\Eloquent\Model;

$rows = (int) ($argv[1] ?? 100);
$iters = (int) ($argv[2] ?? 400);

// A connection so the datetime cast can resolve getDateFormat (no tables; rows are literal).
BootsEloquent::capsule();

$CASTS = ['account_id' => 'integer', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'tax' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

$raw = [];
for ($i = 1; $i <= $rows; $i++) {
    $raw[] = [
        'id' => $i,
        'account_id' => (string) (1000 + $i),
        'amount' => number_format(($i % 10000) + ($i % 100) / 100, 2, '.', ''),
        'fee' => number_format(($i % 50) + 0.50, 2, '.', ''),
        'tax' => number_format(($i % 200) + 0.25, 2, '.', ''),
        'balance_after' => number_format(($i * 7 % 90000) + 0.00, 2, '.', ''),
        'currency' => 'USD',
        'type' => $i % 2 ? 'charge' : 'refund',
        'status' => 'settled',
        'reference' => 'ch_'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ];
}

class TxVanilla extends Model
{
    protected $table = 'transactions';

    protected $casts = ['account_id' => 'integer', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'tax' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

class TxDecimal extends Model
{
    use HasGreasedDecimalCasts;

    protected $table = 'transactions';

    protected $casts = ['account_id' => 'integer', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'tax' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

class TxGrease extends Model
{
    use HasGrease;

    protected $table = 'transactions';

    protected $casts = ['account_id' => 'integer', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'tax' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

class TxGreaseDecimal extends Model
{
    use HasGrease;
    use HasGreasedDecimalCasts;

    protected $table = 'transactions';

    protected $casts = ['account_id' => 'integer', 'amount' => 'decimal:2', 'fee' => 'decimal:2', 'tax' => 'decimal:2', 'balance_after' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

// --- Parity gate: every variant byte-identical to vanilla before timing. ---
$oracle = array_map(fn ($r) => (new TxVanilla)->newFromBuilder($r)->toArray(), $raw);
foreach (['TxDecimal', 'TxGrease', 'TxGreaseDecimal'] as $cls) {
    $got = array_map(fn ($r) => (new $cls)->newFromBuilder($r)->toArray(), $raw);
    if (json_encode($oracle) !== json_encode($got)) {
        fwrite(STDERR, "PARITY FAIL — $cls toArray differs from vanilla. Refusing to time.\n");
        exit(1);
    }
}
echo "Parity: OK — $rows transaction rows byte-identical across all variants (4 decimals, no json).\n";

// min-of-N: single-run means are noise (drift alone flips a small signal's sign). Each timed
// iteration hydrates FRESH instances (the real-request shape — decimal casts aren't cached).
$bench = function (string $class) use ($iters, $raw): float {
    $proto = new $class;
    foreach ($raw as $r) {
        $proto->newFromBuilder($r)->toArray(); // warm opcache/JIT
    }
    $best = PHP_FLOAT_MAX;
    for ($rep = 0; $rep < 5; $rep++) {
        $t0 = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            foreach ($raw as $r) {
                $proto->newFromBuilder($r)->toArray();
            }
        }
        $best = min($best, (hrtime(true) - $t0) / $iters / max(1, count($raw)));
    }

    return $best; // ns per fresh row-toArray
};

$tv = $bench(TxVanilla::class);
$td = $bench(TxDecimal::class);
$tg = $bench(TxGrease::class);
$tgd = $bench(TxGreaseDecimal::class);

echo str_repeat('-', 70)."\n";
printf("A) plain model:   vanilla %8.0f ns   +decimal %8.0f ns   (%+.1f%%)\n", $tv, $td, ($td - $tv) / $tv * 100);
printf("B) greased model: HasGrease %8.0f ns   +decimal %8.0f ns   (%+.1f%%)  <- real deploy\n", $tg, $tgd, ($tgd - $tg) / $tg * 100);
printf("   HasGrease alone vs vanilla: %+.1f%%   |   per decimal column saved: ~%.0f ns\n", ($tg - $tv) / $tv * 100, ($tg - $tgd) / 4);
echo "\nmacOS distorts magnitudes — Linux via benchmarks/docker is canonical.\n";
