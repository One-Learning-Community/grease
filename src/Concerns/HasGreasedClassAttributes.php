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
 * the concatenated `"$class@$attributeClass@$property"` key becomes a nested
 * `[$class][$attributeClass][$property]` lookup, fetched to the per-attribute leaf in **one**
 * `?? null` expression so the warm path is a single hash walk + one `array_key_exists` — no
 * string built, nothing allocated. (The first cut keyed into the blueprint and re-traversed
 * the levels three times a call, which *regressed* against vanilla's concat; a single fetch is
 * what wins — `benchmarks/class_attribute_ab.php`, ~−11% warm.)
 *
 * **Byte-identical:** the cold path is verbatim vanilla — the reflection walk up the parent
 * chain (checking each class's own attributes, then its traits'), `getAttributes(
 * $attributeClass)[0]->newInstance()`, the `$property` extraction, the `catch (\Exception)`
 * swallow, and the `null` memo for an absent attribute. Like vanilla (since
 * laravel/framework#60815) the key carries the property too — `[$class][$attributeClass]
 * [$property ?? '']` — so `#[Table]` resolved via `getTable()` and via the `timestamps` lookup
 * are cached independently. (`null` and `''` share a slot, exactly as vanilla's `'@'.$property`
 * concat makes them; `?? ''` also keeps a `null` array offset out of PHP 8.5's deprecation.)
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
     * Resolved class attributes, keyed `[class][attributeClass][property]`.
     *
     * @var array<class-string, array<class-string, array<string, mixed>>>
     */
    protected static array $greaseClassAttributes = [];

    /**
     * Concat-free twin of Eloquent's `resolveClassAttribute()`.
     */
    protected static function resolveClassAttribute(string $attributeClass, ?string $property = null, ?string $class = null)
    {
        $class ??= static::class;
        $key = $property ?? '';

        $cache = static::$greaseClassAttributes[$class][$attributeClass] ?? null;

        if ($cache !== null && array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $reflection = new ReflectionClass($class);

            do {
                $attributes = $reflection->getAttributes($attributeClass);

                if (count($attributes) > 0) {
                    $instance = $attributes[0]->newInstance();

                    return static::$greaseClassAttributes[$class][$attributeClass][$key]
                        = $property ? $instance->{$property} : $instance;
                }

                foreach ($reflection->getTraits() as $trait) {
                    $attributes = $trait->getAttributes($attributeClass);

                    if (count($attributes) > 0) {
                        $instance = $attributes[0]->newInstance();

                        return static::$greaseClassAttributes[$class][$attributeClass][$key]
                            = $property ? $instance->{$property} : $instance;
                    }
                }
            } while ($reflection = $reflection->getParentClass());
        } catch (\Exception) {
            //
        }

        return static::$greaseClassAttributes[$class][$attributeClass][$key] = null;
    }
}
