<?php

namespace Grease\Tests;

use Closure;
use DateTimeInterface;
use Grease\Concerns\HasGrease;
use Grease\Tests\Fixtures\GreasedDatetimeCast;
use Grease\Tests\Fixtures\GreasedTimestamps;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaDatetimeCast;
use Grease\Tests\Fixtures\VanillaTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Date *casts* (no timestamps) — exercises the addCastAttributesToArray path. */
class VanillaDtCast extends Model
{
    public $timestamps = false;

    protected $table = 'ts';

    protected $casts = [
        'event_at' => 'datetime',
        'archived_at' => 'immutable_datetime',
        'event_day' => 'datetime:Y-m-d',  // custom format → defers
        'born_on' => 'date',              // date cast → defers
    ];
}

class GreasedDtCast extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'ts';

    protected $casts = [
        'event_at' => 'datetime',
        'archived_at' => 'immutable_datetime',
        'event_day' => 'datetime:Y-m-d',
        'born_on' => 'date',
    ];
}

/** A model with timestamps disabled — getDates() is empty, the tier is a no-op. */
class VanillaNoTs extends Model
{
    public $timestamps = false;

    protected $table = 'ts';
}

class GreasedNoTs extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'ts';
}

/** Default everything: timestamps, default serializeDate (toJSON). */
class VanillaTs extends Model
{
    protected $table = 'ts';
}

class GreasedTs extends Model
{
    use HasGrease;

    protected $table = 'ts';
}

/** serializeDate emits the storage format — the "identity" strategy. */
class VanillaStorageFmt extends Model
{
    protected $table = 'ts';

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}

class GreasedStorageFmt extends Model
{
    use HasGrease;

    protected $table = 'ts';

    protected function serializeDate(DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }
}

/** A built-in timestamp ALSO declared as a $casts datetime — both serialize paths run. */
class VanillaCastTs extends Model
{
    protected $table = 'ts';

    protected $casts = ['created_at' => 'datetime:Y-m-d', 'updated_at' => 'datetime'];
}

class GreasedCastTs extends Model
{
    use HasGrease;

    protected $table = 'ts';

    protected $casts = ['created_at' => 'datetime:Y-m-d', 'updated_at' => 'datetime'];
}

/** A custom non-standard dateFormat — must defer (ineligible). */
class VanillaCustomFmt extends Model
{
    protected $table = 'ts';

    protected $dateFormat = 'U';
}

class GreasedCustomFmt extends Model
{
    use HasGrease;

    protected $table = 'ts';

    protected $dateFormat = 'U';
}

/**
 * Tier 4 (HasGreasedSerialization) must keep date serialization byte-identical to
 * vanilla across every configuration — and only engage its fast path where the
 * probe certifies it. Output identity is the contract; the plan assertions prove
 * the fast path is actually exercised (not merely coincidentally matching).
 */
class DateSerializationParityTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        GreasedTs::flushGreaseBlueprint();
        GreasedStorageFmt::flushGreaseBlueprint();
        GreasedCustomFmt::flushGreaseBlueprint();
        GreasedCastTs::flushGreaseBlueprint();
        GreasedNoTs::flushGreaseBlueprint();
        GreasedTimestamps::flushGreaseBlueprint();
        GreasedDtCast::flushGreaseBlueprint();
        GreasedDatetimeCast::flushGreaseBlueprint();
        parent::tearDown();
    }

    /** Invoke the protected plan resolver on a greased instance. */
    private function planOf(Model $m): string|false
    {
        return (fn () => $this->greaseDateSerializePlan())->call($m);
    }

    /** Invoke the protected per-cast-type rewrite resolver on a greased instance. */
    private function castRewriteOf(Model $m, string $type): Closure|false
    {
        return (fn () => $this->greaseDateCastRewrite($type))->call($m);
    }

    private function dtCastRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'event_at' => '2026-03-04 09:10:11',
            'archived_at' => '2024-12-31 23:59:59',
            'event_day' => '2026-03-04 09:10:11',
            'born_on' => '2020-06-15 13:45:30',
        ], $overrides);
    }

    private function assertIdentical(string $vanilla, string $greased, array $row): void
    {
        $v = (new $vanilla)->newFromBuilder($row);
        $g = (new $greased)->newFromBuilder($row);

        $this->assertSame($v->toArray(), $g->toArray());
        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
        $this->assertSame($v->attributesToArray(), $g->attributesToArray());
    }

    private function tsRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'x',
            'created_at' => '2026-03-04 09:10:11',
            'updated_at' => '2024-12-31 23:59:59',
        ], $overrides);
    }

    public function test_utc_default_uses_iso_fastpath_and_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();

        $g = (new GreasedTs)->newFromBuilder($this->tsRow());
        $this->assertSame('utc_iso', $this->planOf($g));

        $this->assertIdentical(VanillaTs::class, GreasedTs::class, $this->tsRow());
    }

    public function test_non_utc_default_defers_and_matches_vanilla(): void
    {
        date_default_timezone_set('America/New_York');
        GreasedTs::flushGreaseBlueprint();

        $g = (new GreasedTs)->newFromBuilder($this->tsRow());
        // Under a non-zero offset the default toJSON converts to UTC, so no fast
        // path is certified — it must defer.
        $this->assertFalse($this->planOf($g));

        $this->assertIdentical(VanillaTs::class, GreasedTs::class, $this->tsRow());
    }

    public function test_storage_format_serializer_uses_identity_in_any_timezone(): void
    {
        foreach (['UTC', 'America/New_York', 'Asia/Kolkata'] as $tz) {
            date_default_timezone_set($tz);
            GreasedStorageFmt::flushGreaseBlueprint();

            $g = (new GreasedStorageFmt)->newFromBuilder($this->tsRow());
            $this->assertSame('identity', $this->planOf($g), "tz=$tz");

            $this->assertIdentical(VanillaStorageFmt::class, GreasedStorageFmt::class, $this->tsRow());
        }
    }

    public function test_custom_dateformat_defers_and_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedCustomFmt::flushGreaseBlueprint();

        // Stored as a unix timestamp string (the 'U' format).
        $row = $this->tsRow(['created_at' => '1772615411', 'updated_at' => '1735689599']);

        $g = (new GreasedCustomFmt)->newFromBuilder($row);
        $this->assertFalse($this->planOf($g));

        $this->assertIdentical(VanillaCustomFmt::class, GreasedCustomFmt::class, $row);
    }

    public function test_shared_bench_fixture_is_byte_identical(): void
    {
        // The exact pair the date-serialization bench times — proven identical here,
        // so the bench measures a round-trip a test guarantees byte-for-byte.
        date_default_timezone_set('UTC');
        GreasedTimestamps::flushGreaseBlueprint();

        $v = (new VanillaTimestamps)->newFromBuilder(SampleData::timestampsRow());
        $g = (new GreasedTimestamps)->newFromBuilder(SampleData::timestampsRow());

        $this->assertSame($v->toArray(), $g->toArray());
        $this->assertSame($v->toJson(), $g->toJson());
    }

    public function test_model_without_timestamps_matches_vanilla(): void
    {
        // getDates() is empty, so the tier's loop is a no-op — still identical.
        date_default_timezone_set('UTC');
        GreasedNoTs::flushGreaseBlueprint();

        $row = ['id' => 1, 'name' => 'x', 'created_at' => '2026-03-04 09:10:11'];
        $v = (new VanillaNoTs)->newFromBuilder($row);
        $g = (new GreasedNoTs)->newFromBuilder($row);

        $this->assertSame($v->toArray(), $g->toArray());
    }

    public function test_shared_datetime_cast_bench_fixture_is_byte_identical(): void
    {
        // The exact pair the cast bench subject times — proven identical here.
        date_default_timezone_set('UTC');
        GreasedDatetimeCast::flushGreaseBlueprint();

        $v = (new VanillaDatetimeCast)->newFromBuilder(SampleData::datetimeCastRow());
        $g = (new GreasedDatetimeCast)->newFromBuilder(SampleData::datetimeCastRow());

        $this->assertSame($v->toArray(), $g->toArray());
        $this->assertSame($v->toJson(), $g->toJson());
    }

    public function test_datetime_casts_use_fastpath_under_utc(): void
    {
        date_default_timezone_set('UTC');
        GreasedDtCast::flushGreaseBlueprint();

        $g = (new GreasedDtCast)->newFromBuilder($this->dtCastRow());

        // The two plain datetime cast types are certified; everything else defers.
        $this->assertInstanceOf(Closure::class, $this->castRewriteOf($g, 'datetime'));
        $this->assertInstanceOf(Closure::class, $this->castRewriteOf($g, 'immutable_datetime'));

        $this->assertIdentical(VanillaDtCast::class, GreasedDtCast::class, $this->dtCastRow());
    }

    public function test_datetime_casts_defer_under_non_utc(): void
    {
        date_default_timezone_set('America/New_York');
        GreasedDtCast::flushGreaseBlueprint();

        $g = (new GreasedDtCast)->newFromBuilder($this->dtCastRow());
        $this->assertFalse($this->castRewriteOf($g, 'datetime'));
        $this->assertFalse($this->castRewriteOf($g, 'immutable_datetime'));

        $this->assertIdentical(VanillaDtCast::class, GreasedDtCast::class, $this->dtCastRow());
    }

    public function test_custom_format_and_date_casts_always_defer(): void
    {
        // event_day (datetime:Y-m-d) and born_on (date) are never fast-pathed —
        // they must come out exactly as vanilla in every zone.
        foreach (['UTC', 'America/New_York'] as $tz) {
            date_default_timezone_set($tz);
            GreasedDtCast::flushGreaseBlueprint();

            $v = (new VanillaDtCast)->newFromBuilder($this->dtCastRow());
            $g = (new GreasedDtCast)->newFromBuilder($this->dtCastRow());

            $this->assertSame($v->toArray()['event_day'], $g->toArray()['event_day'], "event_day tz=$tz");
            $this->assertSame($v->toArray()['born_on'], $g->toArray()['born_on'], "born_on tz=$tz");
            $this->assertSame($v->toArray(), $g->toArray(), "full tz=$tz");
        }
    }

    public function test_carbon_instance_in_datetime_cast_defers(): void
    {
        date_default_timezone_set('UTC');
        GreasedDtCast::flushGreaseBlueprint();

        $instant = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-04 09:10:11');

        $v = (new VanillaDtCast)->newFromBuilder($this->dtCastRow());
        $g = (new GreasedDtCast)->newFromBuilder($this->dtCastRow());
        $v->event_at = clone $instant;
        $g->event_at = clone $instant;

        $this->assertSame($v->toArray(), $g->toArray());
    }

    public function test_runtime_withcasts_divergence_matches_vanilla(): void
    {
        // A runtime-added/overridden cast must still serialize identically — the
        // override reads live getCasts(), so it sees the diverged map.
        date_default_timezone_set('UTC');
        GreasedDtCast::flushGreaseBlueprint();

        $row = $this->dtCastRow(['extra_at' => '2021-07-08 01:02:03']);

        // (a) add a brand-new datetime cast at runtime
        $v = (new VanillaDtCast)->newFromBuilder($row)->mergeCasts(['extra_at' => 'datetime']);
        $g = (new GreasedDtCast)->newFromBuilder($row)->mergeCasts(['extra_at' => 'datetime']);
        $this->assertSame($v->toArray(), $g->toArray(), 'added datetime cast');

        // (b) override an existing datetime cast to a different type at runtime
        $v2 = (new VanillaDtCast)->newFromBuilder($this->dtCastRow())->mergeCasts(['event_at' => 'date']);
        $g2 = (new GreasedDtCast)->newFromBuilder($this->dtCastRow())->mergeCasts(['event_at' => 'date']);
        $this->assertSame($v2->toArray(), $g2->toArray(), 'overridden to date cast');
    }

    public function test_zero_offset_non_utc_zones_use_iso_fastpath(): void
    {
        // Zones that are zero-offset year-round with no DST produce byte-identical
        // toJSON output to UTC, so the probe correctly certifies utc_iso for them —
        // the fast path is not literally UTC-name-gated, it's output-gated.
        foreach (['Etc/UTC', 'Africa/Abidjan', 'Atlantic/Reykjavik'] as $tz) {
            date_default_timezone_set($tz);
            GreasedTs::flushGreaseBlueprint();

            $g = (new GreasedTs)->newFromBuilder($this->tsRow());
            $this->assertSame('utc_iso', $this->planOf($g), "tz=$tz");

            $this->assertIdentical(VanillaTs::class, GreasedTs::class, $this->tsRow());
        }
    }

    public function test_dst_zones_defer_in_both_hemispheres(): void
    {
        // Any zone with a non-zero or seasonally-varying offset must fail the probe
        // (the default toJSON converts to UTC) and defer.
        foreach (['America/New_York', 'Europe/London', 'Australia/Sydney', 'Asia/Kolkata'] as $tz) {
            date_default_timezone_set($tz);
            GreasedTs::flushGreaseBlueprint();

            $g = (new GreasedTs)->newFromBuilder($this->tsRow());
            $this->assertFalse($this->planOf($g), "tz=$tz");

            $this->assertIdentical(VanillaTs::class, GreasedTs::class, $this->tsRow());
        }
    }

    public function test_timestamp_also_declared_as_cast_matches_vanilla(): void
    {
        // created_at is BOTH a getDates() timestamp (Tier 4) AND a $casts datetime
        // (vanilla's addCastAttributesToArray). The fast path must produce exactly
        // what the cast path then consumes, so the doubly-processed output stays
        // identical to vanilla.
        foreach (['UTC', 'America/New_York'] as $tz) {
            date_default_timezone_set($tz);
            GreasedCastTs::flushGreaseBlueprint();

            $this->assertIdentical(VanillaCastTs::class, GreasedCastTs::class, $this->tsRow());
        }
    }

    public function test_microsecond_value_defers_per_value_and_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();

        // Sub-second precision: the strict shape guard rejects the fast rewrite,
        // so this individual value falls back to the exact vanilla composition.
        $this->assertIdentical(
            VanillaTs::class, GreasedTs::class,
            $this->tsRow(['created_at' => '2026-03-04 09:10:11.123456'])
        );
    }

    public function test_date_only_value_defers_and_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();

        $this->assertIdentical(
            VanillaTs::class, GreasedTs::class,
            $this->tsRow(['created_at' => '2026-03-04'])
        );
    }

    public function test_carbon_instance_value_defers_and_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();

        $instant = Carbon::createFromFormat('Y-m-d H:i:s', '2026-03-04 09:10:11');

        $v = (new VanillaTs)->newFromBuilder($this->tsRow());
        $g = (new GreasedTs)->newFromBuilder($this->tsRow());
        $v->created_at = clone $instant;
        $g->created_at = clone $instant;

        $this->assertSame($v->toArray(), $g->toArray());
    }

    public function test_null_timestamp_matches_vanilla(): void
    {
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();

        $this->assertIdentical(
            VanillaTs::class, GreasedTs::class,
            $this->tsRow(['updated_at' => null])
        );
    }

    public function test_plan_does_not_go_stale_across_a_timezone_change(): void
    {
        // Build the plan under UTC (certifies utc_iso)...
        date_default_timezone_set('UTC');
        GreasedTs::flushGreaseBlueprint();
        $g = (new GreasedTs)->newFromBuilder($this->tsRow());
        $this->assertSame('utc_iso', $this->planOf($g));

        // ...then switch zones WITHOUT flushing. The plan is keyed by timezone, so
        // the new zone resolves a freshly-certified (deferring) plan, and output
        // still matches vanilla under the new zone.
        date_default_timezone_set('America/New_York');
        $g2 = (new GreasedTs)->newFromBuilder($this->tsRow());
        $this->assertFalse($this->planOf($g2));

        $this->assertIdentical(VanillaTs::class, GreasedTs::class, $this->tsRow());
    }
}
