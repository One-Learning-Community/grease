<?php

/**
 * Tier-isolated throughput A/B for the empty-fill short-circuit in HasGreasedHydration.
 *
 * Every `new` model — and so every hydrated row, via `newFromBuilder`'s `new static` →
 * `__construct` → `fill([])` — runs `fill([])`, which still computes `totallyGuarded()` and
 * `fillableFromArray([])` before looping over nothing. Once the resolveClassAttribute calls
 * are frozen out, that up-front `totallyGuarded()` is the dominant self-time frame on the
 * eager profile. The short-circuit returns `$this` on `[]` (byte-identical — `fill([])` is a
 * pure no-op). This measures what skipping it is worth.
 *
 *   A = full HasGrease, but fill() forced to vanilla (model overrides it → parent::fill)
 *   B = full HasGrease (the trait short-circuit live)
 *
 * Both run the identical eager get() over identical data; parity gated before timing.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/fill_ab.php [users] [posts] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

$nUsers = (int) ($argv[1] ?? 100);
$nPosts = (int) ($argv[2] ?? 20);
$iters = (int) ($argv[3] ?? 4000);

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
class FVUser extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(FVPost::class, 'user_id');
    }
}
class FVPost extends Model
{
    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];
}

// --- A: full HasGrease, fill() forced back to vanilla (class method shadows the trait's) ---
class FAUser extends Model
{
    use HasGrease;

    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(FAPost::class, 'user_id');
    }

    public function fill(array $attributes)
    {
        return parent::fill($attributes);
    }
}
class FAPost extends Model
{
    use HasGrease;

    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];

    public function fill(array $attributes)
    {
        return parent::fill($attributes);
    }
}

// --- B: full HasGrease (trait short-circuit live) ---
class FBUser extends Model
{
    use HasGrease;

    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(FBPost::class, 'user_id');
    }
}
class FBPost extends Model
{
    use HasGrease;

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

// --- Parity gate: A == B == vanilla, byte for byte ---
$oracle = FVUser::with('posts')->get()->toArray();
$a = FAUser::with('posts')->get()->toArray();
$b = FBUser::with('posts')->get()->toArray();

if ($a !== $oracle || $b !== $oracle) {
    fwrite(STDERR, "PARITY FAIL: greased eager get() diverged from vanilla\n");
    exit(1);
}
echo "parity ✔ (vanilla-fill and short-circuit-fill toArray() == vanilla oracle)\n";

$bench = function (callable $query) use ($iters) {
    $query();
    gc_collect_cycles();
    $t0 = hrtime(true);
    $sink = 0;
    for ($i = 0; $i < $iters; $i++) {
        $sink += count($query());
    }

    return [(hrtime(true) - $t0) / 1e9, $sink];
};

echo "{$iters}× User::with('posts')->get()  ($nUsers users × $nPosts posts = ".($nUsers * $nPosts)." children):\n";
$ta = $tb = 0.0;
$repeats = 3;
for ($r = 0; $r < $repeats; $r++) {
    [$da] = $bench(fn () => FAUser::with('posts')->get());
    [$db] = $bench(fn () => FBUser::with('posts')->get());
    printf("  #%d  A vanilla-fill %7.3f s   B short-circuit %7.3f s   Δ %+.1f%%\n", $r, $da, $db, ($db - $da) / $da * 100);
    $ta += $da;
    $tb += $db;
}
printf("\nshort-circuit vs vanilla-fill:   %+.1f%%   (mean of %d repeats)\n", ($tb - $ta) / $ta * 100, $repeats);
