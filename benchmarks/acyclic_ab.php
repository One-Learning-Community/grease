<?php

/**
 * Tier-isolated A/B for HasGreasedAcyclicSerialization — dropping Eloquent's circular-reference
 * guard (a `debug_backtrace` per call to toArray / getQueueableRelations / touchOwners / push)
 * for models that promise an acyclic graph.
 *
 * Reports toArray() across relation shapes, in two arms, because the win compounds:
 *   PLAIN    — vanilla Model vs +trait. The un-greased row is heavy (Carbon datetime etc.), so
 *              the fixed ~1.4µs guard removal is a smaller %.
 *   GREASED  — HasGrease vs HasGrease+trait. The REAL deploy: the model tiers already shrank the
 *              row, so removing the guard is a bigger share. Each model in the graph saves one
 *              debug_backtrace, so deeper graphs win more in absolute terms.
 *
 * Correctness is driver- and shape-independent and proven byte-identical in
 * HasGreasedAcyclicSerializationParityTest; this only measures the win. (A cyclic graph is the
 * opt-in's documented risk — not benchmarked, it would recurse forever.)
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/acyclic_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedAcyclicSerialization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

BootsEloquent::capsule();

$iters = (int) ($argv[1] ?? 20000);

class PlainV extends Model
{
    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}
class PlainA extends Model
{
    use HasGreasedAcyclicSerialization;

    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}
class GreaseV extends Model
{
    use HasGrease;

    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}
class GreaseA extends Model
{
    use HasGrease;
    use HasGreasedAcyclicSerialization;

    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}

function shape(string $cls, string $kind)
{
    $mk = fn (int $i) => (new $cls)->newFromBuilder(['id' => $i, 'name' => "M$i", 'n' => '5', 'at' => '2026-01-01 00:00:00']);
    $root = $mk(1);
    switch ($kind) {
        case 'relation-less':
            return $root;
        case 'belongsTo':
            $root->setRelation('owner', $mk(2));

            return $root;
        case 'hasMany(10)':
            $root->setRelation('kids', new Collection(array_map($mk, range(2, 11))));

            return $root;
        case 'deep(3)':
            $a = $mk(1);
            $b = $mk(2);
            $c = $mk(3);
            $c->setRelation('leaf', $mk(4));
            $b->setRelation('mid', $c);
            $a->setRelation('top', $b);

            return $a;
        case 'belongsToMany(5)':
            $tags = [];
            foreach (range(2, 6) as $i) {
                $t = $mk($i);
                $t->setRelation('pivot', (new Pivot)->forceFill(['a' => 1, 'b' => $i]));
                $tags[] = $t;
            }
            $root->setRelation('tags', new Collection($tags));

            return $root;
        case 'hasMany(100)':
            $root->setRelation('kids', new Collection(array_map($mk, range(2, 101))));

            return $root;
        case 'list(100×belongsTo)':
            // The real API list shape: a collection of 100 relation-bearing models. EACH pays
            // the guard in vanilla → the per-parent saving multiplies by the row count.
            $rows = [];
            foreach (range(1, 100) as $i) {
                $r = $mk($i);
                $r->setRelation('owner', $mk(1000 + $i));
                $rows[] = $r;
            }

            return new Collection($rows);
    }
}

$kinds = ['relation-less', 'belongsTo', 'hasMany(10)', 'deep(3)', 'belongsToMany(5)', 'hasMany(100)', 'list(100×belongsTo)'];

// --- Parity gate. ---
foreach ($kinds as $k) {
    $v = json_encode(shape(PlainV::class, $k)->toArray());
    foreach ([PlainA::class, GreaseV::class, GreaseA::class] as $cls) {
        if (json_encode(shape($cls, $k)->toArray()) !== $v) {
            fwrite(STDERR, "PARITY FAIL — $cls on $k\n");
            exit(1);
        }
    }
}
echo "Parity: OK — every shape byte-identical across vanilla / +trait / HasGrease / HasGrease+trait.\n\n";

$best = function (callable $f) use ($iters): float {
    $m = PHP_FLOAT_MAX;
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            $f();
        }
        $m = min($m, (hrtime(true) - $t) / $iters);
    }

    return $m;
};

printf("%-18s %22s   %22s\n", 'shape (toArray)', 'PLAIN  (van → +trait)', 'GREASED (HG → HG+trait)');
echo str_repeat('-', 66)."\n";
foreach ($kinds as $k) {
    $pv = shape(PlainV::class, $k);
    $pa = shape(PlainA::class, $k);
    $gv = shape(GreaseV::class, $k);
    $ga = shape(GreaseA::class, $k);
    // warm
    foreach ([$pv, $pa, $gv, $ga] as $m) {
        $m->toArray();
    }
    $tpv = $best(fn () => $pv->toArray());
    $tpa = $best(fn () => $pa->toArray());
    $tgv = $best(fn () => $gv->toArray());
    $tga = $best(fn () => $ga->toArray());
    printf("%-18s %8.0f→%8.0f %+6.1f%%   %8.0f→%8.0f %+6.1f%%\n",
        $k, $tpv, $tpa, ($tpa - $tpv) / $tpv * 100, $tgv, $tga, ($tga - $tgv) / $tgv * 100);
}
printf("\njit on? %s   |   macOS distorts magnitudes — Linux via benchmarks/docker is canonical.\n",
    (opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no');
