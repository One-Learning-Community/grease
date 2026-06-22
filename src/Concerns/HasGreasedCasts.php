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
 * Built-in primitive/date/json casts are accelerated here, and enum casts get a
 * fast path that skips the redundant parent:: re-walk while delegating the actual
 * conversion to the framework (so backed/pure/null/invalid handling is identical).
 * Custom-class (CastsAttributes) and encrypted casts still defer to the framework's
 * own (correct) handling — class-castable reads are already object-cached and
 * encrypted reads are decryption-bound, so a flyweight buys ~nothing there.
 */
trait HasGreasedCasts
{
    use InteractsWithGreaseBlueprint;

    /** Built-in cast flyweights, shared and keyed by normalized cast type. */
    protected static array $greaseCasters = [];

    /**
     * Per-process map: resolved cast type -> whether it is an enum. Keyed by the
     * type string (a class name), not by `[class][key]`, so it is divergence- and
     * STI-safe for free — an enum class either exists or it doesn't, regardless of
     * which model or instance uses it.
     */
    protected static array $greaseEnumTypes = [];

    /**
     * Synonym cast types that resolve to an identical read flyweight. Folding
     * them onto one canonical key means `real`/`float`/`double` (etc.) share a
     * single `ClosureCast` instead of building three byte-identical copies. The
     * flyweights are stateless and the synonym arms in `greaseBuildCaster()` are
     * the same closure, so this is observationally identical — pure dedup.
     * `decimal` is deliberately absent: it carries a per-call precision parameter
     * and is its own canonical key.
     */
    private const GREASE_CAST_ALIASES = [
        'integer' => 'int',
        'real' => 'float',
        'double' => 'float',
        'boolean' => 'bool',
        'array' => 'json',
        'json:unicode' => 'json',
        'custom_datetime' => 'datetime',
        'immutable_custom_datetime' => 'immutable_datetime',
    ];

    protected function castAttribute($key, $value)
    {
        $castType = $this->getCastType($key);

        if (is_null($value) && in_array($castType, static::$primitiveCastTypes, true)) {
            return $value;
        }

        $canonical = self::GREASE_CAST_ALIASES[$castType] ?? $castType;
        $caster = static::$greaseCasters[$canonical] ??= $this->greaseBuildCaster($canonical);

        if ($caster !== null) {
            return $caster->get($this, $key, $value, $this->attributes);
        }

        // Enum fast path. Keyed by the *resolved* cast type (a class name), exactly
        // like $greaseCasters, so it is divergence-safe for free: a runtime cast
        // change moves getCastType($key) to a different entry. The conversion is
        // delegated to the framework's own getEnumCastableAttributeValue(), so
        // backed/pure/instanceof/null/invalid handling stays byte-identical — we
        // only skip the redundant parent:: re-walk (2nd getCastType, the encrypted
        // probe, the 14-arm switch, and isEnumCastable).
        if (static::$greaseEnumTypes[$castType] ??= enum_exists($castType)) {
            return $this->getEnumCastableAttributeValue($key, $value);
        }

        // custom-class (CastsAttributes) / encrypted — outside the built-in subset;
        // defer to the framework's handling (still correct, just unaccelerated).
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
