<?php

/**
 * Tier-isolated A/B for the decimal-cast identity short-circuit in HasGreasedDecimalCasts.
 *
 * `decimal:N` casts run `asDecimal($v,$N)` = (string) BigDecimal::of($v)->toScale($N, HalfUp)
 * — Brick\Math, per cast per row, on every read. The trait returns an already-canonical scaled
 * string verbatim (toScale is a no-op) and defers everything else to Brick. The stack_excimer
 * profile surfaced this at ~5-7% inclusive of a decimal-heavy serialize.
 *
 *   A = plain model (vanilla asDecimal → Brick every row)
 *   B = model with HasGreasedDecimalCasts (the fast path live)
 *
 * Workload: hydrate N rows with three decimal:2 columns and serialize them — get()->toArray(),
 * the shape the profile flagged. Parity-gated: every row's toArray() must byte-match vanilla
 * before timing.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/decimal_ab.php [rows] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Concerns\HasGreasedDecimalCasts;
use Illuminate\Database\Eloquent\Model;

$rows = (int) ($argv[1] ?? 100);
$iters = (int) ($argv[2] ?? 3000);

// IMPORTANT — the fast path only fires when the driver returns a canonical decimal STRING.
// MySQL (DECIMAL) and PostgreSQL (NUMERIC) both do — that's where money lives. SQLite returns
// `decimal` columns as a float, so the fast path defers there (byte-identical, just no win) —
// which is why a SQLite-backed bench would understate this to ~0. We therefore hydrate from
// canonical strings directly (the MySQL/PG return shape), NOT through SQLite. Correctness is
// driver-independent (proven in the parity suite); only the WIN is driver-dependent.
$raw = [];
for ($i = 1; $i <= $rows; $i++) {
    $raw[] = [
        'id' => $i,
        'ref' => "INV-$i",
        'price' => number_format(($i % 1000) + ($i % 100) / 100, 2, '.', ''),
        'tax' => number_format(($i % 50) + 0.50, 2, '.', ''),
        'total' => number_format(($i % 1000) + ($i % 50) + 0.99, 2, '.', ''),
    ];
}

class VanillaInvoice extends Model
{
    public $timestamps = false;

    protected $table = 'invoices';

    protected $casts = ['price' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
}

class GreasedInvoice extends Model
{
    use HasGreasedDecimalCasts;

    public $timestamps = false;

    protected $table = 'invoices';

    protected $casts = ['price' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
}

// --- Parity gate: every row byte-identical before we time anything. ---
$probeV = array_map(fn ($r) => (new VanillaInvoice)->newFromBuilder($r)->toArray(), $raw);
$probeG = array_map(fn ($r) => (new GreasedInvoice)->newFromBuilder($r)->toArray(), $raw);
if (json_encode($probeV) !== json_encode($probeG)) {
    fwrite(STDERR, "PARITY FAIL — greased toArray differs from vanilla. Refusing to time.\n");
    exit(1);
}
echo "Parity: OK — $rows rows byte-identical (3 decimal:2 columns each, canonical-string source = MySQL/PG shape).\n";

// Each timed iteration hydrates FRESH instances via newFromBuilder — the real-request shape (a
// request hydrates new models, so the decimal cast is paid every time, not served from a reused
// instance). Decimal casts are NOT instance-cached, but reusing instances still skews timing.

// min-of-N: single-run means are noise on macOS (drift alone flips a small signal's sign).
// The minimum over several reps is the least-contended, most-reproducible estimate.
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

// Interleave the arms so any slow stretch hits both, not one.
$nsV = $nsG = PHP_FLOAT_MAX;
for ($round = 0; $round < 2; $round++) {
    $nsV = min($nsV, $bench(VanillaInvoice::class));
    $nsG = min($nsG, $bench(GreasedInvoice::class));
}

echo str_repeat('-', 64)."\n";
printf("vanilla (Brick):  %8.1f ns / row toArray\n", $nsV);
printf("greased:          %8.1f ns / row toArray\n", $nsG);
printf("delta:            %+.1f%%\n", ($nsG - $nsV) / $nsV * 100);
echo "\nmacOS distorts magnitudes — reproduce on Linux via benchmarks/docker.\n";
