<?php

namespace Grease\Concerns;

use Grease\ClosureCast;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Tier 3 — narrowed cast dispatch.
 *
 * Replaces Eloquent's per-access `switch` in `castAttribute()` with a flyweight
 * resolved once per cast type. The value transform still defers to the model at
 * call time, so `asDate`/`fromJson`/etc. overrides — and `getCastType()`
 * overrides, which still drive the type decision — are honored.
 *
 * THE NARROWING (the reason this is opt-in, asserted in tests): a per-key
 * `isEncryptedCastable()` override is not honored — encryption is decided from
 * the built-in encrypted cast types, not re-derived per key. Use an `encrypted:*`
 * cast (the idiomatic way), which works. And a model that assigns a *different*
 * `$casts` per instance in its constructor isn't supported (the map is cached per
 * class); use `mergeCasts()`/`withCasts()` at runtime instead. Custom casts
 * (`CastsAttributes`) — the documented extension point — work unchanged.
 *
 * Built-in primitive/date/json casts are accelerated here; enum, custom-class,
 * and encrypted casts defer to the framework's own (correct) handling.
 */
trait HasGreasedCasts
{
    use InteractsWithGreaseBlueprint;

    /** Built-in cast flyweights, shared and keyed by normalized cast type. */
    protected static array $greaseCasters = [];

    protected function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes, true)) {
            return $value;
        }

        $caster = static::$greaseCasters[$castType] ??= $this->greaseBuildCaster($castType);

        if ($caster !== null) {
            return $caster->get($this, $key, $value, $this->attributes);
        }

        // enum / custom-class / encrypted — outside the built-in subset; defer to
        // the framework's flyweight-free handling (still correct, just unaccelerated).
        return parent::castAttribute($key, $value);
    }

    /**
     * Build the shared flyweight for a built-in cast type, or null if this tier
     * does not accelerate it.
     */
    protected function greaseBuildCaster($castType): ?ClosureCast
    {
        return match ($castType) {
            'int', 'integer' => new ClosureCast(static fn ($m, $k, $v) => (int) $v),
            'real', 'float', 'double' => new ClosureCast(static fn ($m, $k, $v) => $m->fromFloat($v)),
            'decimal' => new ClosureCast(static fn ($m, $k, $v) => $m->asDecimal($v, explode(':', $m->getCasts()[$k], 2)[1])),
            'string' => new ClosureCast(static fn ($m, $k, $v) => (string) $v),
            'bool', 'boolean' => new ClosureCast(static fn ($m, $k, $v) => (bool) $v),
            'object' => new ClosureCast(static fn ($m, $k, $v) => $m->fromJson($v, true)),
            'array', 'json', 'json:unicode' => new ClosureCast(static fn ($m, $k, $v) => $m->fromJson($v)),
            'collection' => new ClosureCast(static fn ($m, $k, $v) => new BaseCollection($m->fromJson($v))),
            'date' => new ClosureCast(static fn ($m, $k, $v) => $m->asDate($v)),
            'datetime', 'custom_datetime' => new ClosureCast(static fn ($m, $k, $v) => $m->asDateTime($v)),
            'immutable_date' => new ClosureCast(static fn ($m, $k, $v) => $m->asDate($v)->toImmutable()),
            'immutable_custom_datetime', 'immutable_datetime' => new ClosureCast(static fn ($m, $k, $v) => $m->asDateTime($v)->toImmutable()),
            'timestamp' => new ClosureCast(static fn ($m, $k, $v) => $m->asTimestamp($v)),
            default => null,
        };
    }
}
