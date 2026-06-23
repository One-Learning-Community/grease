<?php

namespace Grease\Concerns;

/**
 * Tier — cast-classification probe memoization.
 *
 * `attributesToArray()` → `addCastAttributesToArray()` loops over every cast on every row
 * and, per key, asks the framework to *classify* the cast: `isEnumCastable()`,
 * `isClassCastable()`, `isClassSerializable()`. Each walks `parseCasterClass` +
 * `in_array($primitiveCastTypes)` + `class_exists`/`enum_exists`/`is_subclass_of`/
 * `method_exists` — and `isClassSerializable()` re-runs the other two internally. On the
 * realworld `index_users`/`posts_with_author` profile (rich casts: boolean / decimal:2 /
 * array / datetime) those three probes are **~10% cumulative self-time**, recomputed for a
 * verdict that never changes: the answer is a pure function of `getCasts()[$key]`, exactly
 * like `getCastType()` (which {@see HasGreasedAttributes} already memoizes).
 *
 * This tier memoizes each probe per `[class][key]`. Vanilla calls them as `$this->is…()`,
 * so these overrides are hit even from *inside* the `parent::addCastAttributesToArray()`
 * loop that {@see HasGreasedSerialization} delegates to — the win lands without
 * reimplementing the array builder.
 *
 * **Byte-identical:** the cold path is verbatim `parent::`. Two correctness points:
 *   - The cache uses `array_key_exists`, **not** `??=` — the overwhelmingly common verdict is
 *     `false`, and `??=` would treat that as "unset" and re-probe every row (the same
 *     `null`-memo trap {@see HasGreasedClassAttributes} documents).
 *   - A diverged instance (runtime `mergeCasts()`/`withCasts()` changed its casts) defers to
 *     live `parent::` resolution, reusing the `greaseCastsDiverged` flag from
 *     {@see HasGreasedAttributes} — so a key whose cast type changed at runtime is never
 *     answered from the per-class cache. `isClassCastable()` can throw `InvalidCastException`
 *     for an invalid cast; that throws on the cold path before anything is cached, exactly as
 *     vanilla, and re-throws next row.
 */
trait HasGreasedCastProbes
{
    use InteractsWithGreaseBlueprint;

    protected function isEnumCastable($key)
    {
        return $this->greaseCastProbe(__FUNCTION__, $key);
    }

    protected function isClassCastable($key)
    {
        return $this->greaseCastProbe(__FUNCTION__, $key);
    }

    protected function isClassSerializable($key)
    {
        return $this->greaseCastProbe(__FUNCTION__, $key);
    }

    /**
     * Memoize a class-pure cast-classification probe per `[class][probe][key]`.
     * `array_key_exists` (not `??=`) so a cached `false` is a real hit, not a re-probe.
     */
    private function greaseCastProbe(string $probe, string $key): bool
    {
        if ($this->greaseCastsDiverged) {
            return parent::{$probe}($key);
        }

        $cache = static::$greaseBlueprint[static::class][$probe] ?? null;

        if ($cache !== null && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return static::$greaseBlueprint[static::class][$probe][$key] = parent::{$probe}($key);
    }
}
