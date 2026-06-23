<?php

/**
 * Measure-first probe for an eager-load *matching* tier (Relation::match /
 * buildDictionary / getDictionaryKey) — BEFORE building anything. Hydration is already
 * greased; the question is whether the dictionary-build + match slice is a reachable,
 * allocation-shaped lever or whether it's dwarfed by hydration + SQL on a real eager load.
 *
 * Boots Eloquent (BootsEloquent), seeds users + posts, then samples a tight loop of
 * `User::with('posts')->get()` (HasMany — the heavier match: one dictionary over every
 * child row, then a lookup per parent) with Excimer. Prints top frames by self time so
 * buildDictionary/matchOneOrMany/getDictionaryKey show up next to newFromBuilder/hydrate.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing benchmarks/eager_excimer.php [users] [posts] [secs]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

if (! extension_loaded('excimer')) {
    fwrite(STDERR, "excimer not loaded.\n");
    exit(1);
}

$nUsers = (int) ($argv[1] ?? 100);
$nPosts = (int) ($argv[2] ?? 20);
$seconds = (float) ($argv[3] ?? 8.0);

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

class GUser extends Model
{
    use HasGrease;

    protected $table = 'users';

    protected $casts = ['age' => 'integer'];

    public function posts()
    {
        return $this->hasMany(GPost::class, 'user_id');
    }
}

class GPost extends Model
{
    use HasGrease;

    protected $table = 'posts';

    protected $casts = ['view_count' => 'integer'];

    public function user()
    {
        return $this->belongsTo(GUser::class, 'user_id');
    }
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

// Warm.
GUser::with('posts')->get();

$profiler = new ExcimerProfiler;
$profiler->setPeriod(0.0001);
$profiler->setEventType(EXCIMER_REAL);

$deadline = hrtime(true) + (int) ($seconds * 1e9);
$runs = 0;
$profiler->start();
while (hrtime(true) < $deadline) {
    GUser::with('posts')->get();
    $runs++;
}
$profiler->stop();

$log = $profiler->getLog();
echo "Excimer: {$runs}x GUser::with('posts')->get()  ($nUsers users x $nPosts posts = ".($nUsers * $nPosts)." children)\n";
echo "samples: ".count($log)."\n".str_repeat('-', 72)."\n";

$agg = $log->aggregateByFunction();
uasort($agg, static fn ($a, $b) => $b['self'] - $a['self']);
$total = array_sum(array_map(static fn ($e) => $e['self'], $agg)) ?: 1;
printf("%-7s %-7s  %s\n", 'self%', 'incl%', 'function');
$i = 0;
foreach ($agg as $fn => $e) {
    if ($i++ >= 28) {
        break;
    }
    printf("%6.2f%% %6.2f%%  %s\n", $e['self'] / $total * 100, $e['inclusive'] / $total * 100, $fn);
}
