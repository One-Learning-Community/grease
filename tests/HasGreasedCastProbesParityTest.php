<?php

namespace Grease\Tests;

use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaSample;
use ReflectionMethod;

/**
 * {@see \Grease\Concerns\HasGreasedCastProbes} memoizes the per-key cast-classification
 * probes (`isEnumCastable` / `isClassCastable` / `isClassSerializable`) that
 * `addCastAttributesToArray()` runs on every row. The contract is byte-for-byte identical
 * classification: for every cast key — primitive, date, enum, custom-class — the greased
 * probe returns exactly what vanilla returns, the cached `false` is a real hit (not a
 * re-probe), and a runtime cast divergence is reflected, never answered stale. Vanilla's own
 * probe is the oracle.
 */
class HasGreasedCastProbesParityTest extends TestCase
{
    private const PROBES = ['isEnumCastable', 'isClassCastable', 'isClassSerializable'];

    public function test_probes_match_vanilla_across_every_cast_kind(): void
    {
        // DefinesSampleCasts spans primitives, dates, an enum (Status), and a custom class
        // (UpperCast) — the full classification surface. Plus a non-cast key (no entry).
        $keys = array_merge(array_keys((new VanillaSample)->getCasts()), ['name', 'missing']);

        foreach (self::PROBES as $probe) {
            foreach ($keys as $key) {
                $this->assertSame(
                    $this->probe(new VanillaSample, $probe, $key),
                    $this->probe(new GreasedSample, $probe, $key),
                    "$probe('$key')",
                );
            }
        }
    }

    public function test_cached_false_is_a_real_hit_not_a_reprobe(): void
    {
        // A primitive key classifies false on all three probes — and false must be MEMOIZED
        // (array_key_exists semantics), not re-resolved like a ??= would. Two calls, stable.
        $m = new GreasedSample;

        foreach (self::PROBES as $probe) {
            $first = $this->probe($m, $probe, 'int_val');
            $second = $this->probe($m, $probe, 'int_val');

            $this->assertFalse($first, "$probe('int_val') is false");
            $this->assertSame($first, $second);
        }

        $cache = (new \ReflectionProperty(GreasedSample::class, 'greaseBlueprint'))->getValue();
        $this->assertArrayHasKey('isClassCastable', $cache[GreasedSample::class]);
        $this->assertArrayHasKey('int_val', $cache[GreasedSample::class]['isClassCastable']);
        $this->assertFalse($cache[GreasedSample::class]['isClassCastable']['int_val'], 'false is cached, not absent');
    }

    public function test_enum_and_custom_class_keys_classify_like_vanilla(): void
    {
        // The keys where the probes return TRUE — the cache must hold true just as faithfully.
        $vanilla = new VanillaSample;
        $greased = new GreasedSample;

        $this->assertSame($this->probe($vanilla, 'isEnumCastable', 'status_val'), $this->probe($greased, 'isEnumCastable', 'status_val'));
        $this->assertTrue($this->probe($greased, 'isEnumCastable', 'status_val'), 'Status enum cast → isEnumCastable true');

        $this->assertSame($this->probe($vanilla, 'isClassCastable', 'upper_val'), $this->probe($greased, 'isClassCastable', 'upper_val'));
        $this->assertTrue($this->probe($greased, 'isClassCastable', 'upper_val'), 'UpperCast custom cast → isClassCastable true');
    }

    public function test_runtime_cast_divergence_is_reflected_not_stale(): void
    {
        // Reuse the casts-divergence guard: a runtime mergeCasts() that changes a key's cast
        // type must make the probes resolve live, exactly like a diverged getCasts(). Warm the
        // cache first (key absent → all false), then diverge the same key to an enum cast.
        $greased = new GreasedSample;
        $this->assertFalse($this->probe($greased, 'isEnumCastable', 'free_key'));

        $greased->mergeCasts(['free_key' => \Grease\Tests\Fixtures\Status::class]);
        $vanilla = new VanillaSample;
        $vanilla->mergeCasts(['free_key' => \Grease\Tests\Fixtures\Status::class]);

        $this->assertSame(
            $this->probe($vanilla, 'isEnumCastable', 'free_key'),
            $this->probe($greased, 'isEnumCastable', 'free_key'),
        );
        $this->assertTrue($this->probe($greased, 'isEnumCastable', 'free_key'), 'diverged key resolves live, not the cached false');
    }

    public function test_full_toarray_is_byte_identical_with_enum_and_custom_casts(): void
    {
        // End to end: the probes are exercised through the real array builder. A model with an
        // enum + custom cast must serialize byte-identically to vanilla.
        // newFromBuilder = hydration (setRawAttributes), so the raw DB values are stored as-is
        // and cast only on the way out — never re-running a SET cast (the `hashed` cast re-salts
        // per call, so a SET-based build would diverge on its own, independent of Grease).
        $row = SampleData::row();

        $vanilla = (new VanillaSample)->newFromBuilder($row);
        $greased = (new GreasedSample)->newFromBuilder($row);

        // json_encode is the byte-identical contract the realworld probe uses — it compares
        // serialized output structurally (object casts like `object` yield distinct stdClass
        // instances that assertSame would reject on identity, not on content).
        $this->assertSame(json_encode($vanilla->toArray()), json_encode($greased->toArray()));
    }

    /** Invoke a protected cast-probe via reflection. */
    private function probe(object $model, string $method, string $key): bool
    {
        return (new ReflectionMethod($model, $method))->invoke($model, $key);
    }
}
