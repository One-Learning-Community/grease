<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedAcyclicSerialization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * {@see HasGreasedAcyclicSerialization} drops Eloquent's circular-reference guard (the
 * `debug_backtrace` in `withoutRecursion()`) for models that promise an acyclic graph. The
 * contract: byte-identical to vanilla for any acyclic graph — across `toArray()` AND
 * `getQueueableRelations()`, the two read-path methods that route through the guard — including
 * the belongsToMany-pivot and deep-nested shapes the conservative leaf-skip would defer.
 *
 * A cyclic graph is the documented, unsupported responsibility of the opt-in (it would recurse
 * until the stack overflows), so it is deliberately NOT exercised here — that would hang.
 * Vanilla is the oracle throughout.
 */
class HasGreasedAcyclicSerializationParityTest extends TestCase
{
    /** @return array<string, callable(string):AcycVanilla|AcycGreased|AcycFull> */
    private function shapes(string $cls): array
    {
        $mk = fn (int $i) => (new $cls)->newFromBuilder(['id' => $i, 'name' => "M$i", 'n' => '5', 'at' => '2026-01-01 00:00:00']);

        $shapes = [];
        $shapes['relation-less'] = $mk(1);

        $bt = $mk(1);
        $bt->setRelation('owner', $mk(2));
        $shapes['belongsTo'] = $bt;

        $hm = $mk(1);
        $hm->setRelation('kids', new Collection([$mk(2), $mk(3), $mk(4)]));
        $shapes['hasMany'] = $hm;

        // 3-level chain: A → B → C → leaf.
        $a = $mk(1);
        $b = $mk(2);
        $c = $mk(3);
        $c->setRelation('leaf', $mk(4));
        $b->setRelation('mid', $c);
        $a->setRelation('top', $b);
        $shapes['deep'] = $a;

        // belongsToMany: related models carry a `pivot` relation.
        $tagged = $mk(1);
        $t1 = $mk(2);
        $t1->setRelation('pivot', (new Pivot)->forceFill(['post_id' => 1, 'tag_id' => 2]));
        $t2 = $mk(3);
        $t2->setRelation('pivot', (new Pivot)->forceFill(['post_id' => 1, 'tag_id' => 3]));
        $tagged->setRelation('tags', new Collection([$t1, $t2]));
        $shapes['belongsToMany'] = $tagged;

        $hidden = $mk(1);
        $hidden->setHidden(['secret']);
        $hidden->setRelation('secret', $mk(2));
        $hidden->setRelation('shown', $mk(3));
        $shapes['hidden'] = $hidden;

        $nullRel = $mk(1);
        $nullRel->setRelation('owner', null);
        $shapes['null-relation'] = $nullRel;

        return $shapes;
    }

    public function test_toarray_is_byte_identical_to_vanilla_across_acyclic_shapes(): void
    {
        $vanilla = $this->shapes(AcycVanilla::class);

        foreach ([AcycGreased::class, AcycFull::class] as $cls) {
            $greased = $this->shapes($cls);
            foreach ($vanilla as $shape => $vModel) {
                $this->assertSame(
                    json_encode($vModel->toArray()),
                    json_encode($greased[$shape]->toArray()),
                    "$cls toArray() diverged on shape: $shape",
                );
            }
        }
    }

    public function test_getqueueablerelations_is_byte_identical_to_vanilla(): void
    {
        $vanilla = $this->shapes(AcycVanilla::class);

        foreach ([AcycGreased::class, AcycFull::class] as $cls) {
            $greased = $this->shapes($cls);
            foreach ($vanilla as $shape => $vModel) {
                $this->assertSame(
                    json_encode($vModel->getQueueableRelations()),
                    json_encode($greased[$shape]->getQueueableRelations()),
                    "$cls getQueueableRelations() diverged on shape: $shape",
                );
            }
        }
    }

    public function test_standalone_trait_needs_nothing_else_from_grease(): void
    {
        // AcycGreased is a plain Model + the trait only — must still match vanilla.
        $v = $this->shapes(AcycVanilla::class)['deep'];
        $g = $this->shapes(AcycGreased::class)['deep'];

        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
    }
}

class AcycVanilla extends Model
{
    protected $table = 'acyc';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}

class AcycGreased extends Model
{
    use HasGreasedAcyclicSerialization;

    protected $table = 'acyc';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}

class AcycFull extends Model
{
    use HasGrease;
    use HasGreasedAcyclicSerialization;

    protected $table = 'acyc';

    protected $casts = ['n' => 'integer', 'at' => 'datetime'];
}
