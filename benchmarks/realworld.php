<?php

/**
 * Grease real-world benchmark — and regression guard.
 *
 * Real SQLite schema, real seeded data, real queries shaped like controller
 * actions. Plain (vanilla Eloquent) vs the same models with HasGrease, on the
 * SAME tables. Interleaved + order-flipped per round so drift cancels. Reports
 * per-request wall time including SQL — the number an endpoint actually sees.
 *
 * Requires `composer install` first.
 *
 *   php benchmarks/realworld.php [rounds]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Grease\Events\Dispatcher as GreaseDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

// Boots Eloquent with a real stock event dispatcher wired in, so model events
// actually fire — the work a real endpoint does. See BootsEloquent.
$capsule = BootsEloquent::capsule();
$schema = $capsule->schema();

$schema->create('users', function (Blueprint $t) {
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

$schema->create('posts', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('user_id');
    $t->string('title');
    $t->text('body');
    $t->integer('view_count');
    $t->boolean('is_published');
    $t->dateTime('published_at')->nullable();
    $t->text('meta');
    $t->timestamps();
});

// --- Plain (vanilla) models. ---------------------------------------------------

class User extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

class Post extends Model
{
    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer', 'is_published' => 'boolean', 'published_at' => 'datetime', 'meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

// --- Greased models (same tables, + HasGrease, relations within the set). -------

class GreasedUser extends User
{
    use HasGrease;

    public function posts()
    {
        return $this->hasMany(GreasedPost::class, 'user_id');
    }
}

class GreasedPost extends Post
{
    use HasGrease;

    public function user()
    {
        return $this->belongsTo(GreasedUser::class, 'user_id');
    }
}

$MODELS = [
    'plain' => ['user' => User::class, 'post' => Post::class],
    'grease' => ['user' => GreasedUser::class, 'post' => GreasedPost::class],
];

// --- Seed. ---------------------------------------------------------------------

$now = '2026-01-01 00:00:00';
$users = [];
for ($u = 1; $u <= 300; $u++) {
    $users[] = ['name' => "User $u", 'email' => "user$u@example.test", 'age' => 18 + ($u % 60), 'is_active' => $u % 2, 'score' => number_format(($u % 100) + 0.5, 2, '.', ''), 'settings' => '{"theme":"dark"}', 'email_verified_at' => $u % 3 ? $now : null, 'created_at' => $now, 'updated_at' => $now];
}
foreach (array_chunk($users, 500) as $c) {
    $capsule->table('users')->insert($c);
}
$posts = [];
$pid = 0;
for ($u = 1; $u <= 300; $u++) {
    for ($p = 0; $p < 8; $p++) {
        $pid++;
        $posts[] = ['user_id' => $u, 'title' => "Post $pid", 'body' => str_repeat('lorem ', 12), 'view_count' => $pid, 'is_published' => $pid % 2, 'published_at' => $pid % 2 ? $now : null, 'meta' => '{"tags":["a"]}', 'created_at' => $now, 'updated_at' => $now];
    }
}
foreach (array_chunk($posts, 500) as $c) {
    $capsule->table('posts')->insert($c);
}

// --- Workloads. ----------------------------------------------------------------

$WORKLOADS = [
    'index_users' => fn (array $m) => $m['user']::query()->limit(100)->get()->toArray(),
    'posts_with_author' => fn (array $m) => $m['post']::with('user')->limit(100)->get()->toArray(),
    'show_post' => fn (array $m) => optional($m['post']::with('user')->find(50))->toArray(),
    'bulk_update' => fn (array $m) => $m['user']::query()->limit(150)->get()->each(function ($u) {
        $u->score = $u->score + 1;
        $u->save();
    }),
];

// --- Parity + harness. ---------------------------------------------------------

// Run each parity probe inside a rolled-back transaction so mutating workloads
// (bulk_update) don't pollute the shared DB between the two arms — both compare
// against identical pristine state.
$conn = $capsule->getConnection();
$probe = function (callable $fn) use ($conn) {
    $conn->beginTransaction();

    try {
        return json_encode($fn());
    } finally {
        $conn->rollBack();
    }
};

// Full-stack A/B: the vanilla arm runs on the stock dispatcher, the greased arm on
// Grease's faster dispatcher — so the macro reflects the events tier too, not just
// the model tiers. Both carry zero listeners (the realistic model-event hot path).
$dispatchers = [
    'plain' => Model::getEventDispatcher(),                      // the stock one BootsEloquent wired
    'grease' => new GreaseDispatcher($capsule->getContainer()),
];
$useDispatcher = fn (string $arm) => Model::setEventDispatcher($dispatchers[$arm]);

foreach ($WORKLOADS as $name => $w) {
    $useDispatcher('plain');
    $plainOut = $probe(fn () => $w($MODELS['plain']));
    $useDispatcher('grease');
    $greaseOut = $probe(fn () => $w($MODELS['grease']));

    if ($plainOut !== $greaseOut) {
        fwrite(STDERR, "PARITY FAIL: $name\n");
        exit(1);
    }
}
echo "PARITY: PASS — grease output byte-identical to vanilla.\n".str_repeat('-', 70)."\n";

// Linear-interpolated percentile (same method as numpy's default). p in [0,100].
function percentile(array $xs, float $p): float
{
    sort($xs);
    $n = count($xs);
    if ($n === 1) {
        return $xs[0];
    }
    $rank = $p / 100 * ($n - 1);
    $lo = (int) floor($rank);
    $hi = (int) ceil($rank);

    return $xs[$lo] + ($xs[$hi] - $xs[$lo]) * ($rank - $lo);
}

$rounds = (int) ($argv[1] ?? 25);
$warmup = 6;

foreach ($WORKLOADS as $name => $w) {
    $t = ['plain' => [], 'grease' => []];
    for ($r = 0; $r < $warmup + $rounds; $r++) {
        foreach ($r % 2 ? ['grease', 'plain'] : ['plain', 'grease'] as $arm) {
            $useDispatcher($arm);
            gc_collect_cycles();
            $t0 = hrtime(true);
            $w($MODELS[$arm]);
            $dt = hrtime(true) - $t0;
            if ($r >= $warmup) {
                $t[$arm][] = $dt;
            }
        }
    }
    echo "$name\n";
    foreach ([50, 75, 90, 99] as $pct) {
        $p = percentile($t['plain'], $pct) / 1e3;
        $g = percentile($t['grease'], $pct) / 1e3;
        printf("  p%-2d  vanilla %9.1f µs   grease %9.1f µs   Δ %+6.1f%%\n", $pct, $p, $g, ($g - $p) / $p * 100);
    }
}
