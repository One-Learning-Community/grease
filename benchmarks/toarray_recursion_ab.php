<?php

/**
 * Tier-isolated A/B for the relation-less toArray() recursion-guard short-circuit in
 * HasGreasedSerialization.
 *
 * Vanilla wraps toArray() in withoutRecursion() → debug_backtrace + Onceable hash + WeakMap
 * on every call. With no relations loaded the guard is dead weight (relationsToArray() is []).
 *
 *   A = HasGrease with toArray() forced to vanilla (model overrides it → parent::toArray)
 *   B = HasGrease (the short-circuit live)
 *
 * Workload: User::query()->limit(N)->get()->toArray() with rich casts and NO eager load
 * (relation-less — where the guard is pure overhead). Parity gated before timing.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/toarray_recursion_ab.php [rows] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

$rows = (int) ($argv[1] ?? 100);
$iters = (int) ($argv[2] ?? 6000);

$capsule = BootsEloquent::capsule();
$capsule->schema()->create('users', function (Blueprint $t) {
    $t->increments('id');
    $t->string('name');
    $t->string('email');
    $t->integer('age');
    $t->boolean('is_active');
    $t->decimal('score', 8, 2);
    $t->text('settings');
    $t->dateTime('email_verified_at')->nullable();
    $t->timestamps();
});

class TRVanilla extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];
}
// A: HasGrease but toArray() forced back to vanilla (class method shadows the trait's).
class TRA extends Model
{
    use HasGrease;

    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];

    public function toArray()
    {
        return parent::toArray();
    }
}
// B: HasGrease (short-circuit live).
class TRB extends Model
{
    use HasGrease;

    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];
}

$now = '2026-01-01 00:00:00';
$seed = [];
for ($u = 1; $u <= 300; $u++) {
    $seed[] = ['name' => "User $u", 'email' => "user$u@example.test", 'age' => 18 + ($u % 60), 'is_active' => $u % 2, 'score' => number_format(($u % 100) + 0.5, 2, '.', ''), 'settings' => '{"theme":"dark"}', 'email_verified_at' => $u % 3 ? $now : null, 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($seed, 500) as $c) {
    $capsule->table('users')->insert($c);
}

Carbon::setTestNow('2026-01-01 12:00:00');

$oracle = TRVanilla::query()->limit($rows)->get()->toArray();
$a = TRA::query()->limit($rows)->get()->toArray();
$b = TRB::query()->limit($rows)->get()->toArray();

if (json_encode($a) !== json_encode($oracle) || json_encode($b) !== json_encode($oracle)) {
    fwrite(STDERR, "PARITY FAIL: toArray short-circuit diverged from vanilla\n");
    exit(1);
}
echo "parity ✔ (vanilla-toArray and short-circuit toArray() == vanilla oracle)\n";

$bench = function (string $class) use ($rows, $iters) {
    $class::query()->limit($rows)->get()->toArray();
    gc_collect_cycles();
    $t0 = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $class::query()->limit($rows)->get()->toArray();
    }

    return (hrtime(true) - $t0) / 1e9;
};

echo "{$iters}× User::query()->limit($rows)->get()->toArray()  (rich casts, NO relations):\n";
$ta = $tb = 0.0;
$repeats = 3;
for ($r = 0; $r < $repeats; $r++) {
    $da = $bench(TRA::class);
    $db = $bench(TRB::class);
    printf("  #%d  A vanilla-toArray %7.3f s   B short-circuit %7.3f s   Δ %+.1f%%\n", $r, $da, $db, ($db - $da) / $da * 100);
    $ta += $da;
    $tb += $db;
}
printf("\nshort-circuit vs vanilla-toArray:   %+.1f%%   (mean of %d repeats)\n", ($tb - $ta) / $ta * 100, $repeats);

Carbon::setTestNow();
