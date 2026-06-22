<?php

namespace Grease\View;

use Illuminate\Support\Str;
use Illuminate\View\AppendableAttributeValue;
use Illuminate\View\ComponentAttributeBag as BaseComponentAttributeBag;

/**
 * A drop-in faster attribute bag — overrides the one hot method, `merge()`, that every
 * component with an `@props` block calls on each render (`$attributes->merge([...])`).
 * {@see \Grease\View\Props::mergeAttributes()} hands the component its surviving
 * attributes as one of these instead of a vanilla bag, so the template's merge runs the
 * tight path; `merge()` returns `new static`, so the type stays greased down any chain.
 *
 * Vanilla's `merge()` does the job through the Collection pipeline: `new Collection`,
 * `partition()` (two more Collections + a per-attribute closure), `mapWithKeys()`
 * (another Collection + `Arr::mapWithKeys`), `->merge()` (a Collection plus
 * `getArrayableItems`/`Arr::from`), then `->all()`. That's ~5 Collection allocations and
 * as many `Arr::*` walks per render — the single biggest Collection source in a component
 * render (see the profile in benchmarks/blade_profile.php). This override does the exact
 * same partition + append + final merge with two plain `foreach` loops and no Collections.
 *
 * Byte-identical to vanilla: same default-escaping predicate, the same appendable vs.
 * non-appendable split (`class`/`style`/`AppendableAttributeValue` default), appendable
 * keys emitted before non-appendable ones (vanilla's `mapWithKeys()->merge()` order), and
 * the same `array_merge($defaults, $attributes)` final shape — so attribute order, and
 * therefore the rendered string, is preserved exactly. The parity suite asserts it.
 */
class ComponentAttributeBag extends BaseComponentAttributeBag
{
    /**
     * {@inheritDoc}
     *
     * Collection-free reimplementation of the vanilla merge. Every branch mirrors the
     * parent's, in the parent's order, so the resulting attribute array — and the HTML it
     * stringifies to — is identical.
     */
    public function merge(array $attributeDefaults = [], $escape = true)
    {
        // Escape the default values, same predicate as the parent's array_map.
        foreach ($attributeDefaults as $key => $value) {
            if ($this->shouldEscapeAttributeValue($escape, $value)) {
                $attributeDefaults[$key] = e($value);
            }
        }

        // Partition the live attributes, computing the appended value inline. Appendable
        // keys are collected first, then non-appendable — matching vanilla's
        // mapWithKeys()->merge($nonAppendable) ordering (the two key sets are disjoint).
        $appendable = [];
        $nonAppendable = [];

        foreach ($this->attributes as $key => $value) {
            $default = $attributeDefaults[$key] ?? null;

            if ($key === 'class' || $key === 'style' || $default instanceof AppendableAttributeValue) {
                $defaultsValue = $default instanceof AppendableAttributeValue
                    ? $this->resolveAppendableAttributeDefault($attributeDefaults, $key, $escape)
                    : ($default ?? '');

                if ($key === 'style') {
                    $value = Str::finish($value, ';');
                }

                $appendable[$key] = implode(' ', array_unique(array_filter([$defaultsValue, $value])));
            } else {
                $nonAppendable[$key] = $value;
            }
        }

        // $appendable + $nonAppendable: disjoint keys, so union preserves both orders.
        return new static(array_merge($attributeDefaults, $appendable + $nonAppendable));
    }
}
