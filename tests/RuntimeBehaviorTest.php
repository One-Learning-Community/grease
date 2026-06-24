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

// --- Timestamps toggle (getDates per-instance input). --------------------------
class VanillaTimestampsModel extends Model
{
    protected $table = 'tsm';
}
class GreasedTimestampsModel extends Model
{
    use HasGrease;

    protected $table = 'tsm';
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

    public function test_getdates_memo_is_not_poisoned_by_a_timestamps_disabled_instance(): void
    {
        // $model->timestamps = false / withoutTimestamps() is normal Eloquent. A
        // timestamps-off instance must not poison the per-class getDates() cache for
        // timestamps-on instances — that would drop created_at/updated_at from toArray().
        GreasedTimestampsModel::flushGreaseBlueprint();

        $off = new GreasedTimestampsModel;
        $off->timestamps = false;
        $this->assertSame([], $off->getDates(), 'timestamps-off instance has no dates');

        $on = new GreasedTimestampsModel;          // timestamps default on
        $vanilla = new VanillaTimestampsModel;

        $this->assertSame($vanilla->getDates(), $on->getDates(), 'timestamps-on instance must still date its timestamps');
        $this->assertSame(['created_at', 'updated_at'], $on->getDates());
    }

    public function test_getcasts_is_not_frozen_by_runtime_setkeyname(): void
    {
        // primaryKey/keyType/incrementing are class properties in normal use (read
        // correctly at first getCasts). The runtime setters are unusual, but if used
        // they must still invalidate the per-class cast cache, not serve a stale key.
        $g = (new GreasedSample)->newFromBuilder($this->sampleRow());
        $g->getCasts();                       // warm the blueprint with the default 'id' key
        $g->setKeyName('uuid');

        $v = (new VanillaSample)->newFromBuilder($this->sampleRow());
        $v->setKeyName('uuid');

        $this->assertSame($v->getCasts(), $g->getCasts(), 'stale key entry after setKeyName');
        $this->assertArrayHasKey('uuid', $g->getCasts());
        $this->assertArrayNotHasKey('id', $g->getCasts());
    }

    public function test_getcasts_is_not_frozen_by_runtime_setincrementing(): void
    {
        $g = (new GreasedSample)->newFromBuilder($this->sampleRow());
        $g->getCasts();
        $g->setIncrementing(false);

        $v = (new VanillaSample)->newFromBuilder($this->sampleRow());
        $v->setIncrementing(false);

        $this->assertSame($v->getCasts(), $g->getCasts(), 'stale key entry after setIncrementing(false)');
        $this->assertArrayNotHasKey('id', $g->getCasts(), 'a non-incrementing model drops the key cast');
    }

    public function test_a_diverged_key_mutation_does_not_corrupt_other_instances(): void
    {
        // setKeyName is per-instance — it must not leak into the per-class cache.
        $diverged = (new GreasedSample)->newFromBuilder($this->sampleRow());
        $diverged->getCasts();
        $diverged->setKeyName('uuid');
        $diverged->getCasts();

        $fresh = (new GreasedSample)->newFromBuilder($this->sampleRow());
        $this->assertArrayHasKey('id', $fresh->getCasts(), 'fresh instance keeps the default key');
        $this->assertArrayNotHasKey('uuid', $fresh->getCasts());
    }

    public function test_flush_rebuilds_the_blueprint(): void
    {
        $before = (new GreasedSample)->newFromBuilder($this->sampleRow())->getCasts();

        GreasedSample::flushGreaseBlueprint();

        $after = (new GreasedSample)->newFromBuilder($this->sampleRow())->getCasts();
        $this->assertEquals($before, $after);
    }
}
