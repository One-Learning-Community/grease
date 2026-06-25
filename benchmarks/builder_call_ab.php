<?php

/**
 * Tier-isolated A/B for the Eloquent builder __call verdict memo (HasGreasedQueries).
 *
 * Eloquent\Builder lets most query verbs fall through __call, which re-resolves on EVERY
 * call whether the name is a named scope (method_exists + attribute scan) or in the
 * 32-element passthru list (in_array(strtolower(...))). The greased builder memoizes that
 * per-(model, method) verdict. NB where()/orWhere() are defined on Eloquent\Builder and
 * bypass __call, so this measures the OTHER forwarded verbs (orderBy/whereIn/select/…).
 *
 *   A = vanilla model  → Illuminate\…\Eloquent\Builder
 *   B = same model + HasGreasedQueries → Grease\…\Eloquent\Builder
 *
 * Pure dispatch — chains are built, never executed (no SQL), to isolate __call.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/builder_call_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGreasedQueries;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

$iters = (int) ($argv[1] ?? 200000);

$capsule = BootsEloquent::capsule();
$capsule->schema()->create('bc_rows', function (Blueprint $t) {
    $t->increments('id');
    $t->integer('a');
    $t->integer('b');
    $t->integer('c');
});

class BcVanilla extends Model
{
    public $timestamps = false;

    protected $table = 'bc_rows';

    protected $guarded = [];
}

class BcGreased extends Model
{
    use HasGreasedQueries;

    public $timestamps = false;

    protected $table = 'bc_rows';

    protected $guarded = [];
}

// A representative chain of FORWARDED verbs (each one hit goes through __call).
$chain = function (Model $m) {
    return $m->newQuery()
        ->select('a', 'b', 'c')
        ->whereIn('a', [1, 2, 3])
        ->orderBy('b', 'desc')
        ->groupBy('c')
        ->having('a', '>', 0)
        ->limit(10)
        ->offset(5)
        ->toSql();
};

// Parity gate.
if ($chain(new BcVanilla) !== $chain(new BcGreased)) {
    fwrite(STDERR, "PARITY FAILED — aborting\n");
    exit(1);
}
if (get_class((new BcGreased)->newQuery()) !== 'Grease\Database\Eloquent\Builder') {
    fwrite(STDERR, "greased arm not using greased builder\n");
    exit(1);
}

$time = function (callable $fn, Model $m) use ($iters): float {
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $fn($m);
    }

    return (hrtime(true) - $t) / $iters / 1000; // µs per chain (7 forwarded calls)
};

$v = new BcVanilla;
$g = new BcGreased;
$time($chain, $g); // warm
$vanilla = $time($chain, $v);
$greased = $time($chain, $g);

printf("\n=== builder __call A/B (7 forwarded verbs/chain, %d iters) ===\n\n", $iters);
printf("parity: IDENTICAL SQL, greased builder = %s\n\n", get_class($g->newQuery()));
printf("vanilla builder : %8.4f µs/chain\n", $vanilla);
printf("greased builder : %8.4f µs/chain\n", $greased);
printf("delta           : %+7.1f%%  (run on benchmarks/docker for trustworthy magnitudes)\n\n",
    ($greased - $vanilla) / $vanilla * 100);
