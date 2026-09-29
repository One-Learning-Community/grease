<?php

/**
 * A/B: hydrating a convention-named model (no `$table` — the Laravel default). Vanilla's
 * newFromBuilder → newInstance → `setTable($this->getTable())` re-derives
 * `Str::snake(Str::pluralStudly(class_basename($this)))` for every row; the greased slim
 * path memoizes that class-pure name (only when getTable() is vanilla's). Also counts how
 * often an ordinary query path calls getTable().
 *
 *   php benchmarks/derived_table_hydration_ab.php [rows]
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

$n = (int) ($argv[1] ?? 200_000);
$row = ['id' => 1, 'title' => 'hello', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];

$time = function (Model $prototype) use ($n, $row): float {
    $prototype->newFromBuilder($row);
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $prototype->newFromBuilder($row);
    }

    return (hrtime(true) - $t) / $n;
};

for ($r = 0; $r < 3; $r++) {
    $v = $time(new BlogPostVanilla);
    $g = $time(new BlogPostGreased);
    printf("hydrate/row: vanilla %.0fns  greased %.0fns  %+.1f%%\n", $v, $g, ($g / $v - 1) * 100);
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
