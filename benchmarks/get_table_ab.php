<?php

/**
 * A/B: `getTable()` on a convention-named model (no `$table` — the Laravel default). Vanilla
 * re-derives `Str::snake(Str::pluralStudly(class_basename($this)))` on every call; greased
 * memoizes the class-pure derived name. Also counts how often a typical query path calls it.
 *
 *   php benchmarks/get_table_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Concerns\HasGrease;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;

class BlogPostVanilla extends Model {}
class BlogPostGreased extends Model
{
    use HasGrease;
}

$n = (int) ($argv[1] ?? 1_000_000);

$time = function (Model $m) use ($n): float {
    $m->getTable();
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $m->getTable();
    }

    return (hrtime(true) - $t) / $n;
};

for ($r = 0; $r < 3; $r++) {
    $v = $time(new BlogPostVanilla);
    $g = $time(new BlogPostGreased);
    printf("getTable(): vanilla %.1fns  greased %.1fns  %+.1f%%\n", $v, $g, ($g / $v - 1) * 100);
}

// How many getTable() calls does an ordinary query + hydrate + save make?
$db = new DB;
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();

class CountingPost extends Model
{
    public static int $calls = 0;

    protected $guarded = [];

    public function getTable()
    {
        static::$calls++;

        return parent::getTable();
    }
}

DB::schema()->create('counting_posts', function ($t) {
    $t->id();
    $t->string('title');
    $t->timestamps();
});
for ($i = 0; $i < 20; $i++) {
    CountingPost::create(['title' => "p$i"]);
}
CountingPost::$calls = 0;
$rows = CountingPost::query()->where('id', '>', 0)->orderBy('id')->get();
$afterGet = CountingPost::$calls;
$rows->first()->update(['title' => 'x']);
printf("getTable() calls: get() of %d rows = %d, one update() = %d\n", $rows->count(), $afterGet, CountingPost::$calls - $afterGet);
