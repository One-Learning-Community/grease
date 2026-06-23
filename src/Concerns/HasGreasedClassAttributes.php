<?php

namespace Grease\Concerns;

use ReflectionClass;

/**
 * Tier — class-attribute resolution (`#[Table]`, `#[Fillable]`, `#[Hidden]`, `#[Appends]`,
 * `#[Connection]`, `#[Touches]`, `#[DateFormat]`, …).
 *
 * Eloquent reads class-level PHP attributes through `Model::resolveClassAttribute()`, which
 * the per-instance `initialize*` trait booters (GuardsAttributes / HidesAttributes /
 * HasAttributes / HasTimestamps / HasRelationships) plus `getTable` / `getConnectionName`
 * call **~13× per `new` model** — so every hydrated row pays it. It *is* cached, but the
 * vanilla cache is keyed by a freshly-concatenated `"$class@$attributeClass"` string built
 * on **every call**: on a 100-parent × 20-child eager load that's ~27k cache-key string
 * allocations a request, and the profile (`benchmarks/eager_excimer.php`) puts the method at
 * ~37% of self-time on the eager/hydration path — the single dominant frame, even with the
 * other tiers on. (A newer L11/L12 feature core hasn't tuned the per-instance cost of.)
 *
 * This tier keeps the resolution byte-for-byte identical and only swaps the cache *shape*:
 * the concatenated string key becomes a two-level `[$class][$attributeClass]` lookup, with
 * the per-class sub-array fetched **once** into a local so the warm path is a single class
 * lookup + one `array_key_exists` — no string built, nothing allocated. (The first cut keyed
 * into the three-level blueprint and *regressed*: the extra level, traversed three times a
 * call, out-cost vanilla's concat — so this uses a flat two-level carve-out instead, which
 * the micro-A/B and the eager profile both confirm is the faster shape.)
 *
 * **Byte-identical, bug-for-bug:** the cold path is verbatim vanilla — the reflection walk up
 * the parent chain, `getAttributes($attributeClass)[0]->newInstance()`, the `$property`
 * extraction, the `catch (\Exception)` swallow, and the `null` memo for an absent attribute.
 * Crucially the key carries the class and attribute but **not** `$property` — exactly like
 * vanilla — so when the same attribute is resolved once with a property and once without
 * (e.g. `#[Table]` via `getTable()` and via the `timestamps` lookup), both calls return
 * whatever the *first* one cached. That quirk is Eloquent's; we reproduce it precisely.
 *
 * A carve-out static rather than a blueprint key (like the `getDateFormat` connection cache):
 * class-level PHP attributes are immutable for a process's lifetime, so this cache never needs
 * invalidation — there is no runtime mutation that could make a cached value wrong. Uses
 * `array_key_exists`, not the tiers' usual `??=`, because `null` (an absent attribute — the
 * overwhelmingly common case) is a real cached value that `??=` would re-resolve every call.
 */
trait HasGreasedClassAttributes
{
    /**
     * Resolved class attributes, keyed `[class][attributeClass]`.
     *
     * @var array<class-string, array<class-string, mixed>>
     */
    protected static array $greaseClassAttributes = [];

    /**
     * Concat-free twin of Eloquent's `resolveClassAttribute()`.
     */
    protected static function resolveClassAttribute(string $attributeClass, ?string $property = null, ?string $class = null)
    {
        $class ??= static::class;

        $cache = static::$greaseClassAttributes[$class] ?? null;

        if ($cache !== null && array_key_exists($attributeClass, $cache)) {
            return $cache[$attributeClass];
        }

        try {
            $reflection = new ReflectionClass($class);

            do {
                $attributes = $reflection->getAttributes($attributeClass);

                if (count($attributes) > 0) {
                    $instance = $attributes[0]->newInstance();

                    return static::$greaseClassAttributes[$class][$attributeClass]
                        = $property ? $instance->{$property} : $instance;
                }
            } while ($reflection = $reflection->getParentClass());
        } catch (\Exception) {
            //
        }

        return static::$greaseClassAttributes[$class][$attributeClass] = null;
    }
}
