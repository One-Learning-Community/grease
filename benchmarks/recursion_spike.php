<?php

/**
 * SPIKE — extend the toArray() recursion-guard short-circuit to relation-bearing models
 * (pre-build proof, throwaway until wired).
 *
 * HasGreasedSerialization::toArray() already skips the `withoutRecursion` machinery
 * (Onceable::hashFromTrace = a debug_backtrace per call) when `relations === []`. The
 * stack_excimer profile shows that guard returns — ~5% — the moment a relation is loaded
 * (every `with()` API response). This extends the skip to: relations loaded, but ALL loaded
 * relations hold only LEAF models (related models whose own `relations === []`).
 *
 * WHY IT'S SAFE: the guard only ever changes output when toArray RE-ENTERS the same object
 * (a cycle). If A's loaded relations are all leaves, relationsToArray() calls leaf->toArray()
 * which recurse no further — so no path back to A, no cycle, the guard is provably inert, and
 * `array_merge(attributesToArray(), relationsToArray())` is byte-identical to the guarded
 * vanilla toArray(). A cycle (A has B, B has A) makes B non-leaf, so the check DEFERS — and
 * defers to the real guarded toArray, which terminates. Asymmetric risk:
 *
 *     fires(A) ⟹ fastToArray(A) === vanilla toArray(A)     ← the only invariant that matters
 *     a reachable cycle ⟹ DEFER (never fire)               ← guarded path owns it, unchanged
 *
 * Proven one-directionally: every scenario asserts fast === vanilla (the oracle), with the
 * teeth in (a) the FIRE cases (leaf relations) and (b) the CIRCULAR cases, which must defer
 * and still terminate byte-identically. Then it times the win. Exit non-zero on any violation.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/recursion_spike.php
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

BootsEloquent::capsule(); // relations' toArray touches getDateFormat → needs a connection

/** The prototype skip — exactly the shape the real HasGreasedSerialization override would take. */
trait SpikeRecursionToArray
{
    public bool $spikeFired = false;

    public function spikeToArray(): array
    {
        $rels = $this->getRelations();

        if ($rels === []) {
            $this->spikeFired = true;

            return $this->attributesToArray(); // the already-shipped relation-less short-circuit
        }

        foreach ($rels as $rel) {
            foreach (is_iterable($rel) ? $rel : [$rel] as $m) {
                if ($m instanceof Model && $m->getRelations() !== []) {
                    return $this->toArray(); // not all-leaf ⇒ defer to the guarded vanilla path
                }
            }
        }

        $this->spikeFired = true;

        // All loaded relations are leaves ⇒ the guard is provably inert. Vanilla toArray() is
        // exactly withoutRecursion(fn => array_merge(attributesToArray(), relationsToArray())).
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }
}

class Leaf extends Model
{
    use HasGrease;
    use SpikeRecursionToArray;

    protected $table = 'leaf';

    protected $casts = ['n' => 'integer', 'ok' => 'boolean', 'at' => 'datetime'];
}

class Node extends Model
{
    use HasGrease;
    use SpikeRecursionToArray;

    protected $table = 'node';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];

    public function rel()
    {
        return $this->belongsTo(Node::class, 'rel_id');
    }
}

$leaf = fn (int $i) => (new Leaf)->newFromBuilder(['id' => $i, 'name' => "L$i", 'n' => '7', 'ok' => '1', 'at' => '2026-01-01 00:00:00']);
$node = fn (int $i) => (new Node)->newFromBuilder(['id' => $i, 'name' => "N$i", 'n' => '3', 'at' => '2026-01-01 00:00:00']);

// --- Scenarios. Each: [label, builder()] — builder returns a fresh root model. ---
$scenarios = [];

$scenarios['relation-less'] = fn () => $leaf(1);

$scenarios['belongsTo leaf (FIRE)'] = function () use ($node, $leaf) {
    $n = $node(1);
    $n->setRelation('child', $leaf(2));

    return $n;
};

$scenarios['hasMany leaves (FIRE)'] = function () use ($node, $leaf) {
    $n = $node(1);
    $n->setRelation('kids', new Collection([$leaf(2), $leaf(3), $leaf(4)]));

    return $n;
};

$scenarios['null relation (FIRE)'] = function () use ($node) {
    $n = $node(1);
    $n->setRelation('child', null);

    return $n;
};

$scenarios['empty collection (FIRE)'] = function () use ($node) {
    $n = $node(1);
    $n->setRelation('kids', new Collection([]));

    return $n;
};

