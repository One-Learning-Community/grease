<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\VanillaSample;
use Illuminate\Database\Eloquent\Model;

// --- Single-table-inheritance fixtures: same parent, different per-child casts. -
class StiBase extends Model
{
    use HasGrease;

    protected $table = 'sti';
}
class StiAsInt extends StiBase
{
    protected $casts = ['v' => 'integer'];
}
class StiAsBool extends StiBase
{
    protected $casts = ['v' => 'boolean'];
}

// --- Accessor + appends. -------------------------------------------------------
class VanillaAccessor extends Model
{
    protected $table = 'acc';

    protected $appends = ['shout'];

    public function getShoutAttribute(): string
    {
        return strtoupper($this->attributes['title'] ?? '');
    }
}
class GreasedAccessor extends VanillaAccessor
{
    use HasGrease;
}

// --- Set mutator (write path). -------------------------------------------------
class VanillaSetter extends Model
{
    protected $table = 'setter';

    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper((string) $value);
    }
}
class GreasedSetter extends VanillaSetter
{
    use HasGrease;
}

/**
 * The runtime-divergence and edge-shape guarantees: a greased model must stay
 * correct when casts change at runtime, when subclasses differ, and when
 * mutators/appends are in play.
 */
class RuntimeBehaviorTest extends TestCase
{
    public function test_runtime_merge_casts_is_not_stale(): void
    {
        $row = $this->sampleRow(['extra' => '7']);

        $g = (new GreasedSample)->newFromBuilder($row);
        $g->getCasts();                       // warm the per-class blueprint first
        $g->mergeCasts(['extra' => 'integer']);

        $v = (new VanillaSample)->newFromBuilder($row);
        $v->mergeCasts(['extra' => 'integer']);

        $this->assertSame(7, $g->extra, 'merged cast not honored — stale blueprint cache');
        $this->assertSame($v->extra, $g->extra);
        $this->assertEquals($v->getCasts(), $g->getCasts());
    }

    public function test_with_casts_is_not_stale(): void
    {
        $g = (new GreasedSample)->newFromBuilder($this->sampleRow(['extra' => '1']));
        $g->getCasts();
        $g->withCasts(['extra' => 'boolean']);

        $this->assertSame(true, $g->extra);
    }

    public function test_recasting_an_already_warmed_key_is_not_stale(): void
    {
        // getCastType() is memoized per key, so re-casting a key whose type was
        // already cached must still reflect the new type once the instance diverges.
        $row = $this->sampleRow();

        $g = (new GreasedSample)->newFromBuilder($row);
        $this->assertSame(42, $g->int_val);          // warms castTypes['int_val'] = 'int'
        $g->withCasts(['int_val' => 'string']);       // override an already-cached cast type

        $v = (new VanillaSample)->newFromBuilder($row);
        $v->withCasts(['int_val' => 'string']);

        $this->assertSame('42', $g->int_val, 'stale cached cast type after runtime re-cast');
        $this->assertSame($v->int_val, $g->int_val);
        $this->assertSame(get_debug_type($v->int_val), get_debug_type($g->int_val));
    }

    public function test_a_non_diverged_instance_keeps_the_fast_path(): void
    {
        // Diverging one instance must not corrupt the per-class cache for others.
        $diverged = (new GreasedSample)->newFromBuilder($this->sampleRow(['extra' => '1']));
        $diverged->mergeCasts(['extra' => 'integer']);

        $fresh = (new GreasedSample)->newFromBuilder($this->sampleRow());
        $this->assertArrayNotHasKey('extra', $fresh->getCasts());
    }

    public function test_sti_subclasses_isolate_their_casts(): void
    {
        $row = ['id' => 1, 'v' => '1'];

        $gInt = (new StiAsInt)->newFromBuilder($row);
        $gBool = (new StiAsBool)->newFromBuilder($row);

        $this->assertSame(1, $gInt->v, 'subclass cast leaked');
        $this->assertSame(true, $gBool->v, 'subclass cast leaked');
    }

    public function test_string_accessor_and_appends_parity(): void
    {
        $row = ['id' => 1, 'title' => 'hi'];

        $v = (new VanillaAccessor)->newFromBuilder($row);
        $g = (new GreasedAccessor)->newFromBuilder($row);

        $this->assertSame($v->toArray(), $g->toArray());
        $this->assertSame('HI', $g->shout);
    }

    public function test_set_mutator_parity(): void
    {
        $v = new VanillaSetter;
        $g = new GreasedSetter;

        $v->code = 'abc';
        $g->code = 'abc';

        $this->assertSame($v->getAttributes(), $g->getAttributes());
        $this->assertSame('ABC', $g->getAttributes()['code']);
    }

    public function test_flush_rebuilds_the_blueprint(): void
    {
        $before = (new GreasedSample)->newFromBuilder($this->sampleRow())->getCasts();

        GreasedSample::flushGreaseBlueprint();

        $after = (new GreasedSample)->newFromBuilder($this->sampleRow())->getCasts();
        $this->assertEquals($before, $after);
    }
}
