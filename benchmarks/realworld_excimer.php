<?php

/**
 * Excimer profiler over the *actual* realworld.php endpoints — not the eager_excimer
 * proxy. Same rich-cast models (boolean / decimal:2 / array / datetime), same schema,
 * same seed, the greased dispatcher wired in — so the frames reflect what each endpoint
 * really pays, including the cast + serialization tiers the single-integer-cast proxy
 * barely exercised, and (for bulk_update) the write path the proxy never touched at all.
 *
 * Profiles each endpoint separately and prints top self-time frames. Run with
 * opcache.jit=off: under tracing JIT, inlined callers misattribute self-time to tiny
 * leaves (the enum_value phantom — see NOTES #11). jit=off self-times are truthful;
 * call counts are reliable either way.
 *
 *   php -d xdebug.mode=off -d opcache.jit=off -d memory_limit=1G benchmarks/realworld_excimer.php [endpoint] [secs]
 *
 * endpoint: one of index_users|posts_with_author|show_post|bulk_update, or "all" (default).
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Grease\Events\Dispatcher as GreaseDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

if (! extension_loaded('excimer')) {
    fwrite(STDERR, "excimer not loaded.\n");
    exit(1);
}

$only = $argv[1] ?? 'all';
$seconds = (float) ($argv[2] ?? 5.0);

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

class RwUser extends Model
{
    protected $table = 'users';

    protected $casts = ['age' => 'integer', 'is_active' => 'boolean', 'score' => 'decimal:2', 'settings' => 'array', 'email_verified_at' => 'datetime'];

    public function posts()
    {
        return $this->hasMany(RwPost::class, 'user_id');
    }
}
class RwPost extends Model
{
    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer', 'is_published' => 'boolean', 'published_at' => 'datetime', 'meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(RwUser::class, 'user_id');
    }
}
class GreasedRwUser extends RwUser
{
    use HasGrease;

    public function posts()
    {
        return $this->hasMany(GreasedRwPost::class, 'user_id');
    }
}
class GreasedRwPost extends RwPost
{
    use HasGrease;

    public function user()
    {
        return $this->belongsTo(GreasedRwUser::class, 'user_id');
    }
}

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

$M = ['user' => GreasedRwUser::class, 'post' => GreasedRwPost::class];

// bulk_update mutates; run each iteration inside a rolled-back transaction so the seed
// stays pristine across the profiling loop (mirrors realworld.php's probe).
$conn = $capsule->getConnection();

$WORKLOADS = [
    'index_users' => fn () => $M['user']::query()->limit(100)->get()->toArray(),
    'posts_with_author' => fn () => $M['post']::with('user')->limit(100)->get()->toArray(),
    'show_post' => fn () => optional($M['post']::with('user')->find(50))->toArray(),
    'bulk_update' => function () use ($M, $conn) {
        $conn->beginTransaction();
        try {
            $M['user']::query()->limit(150)->get()->each(function ($u) {
                $u->score = $u->score + 1;
                $u->save();
            });
        } finally {
            $conn->rollBack();
        }
    },
];

Model::setEventDispatcher(new GreaseDispatcher($capsule->getContainer()));
Carbon::setTestNow('2026-01-01 12:00:00');

$endpoints = $only === 'all' ? array_keys($WORKLOADS) : [$only];

foreach ($endpoints as $name) {
    $w = $WORKLOADS[$name];
    $w(); // warm

    $profiler = new ExcimerProfiler;
    $profiler->setPeriod(0.0001);
    $profiler->setEventType(EXCIMER_REAL);

    $deadline = hrtime(true) + (int) ($seconds * 1e9);
    $runs = 0;
    $profiler->start();
    while (hrtime(true) < $deadline) {
        $w();
        $runs++;
    }
    $profiler->stop();

    $log = $profiler->getLog();
    echo "\n".str_repeat('=', 72)."\n";
    echo "$name — {$runs}× (greased arm, jit per -d)\n";
    echo 'samples: '.count($log)."\n".str_repeat('-', 72)."\n";

    $agg = $log->aggregateByFunction();
    uasort($agg, static fn ($a, $b) => $b['self'] - $a['self']);
    $total = array_sum(array_map(static fn ($e) => $e['self'], $agg)) ?: 1;
    printf("%-7s %-7s  %s\n", 'self%', 'incl%', 'function');
    $i = 0;
    foreach ($agg as $fn => $e) {
        if ($i++ >= 18) {
            break;
        }
        printf("%6.2f%% %6.2f%%  %s\n", $e['self'] / $total * 100, $e['inclusive'] / $total * 100, $fn);
    }
}

Carbon::setTestNow();
