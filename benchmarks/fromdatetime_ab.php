<?php

/**
 * Tier-isolated A/B for the fromDateTime() identity short-circuit in HasGreasedSerialization.
 *
 * fromDateTime($value) = asDateTime($value)->format(getDateFormat()). On save(),
 * originalIsEquivalent() compares a date column as fromDateTime($attr) === fromDateTime($original)
 * where $original is the raw stored string — so vanilla PARSES that string into Carbon and formats
 * it straight back to the identical string. The short-circuit returns a certified storage-format
 * string as-is.
 *
 *   A = HasGrease with fromDateTime() forced to vanilla (model override → parent::fromDateTime)
 *   B = HasGrease (the fast path live)
 *
 * Workload: the bulk_update shape — get N rows, mutate, save (rolled back). Parity gated.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/fromdatetime_ab.php [rows] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

$rows = (int) ($argv[1] ?? 150);
$iters = (int) ($argv[2] ?? 1500);

$capsule = BootsEloquent::capsule();
$conn = $capsule->getConnection();
$capsule->schema()->create('users', function (Blueprint $t) {
    $t->increments('id');
    $t->string('name');
    $t->integer('age');
    $t->decimal('score', 8, 2);
    $t->dateTime('email_verified_at')->nullable();
    $t->timestamps();
});

class FDVanilla extends Model
{
    protected $table = 'users';
    protected $casts = ['age' => 'integer', 'score' => 'decimal:2', 'email_verified_at' => 'datetime'];
}
// A: HasGrease but fromDateTime() forced back to vanilla (class method shadows the trait's).
class FDA extends Model
{
    use HasGrease;
    protected $table = 'users';
    protected $casts = ['age' => 'integer', 'score' => 'decimal:2', 'email_verified_at' => 'datetime'];
    public function fromDateTime($value) { return parent::fromDateTime($value); }
}
// B: HasGrease (fast path live).
class FDB extends Model
{
    use HasGrease;
    protected $table = 'users';
    protected $casts = ['age' => 'integer', 'score' => 'decimal:2', 'email_verified_at' => 'datetime'];
}

$now = '2026-01-01 00:00:00';
$seed = [];
for ($u = 1; $u <= 300; $u++) {
    $seed[] = ['name' => "User $u", 'age' => 18 + ($u % 60), 'score' => number_format(($u % 100) + 0.5, 2, '.', ''), 'email_verified_at' => $u % 3 ? $now : null, 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($seed, 500) as $c) {
    $capsule->table('users')->insert($c);
}

\Illuminate\Support\Carbon::setTestNow('2026-01-01 12:00:00');

// Parity: the dirty set + stored output of a mutate+save must match vanilla, byte for byte.
$run = function (string $class) use ($rows, $conn) {
    $conn->beginTransaction();
    try {
        $dirty = [];
        $class::query()->limit($rows)->get()->each(function ($u) use (&$dirty) {
            $u->score = $u->score + 1;
            $dirty[] = $u->getDirty();
            $u->save();
        });

        return $dirty;
    } finally {
        $conn->rollBack();
    }
};

if (json_encode($run(FDA::class)) !== json_encode($run(FDVanilla::class))
    || json_encode($run(FDB::class)) !== json_encode($run(FDVanilla::class))) {
    fwrite(STDERR, "PARITY FAIL: fromDateTime short-circuit diverged from vanilla getDirty\n");
    exit(1);
}
echo "parity ✔ (vanilla-fromDateTime and fast-path getDirty == vanilla oracle)\n";

$bench = function (string $class) use ($rows, $iters, $conn) {
    $warm = function () use ($class, $rows, $conn) {
        $conn->beginTransaction();
        try {
            $class::query()->limit($rows)->get()->each(function ($u) {
                $u->score = $u->score + 1;
                $u->save();
            });
        } finally {
            $conn->rollBack();
        }
    };
    $warm();
    gc_collect_cycles();
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $warm();
    }
    return (hrtime(true) - $t0) / 1e9;
};

echo "{$iters}× bulk_update($rows rows: get → score+1 → save, rolled back):\n";
$ta = $tb = 0.0;
$repeats = 3;
for ($r = 0; $r < $repeats; $r++) {
    $da = $bench(FDA::class);
    $db = $bench(FDB::class);
    printf("  #%d  A vanilla-fromDateTime %7.3f s   B fast-path %7.3f s   Δ %+.1f%%\n", $r, $da, $db, ($db - $da) / $da * 100);
    $ta += $da;
    $tb += $db;
}
printf("\nfast-path vs vanilla-fromDateTime:   %+.1f%%   (mean of %d repeats)\n", ($tb - $ta) / $ta * 100, $repeats);

\Illuminate\Support\Carbon::setTestNow();
