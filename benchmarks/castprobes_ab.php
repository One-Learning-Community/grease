<?php

/**
 * Tier-isolated throughput A/B for HasGreasedCastProbes — memoizing the per-key cast
 * classification probes (isEnumCastable / isClassCastable / isClassSerializable) that
 * addCastAttributesToArray runs on every row during toArray().
 *
 *   A = the six prior tiers (HasGrease *without* HasGreasedCastProbes)
 *   B = all seven tiers       (HasGrease)
 *
 * Models carry the realworld rich-cast shape (boolean / decimal:2 / array / datetime), and
 * the workload is the index_users endpoint: query()->limit(N)->get()->toArray(). Parity is
 * gated before timing (B's toArray() byte-identical to A's and to a vanilla oracle).
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/castprobes_ab.php [rows] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGreasedAttributes;
use Grease\Concerns\HasGreasedCastProbes;
use Grease\Concerns\HasGreasedCasts;
use Grease\Concerns\HasGreasedClassAttributes;
use Grease\Concerns\HasGreasedHydration;
use Grease\Concerns\HasGreasedInitializers;
use Grease\Concerns\HasGreasedSerialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

$rows = (int) ($argv[1] ?? 100);
$iters = (int) ($argv[2] ?? 6000);

// A: six tiers, no cast-probe memoization.
trait HasGreasePrior6
{
    use HasGreasedAttributes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedInitializers;
    use HasGreasedSerialization;
}

// B: all seven (prior six + cast-probe memoization).
trait HasGreaseFull7
{
    use HasGreasedAttributes;
    use HasGreasedCastProbes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedInitializers;
    use HasGreasedSerialization;
}

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

class CPVanilla extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];
}
class CPA extends Model
{
    use HasGreasePrior6;

    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];
}
class CPB extends Model
{
    use HasGreaseFull7;

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

$oracle = CPVanilla::query()->limit($rows)->get()->toArray();
$a = CPA::query()->limit($rows)->get()->toArray();
$b = CPB::query()->limit($rows)->get()->toArray();

if ($a !== $oracle || $b !== $oracle) {
    fwrite(STDERR, "PARITY FAIL: cast-probe arm diverged from vanilla\n");
    exit(1);
}
echo "parity ✔ (prior-6 and full-7 toArray() == vanilla oracle)\n";

$bench = function (string $class) use ($rows, $iters) {
    $class::query()->limit($rows)->get()->toArray(); // warm
    gc_collect_cycles();
    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $iters; $i++) {
        $sink += count($class::query()->limit($rows)->get()->toArray());
    }

    return (hrtime(true) - $t0) / 1e9;
};

echo "{$iters}× User::query()->limit($rows)->get()->toArray()  (rich casts):\n";
$ta = $tb = 0.0;
$repeats = 3;
for ($r = 0; $r < $repeats; $r++) {
    $da = $bench(CPA::class);
    $db = $bench(CPB::class);
    printf("  #%d  A prior-6 %7.3f s   B full-7 %7.3f s   Δ %+.1f%%\n", $r, $da, $db, ($db - $da) / $da * 100);
    $ta += $da;
    $tb += $db;
}
printf("\nfull-7 vs prior-6 (cast-probe memo on vs off):   %+.1f%%   (mean of %d repeats)\n", ($tb - $ta) / $ta * 100, $repeats);

Carbon::setTestNow();
