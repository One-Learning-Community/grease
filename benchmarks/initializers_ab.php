<?php

/**
 * Tier-isolated throughput A/B for HasGreasedInitializers — the per-class freeze of the
 * four surviving trait booters (guards / hides / timestamps / touches) that still call
 * resolveClassAttribute on every warm instance.
 *
 *   A = the prior five tiers (HasGrease *without* HasGreasedInitializers)
 *   B = all six tiers       (HasGrease, the freeze on)
 *
 * Both run the identical eager get() over the identical seeded data; the *only* difference
 * is the freeze. Parity is gated before timing: B's toArray() must be byte-identical to A's
 * (and to a vanilla oracle), or the bench aborts. The eager profile (eager_excimer.php) shows
 * resolveClassAttribute drops out of the top frames with the freeze on; this measures what
 * that's worth end-to-end on the hydrate path.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/initializers_ab.php [users] [posts] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGreasedAttributes;
use Grease\Concerns\HasGreasedCasts;
use Grease\Concerns\HasGreasedClassAttributes;
use Grease\Concerns\HasGreasedHydration;
use Grease\Concerns\HasGreasedInitializers;
use Grease\Concerns\HasGreasedSerialization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

$nUsers = (int) ($argv[1] ?? 100);
$nPosts = (int) ($argv[2] ?? 20);
$iters = (int) ($argv[3] ?? 4000);

// A: the five tiers shipped before this one — everything HasGrease has MINUS the freeze.
trait HasGreasePrior
{
    use HasGreasedAttributes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedSerialization;
}

// B: the full six-tier stack (prior five + the booter freeze).
trait HasGreaseFull
{
    use HasGreasedAttributes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedInitializers;
    use HasGreasedSerialization;
}

$capsule = BootsEloquent::capsule();
$schema = $capsule->schema();

$schema->create('users', function (Blueprint $t) {
    $t->increments('id');
    $t->string('name');
    $t->integer('age');
    $t->timestamps();
});
$schema->create('posts', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('user_id');
    $t->string('title');
    $t->integer('view_count');
    $t->timestamps();
});

// --- Vanilla oracle ---
class VUser extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(VPost::class, 'user_id');
    }
}
class VPost extends Model
{
    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];
}

// --- A: prior five tiers ---
class AUser extends Model
{
    use HasGreasePrior;

    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(APost::class, 'user_id');
    }
}
class APost extends Model
{
    use HasGreasePrior;

    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];
}

// --- B: all six tiers ---
class BUser extends Model
{
    use HasGreaseFull;

    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(BPost::class, 'user_id');
    }
}
class BPost extends Model
{
    use HasGreaseFull;

    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];
}

$now = '2026-01-01 00:00:00';
$rows = [];
for ($u = 1; $u <= $nUsers; $u++) {
    $rows[] = ['name' => "User $u", 'age' => 18 + ($u % 60), 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($rows, 500) as $c) {
    $capsule->table('users')->insert($c);
}
$rows = [];
for ($u = 1; $u <= $nUsers; $u++) {
    for ($p = 0; $p < $nPosts; $p++) {
        $rows[] = ['user_id' => $u, 'title' => "Post $p of $u", 'view_count' => $p, 'created_at' => $now, 'updated_at' => $now];
    }
}
foreach (array_chunk($rows, 1000) as $c) {
    $capsule->table('posts')->insert($c);
}

// --- Parity gate: B == A == vanilla, byte for byte ---
$oracle = VUser::with('posts')->get()->toArray();
$a = AUser::with('posts')->get()->toArray();
$b = BUser::with('posts')->get()->toArray();

if ($a !== $oracle || $b !== $oracle) {
    fwrite(STDERR, "PARITY FAIL: greased eager get() diverged from vanilla\n");
    exit(1);
}
echo "parity ✔ (prior-5 and full-6 toArray() == vanilla oracle)\n";

$bench = function (string $label, callable $query) use ($iters) {
    // warm
    $query();
    gc_collect_cycles();
    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $iters; $i++) {
        $sink += count($query());
    }
    $dt = (hrtime(true) - $t0) / 1e9;
    printf("  %-16s %7.3f s   (%.1f get()/s)   sink=%d\n", $label, $dt, $iters / $dt, $sink);

    return $dt;
};

echo "{$iters}× User::with('posts')->get()  ($nUsers users × $nPosts posts = ".($nUsers * $nPosts)." children):\n";
// Interleave A/B/A/B across repeats to average out thermal drift.
$ta = $tb = 0.0;
$repeats = 3;
for ($r = 0; $r < $repeats; $r++) {
    $ta += $bench("A prior-5  #$r", fn () => AUser::with('posts')->get());
    $tb += $bench("B full-6   #$r", fn () => BUser::with('posts')->get());
}
printf("\nfull-6 vs prior-5 (freeze on vs off):   %+.1f%%   (mean of %d repeats)\n", ($tb - $ta) / $ta * 100, $repeats);