$scenarios['hidden leaf relation (FIRE)'] = function () use ($node, $leaf) {
    $n = $node(1);
    $n->setHidden(['child']);
    $n->setRelation('child', $leaf(2));

    return $n;
};

$scenarios['nested non-leaf (DEFER)'] = function () use ($node, $leaf) {
    $inner = $node(2);
    $inner->setRelation('child', $leaf(3)); // inner is NOT a leaf
    $outer = $node(1);
    $outer->setRelation('child', $inner);

    return $outer;
};

$scenarios['circular A<->B (DEFER, must terminate)'] = function () use ($node) {
    $a = $node(1);
    $b = $node(2);
    $a->setRelation('rel', $b);
    $b->setRelation('rel', $a); // cycle — guard's reason to exist

    return $a;
};

$scenarios['self-referential (DEFER, must terminate)'] = function () use ($node) {
    $a = $node(1);
    $a->setRelation('rel', $a); // points at itself

    return $a;
};

$scenarios['mutually-circular collections (DEFER)'] = function () use ($node) {
    $a = $node(1);
    $b = $node(2);
    $a->setRelation('kids', new Collection([$b]));
    $b->setRelation('kids', new Collection([$a]));

    return $a;
};

// --- Parity: fast === vanilla for every scenario; track fire/defer. ---
echo "recursion-guard short-circuit — PARITY vs vanilla toArray()\n";
echo str_repeat('=', 78)."\n";

$violations = 0;
$fired = 0;
$deferred = 0;

foreach ($scenarios as $label => $build) {
    // Two independent builds: one for the oracle, one for the fast path (toArray can mutate
    // internal recursion state, so don't share the instance).
    $oracleRoot = $build();
    $fastRoot = $build();

    $expectFire = str_contains($label, 'FIRE') || $label === 'relation-less';

    try {
        $vanilla = $oracleRoot->toArray();
        $fast = $fastRoot->spikeToArray();
        $ok = json_encode($vanilla) === json_encode($fast);
    } catch (Throwable $e) {
        $ok = false;
        $vanilla = $fast = null;
        echo "  💥 $label — threw: ".$e->getMessage()."\n";
    }

    $fastRoot->spikeFired ? $fired++ : $deferred++;

    $fireMark = $fastRoot->spikeFired ? 'FIRE ' : 'defer';
    if (! $ok) {
        $violations++;
        echo "  ❌ [$fireMark] $label — fast != vanilla\n";
        echo '       vanilla: '.json_encode($vanilla)."\n";
        echo '       fast:    '.json_encode($fast)."\n";
    } else {
        // Coverage guard: a scenario we expect to fire but didn't proves nothing.
        $covWarn = ($expectFire && ! $fastRoot->spikeFired) ? '  ⚠ expected FIRE but deferred' : '';
        echo "  ✅ [$fireMark] $label$covWarn\n";
        if ($expectFire && ! $fastRoot->spikeFired) {
            $violations++;
        }
    }
}

echo str_repeat('-', 78)."\n";
printf("fired: %d   deferred: %d   violations: %d\n", $fired, $deferred, $violations);

if ($violations) {
    echo "\nSPIKE FAILED — not byte-identical (or a coverage gap). Do not ship.\n";
    exit(1);
}
echo "✅ every scenario byte-identical; cycles all deferred and terminated.\n";

// --- Timing: a relation-bearing model that FIRES (belongsTo a leaf) — the eager-load shape. ---
$root = $scenarios['belongsTo leaf (FIRE)'];
$best = function (callable $f): float {
    $M = 120000;
    $m = PHP_FLOAT_MAX;
    for ($r = 0; $r < 7; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $M; $i++) {
            $f();
        }
        $x = (hrtime(true) - $t) / $M;
        $m = min($m, $x);
    }

    return $m;
};

$v = $root();
$g = $root();
$tv = $best(fn () => $v->toArray());
$tg = $best(fn () => $g->spikeToArray());

echo "\nTIMING — relation-bearing toArray (belongsTo a leaf), min-of-7\n";
echo str_repeat('-', 78)."\n";
printf("vanilla (guard):  %7.0f ns\n", $tv);
printf("skip-guard:       %7.0f ns\n", $tg);
printf("delta:            %+.1f%%\n", ($tg - $tv) / $tv * 100);
printf("jit on? %s\n", (opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no');
