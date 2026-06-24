<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedSerialization;
use Illuminate\Database\Eloquent\Model;

/**
 * An appended accessor that lazy-loads a relation as a side effect of
 * attributesToArray() — the canonical "a count/flag derived from a relationship"
 * shape (e.g. `kid_count` reading `$this->kids`).
 */
trait LazyLoadsRelationOnAppend
{
    public function getKidCountAttribute(): int
    {
        if (! $this->relationLoaded('kids')) {
            $this->setRelation('kids', collect([['id' => 7], ['id' => 8]]));
        }

        return $this->kids->count();
    }
}

class VanillaAppendLoadsRelation extends Model
{
    use LazyLoadsRelationOnAppend;

    public $timestamps = false;

    protected $table = 'al';

    protected $appends = ['kid_count'];
}

class GreasedAppendLoadsRelation extends Model
{
    use HasGrease;
    use LazyLoadsRelationOnAppend;

    public $timestamps = false;

    protected $table = 'al';

    protected $appends = ['kid_count'];
}

/**
 * {@see HasGreasedSerialization::toArray()} short-circuits the
 * circular-recursion guard when no relations are loaded: vanilla wraps every `toArray()` in
 * `withoutRecursion()` (a `debug_backtrace` + `Onceable` hash per call), but with
 * `$this->relations === []` there is nothing to recurse into, so `toArray()` is exactly
 * `attributesToArray()`. The contract: byte-for-byte identical output to vanilla — relation-
 * less, with a loaded relation (defers to `parent::`, guard intact), and — the safety case —
 * with a *circular* relation, where the guard must still terminate. Vanilla is the oracle.
 */
class HasGreasedToArrayRecursionParityTest extends TestCase
{
    public function test_relationless_toarray_is_byte_identical_to_vanilla(): void
    {
        // Rich casts + dates, no relations → the short-circuit path. Must equal vanilla.
        [$vanilla, $greased] = $this->pair($this->sampleRow());

        $this->assertSame(json_encode($vanilla->toArray()), json_encode($greased->toArray()));
    }

    public function test_relationless_toarray_includes_appends_like_vanilla(): void
    {
        // attributesToArray() also runs appends/accessors — the short-circuit must not drop them.
        [$vanilla, $greased] = $this->pair($this->sampleRow());
        $vanilla->setAppends(['upper_val']);
        $greased->setAppends(['upper_val']);

        $this->assertSame(json_encode($vanilla->toArray()), json_encode($greased->toArray()));
    }

    public function test_loaded_relation_defers_to_vanilla(): void
    {
        // A loaded relation → relations !== [] → defers to parent::toArray(); the related model
        // is serialized identically.
        [$vanilla, $greased] = $this->pair($this->sampleRow());
        [$vRel, $gRel] = $this->pair($this->sampleRow(['id' => 2, 'str_val' => 'related']));

        $vanilla->setRelation('buddy', $vRel);
        $greased->setRelation('buddy', $gRel);

        $this->assertSame(json_encode($vanilla->toArray()), json_encode($greased->toArray()));

        $out = $greased->toArray();
        $this->assertArrayHasKey('buddy', $out, 'loaded relation is present in the array');
    }

    public function test_circular_relation_is_still_guarded_and_matches_vanilla(): void
    {
        // The guard's whole reason for existing: a self-referential relation must not recurse
        // forever. relations !== [] → greased defers to the vanilla guard. Both must terminate
        // and produce identical output (the nested self resolves to its attributes, no relations).
        [$vanilla, $greased] = $this->pair($this->sampleRow());
        $vanilla->setRelation('self', $vanilla);
        $greased->setRelation('self', $greased);

        $vOut = $vanilla->toArray();   // terminates via the guard, or this test would hang
        $gOut = $greased->toArray();

        $this->assertSame(json_encode($vOut), json_encode($gOut));
        $this->assertArrayHasKey('self', $gOut);
        $this->assertArrayNotHasKey('self', $gOut['self'], 'nested self is attributes-only (guard broke the cycle)');
    }

    public function test_append_accessor_that_lazy_loads_a_relation_matches_vanilla(): void
    {
        // The short-circuit checks relations === [] BEFORE attributesToArray() runs
        // the appended accessor — but that accessor lazy-loads a relation. Vanilla
        // runs relationsToArray() AFTER attributesToArray(), so the relation appears;
        // the fast path must not drop it.
        $vanilla = (new VanillaAppendLoadsRelation)->newFromBuilder(['id' => 1]);
        $greased = (new GreasedAppendLoadsRelation)->newFromBuilder(['id' => 1]);

        $vOut = $vanilla->toArray();
        $gOut = $greased->toArray();

        $this->assertSame(json_encode($vOut), json_encode($gOut));
        $this->assertArrayHasKey('kids', $gOut, 'relation loaded by the append accessor must be present');
    }

    public function test_append_loaded_relation_toarray_is_idempotent(): void
    {
        // A second toArray() must equal the first — the side-effecting accessor left
        // the relation loaded, so the fast path must not flip the result between calls.
        $vanilla = (new VanillaAppendLoadsRelation)->newFromBuilder(['id' => 1]);
        $greased = (new GreasedAppendLoadsRelation)->newFromBuilder(['id' => 1]);

        $greased->toArray();

        $this->assertSame(json_encode($vanilla->toArray()), json_encode($greased->toArray()));
    }

    public function test_mutually_circular_relations_match_vanilla(): void
    {
        // A <-> B cycle through two distinct models — the canonical circular-relation shape.
        [$vA, $gA] = $this->pair($this->sampleRow(['id' => 1]));
        [$vB, $gB] = $this->pair($this->sampleRow(['id' => 2]));

        $vA->setRelation('peer', $vB);
        $vB->setRelation('peer', $vA);
        $gA->setRelation('peer', $gB);
        $gB->setRelation('peer', $gA);

        $this->assertSame(json_encode($vA->toArray()), json_encode($gA->toArray()));
    }
}
