<?php

/**
 * SPIKE — the "my graph isn't cyclical, give me the full juice" opt-in (pre-build proof).
 *
 * Eloquent pays a circular-reference guard on EVERY call to four methods — toArray(),
 * getQueueableRelations(), touchOwners(), push() — and pays it with
 * `debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)` (Onceable::hashFromTrace) + a WeakMap,
 * so the 99% with acyclic data subsidise the <1% with self-referential graphs. All four route
 * through `$this->withoutRecursion()`, so ONE override removes the tax everywhere:
 *
 *     protected function withoutRecursion($callback, $default = null) { return $callback(); }
 *
 * BYTE-IDENTICAL for acyclic data: the guard only ever changes output when toArray (etc.)
 * RE-ENTERS the same object — i.e. a cycle. No cycle ⇒ the guard is pure overhead ⇒ removing
 * it is identical output. The opt-in is the user asserting "no cycles"; if they lie, a cyclic
 * graph recurses until the stack blows (their explicit risk — exactly the point of opt-in).
 *
 * Unlike the conservative leaf-relation skip (recursion_spike.php), this needs NO per-call
 * relation walk and fires on EVERY shape — belongsToMany pivots, deep nesting, large
 * collections — the cases the leaf version had to defer. This spike proves byte-identity across
 * those acyclic shapes and times the full juice. (It does NOT run a real cycle through the
 * override — that would loop forever, which is the documented opt-in risk.)
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/acyclic_spike.php
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

BootsEloquent::capsule();

/** The whole opt-in: one override, kills the guard on all four recursive methods. */
trait GreaseAcyclic
{
    protected function withoutRecursion($callback, $default = null)
    {
        return $callback();
    }
}

class Vanilla extends Model
{
    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];

    public function getQRelations(): array
    {
        return $this->getQueueableRelations();
    }
}

class Acyclic extends Model
{
    use GreaseAcyclic;

    protected $table = 't';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];

    public function getQRelations(): array
    {
        return $this->getQueueableRelations();
    }
}

$mk = fn (string $cls, int $i) => (new $cls)->newFromBuilder(['id' => $i, 'name' => "M$i", 'n' => '5', 'at' => '2026-01-01 00:00:00']);

// Build the SAME acyclic shape on two classes (vanilla oracle vs acyclic opt-in).
$build = function (string $cls) use ($mk) {
    $shapes = [];

    $shapes['relation-less'] = $mk($cls, 1);

    $bt = $mk($cls, 1);
    $bt->setRelation('owner', $mk($cls, 2));
    $shapes['belongsTo'] = $bt;

    $hm = $mk($cls, 1);
    $hm->setRelation('kids', new Collection([$mk($cls, 2), $mk($cls, 3), $mk($cls, 4)]));
    $shapes['hasMany'] = $hm;

    // 3-level deep chain — the leaf-skip deferred on every level but the last; this fires all.
    $a = $mk($cls, 1);
    $b = $mk($cls, 2);
    $c = $mk($cls, 3);
    $c->setRelation('leaf', $mk($cls, 4));
    $b->setRelation('mid', $c);
    $a->setRelation('top', $b);
    $shapes['deep (3 levels)'] = $a;

    // belongsToMany shape — related models carry a `pivot` relation, so the leaf-skip deferred.
    $tagged = $mk($cls, 1);
    $t1 = $mk($cls, 2);
    $t1->setRelation('pivot', (new Pivot)->forceFill(['post_id' => 1, 'tag_id' => 2, 'sort' => 0]));
    $t2 = $mk($cls, 3);
    $t2->setRelation('pivot', (new Pivot)->forceFill(['post_id' => 1, 'tag_id' => 3, 'sort' => 1]));
    $tagged->setRelation('tags', new Collection([$t1, $t2]));
    $shapes['belongsToMany (pivot)'] = $tagged;

    $hidden = $mk($cls, 1);
    $hidden->setHidden(['secret']);
    $hidden->setRelation('secret', $mk($cls, 2));
    $hidden->setRelation('shown', $mk($cls, 3));
    $shapes['hidden relation'] = $hidden;

    return $shapes;
};

$vanilla = $build(Vanilla::class);
$acyclic = $build(Acyclic::class);

echo "acyclic opt-in (withoutRecursion override) — PARITY vs vanilla\n";
echo str_repeat('=', 78)."\n";

$violations = 0;
foreach ($vanilla as $label => $vModel) {
    $aModel = $acyclic[$label];

    $vTo = json_encode($vModel->toArray());
    $aTo = json_encode($aModel->toArray());
    $okTo = $vTo === $aTo;

    // getQueueableRelations() also goes through the guard — prove it too.
    $vQ = json_encode($vModel->getQRelations());
    $aQ = json_encode($aModel->getQRelations());
    $okQ = $vQ === $aQ;

    if ($okTo && $okQ) {
        echo "  ✅ $label\n";
    } else {
        $violations++;
        echo "  ❌ $label".($okTo ? '' : ' [toArray]').($okQ ? '' : ' [getQueueableRelations]')."\n";
        if (! $okTo) {
            echo "       vanilla: $vTo\n       acyclic: $aTo\n";
        }
    }
}

echo str_repeat('-', 78)."\n";
printf("violations: %d\n", $violations);
if ($violations) {
    echo "\nSPIKE FAILED.\n";
    exit(1);
}
echo "✅ byte-identical across every acyclic shape (toArray + getQueueableRelations),\n";
echo "   including the belongsToMany-pivot and deep-nesting shapes the leaf-skip deferred.\n";

// --- Timing: the deep + pivot shapes (where the leaf-skip got nothing), min-of-7. ---
$best = function (callable $f): float {
    $M = 120000;
    $m = PHP_FLOAT_MAX;
    for ($r = 0; $r < 7; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $M; $i++) {
            $f();
        }
        $m = min($m, (hrtime(true) - $t) / $M);
    }

    return $m;
};

echo "\nTIMING — toArray, min-of-7 (the shapes the leaf-skip could not touch)\n";
echo str_repeat('-', 78)."\n";
foreach (['deep (3 levels)', 'belongsToMany (pivot)', 'belongsTo'] as $shape) {
    $v = $vanilla[$shape];
    $a = $acyclic[$shape];
    $tv = $best(fn () => $v->toArray());
    $ta = $best(fn () => $a->toArray());
    printf("%-24s vanilla %7.0f ns   acyclic %7.0f ns   (%+.1f%%)\n", $shape, $tv, $ta, ($ta - $tv) / $tv * 100);
}
printf("jit on? %s\n", (opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no');
