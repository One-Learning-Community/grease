<?php

namespace Grease\View;

use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

/**
 * The `@props` resolution Blade hand-inlines into every compiled component, lifted into
 * one helper. Vanilla's emit is wasteful three ways on the per-render hot path: it
 * rebuilds a flat name list (`ComponentAttributeBag::extractPropNames`) and scans it
 * with `in_array` (O(props × attributes)); it evaluates the whole `@props` array
 * literal *twice* (once for the names, once for `array_filter(..., 'is_string')` to find
 * the defaults); and it snapshots the entire scope with `get_defined_vars()` to unset
 * attribute-named locals.
 *
 * {@see \Grease\View\Compiler::compileProps()} evaluates the declaration once and calls
 * {@see mergeAttributes()}, which does the whole job — partition, defaults, surviving bag
 * — and returns one map the compiler binds with a tight `$$key = $value` loop: the
 * resolved prop locals plus `attributes` (the non-prop bag) as just another key. The loop
 * (not `extract`, which is slower and skips non-identifier keys) reproduces vanilla's
 * locals exactly, including the inaccessible `${'icon-name'}` kebab-alias local.
 *
 * Internally the name set is the same membership `extractPropNames` builds (every name
 * plus kebab alias), shaped as a set for `isset()`; the static shape (names + which keys
 * carry defaults) is memoized per `@props` site, so only the fresh default *values* are
 * read each render. The parity suite asserts the compiled block resolves the same props
 * and leaves the same attributes as vanilla.
 */
class Props
{
    /**
     * Per-site analysis of an `@props` declaration: the keyed name set, and the set of
     * string keys (the ones that carry defaults). Both are compile-time constants for a
     * given site, so they're built once and reused across every render of the component.
     *
     * @var array<string, array{names: array<string, true>, stringKeys: array<string, true>}>
     */
    protected static array $meta = [];

    /**
     * The whole `@props` resolution in one call: partition the incoming attributes into
     * props (consumed) and the rest, apply defaults to the string-keyed props, and hand
     * back a single map ready to `extract()` into the component scope — the resolved prop
     * locals plus `attributes` (the surviving, non-prop bag). Replaces the entire inlined
     * block the compiler used to emit.
     *
     * Equivalent to vanilla's partition + defaults, with one wrinkle the caller accepts by
     * using `extract()`: a prop reached via a *non-identifier* key (the kebab alias, e.g.
     * `icon-name`) lands in the map but `extract()` skips it — exactly as vanilla's value
     * landed in an inaccessible `${'icon-name'}` local. The rendered output is identical;
     * only the (unusable) junk local differs.
     *
     * @param  string  $site  stable id for this `@props` location (compile-time constant)
     * @param  array<array-key, mixed>  $declaration  the `@props` array (one evaluation)
     * @return array<string, mixed>  prop locals + `attributes`, ready for `extract()`
     */
    public static function mergeAttributes(string $site, array $declaration, ComponentAttributeBag $attributes): array
    {
        $meta = static::$meta[$site] ??= static::analyze($declaration);
        $names = $meta['names'];

        $props = [];
        $rest = [];

        foreach ($attributes->getAttributes() as $key => $value) {
            if (isset($names[$key])) {
                $props[$key] = $value;
            } else {
                $rest[$key] = $value;
            }
        }

        // Defaults (`?? `): a string-keyed prop not passed (or passed null) takes its
        // declaration value. Cached key-structure, fresh values — no per-render is_string.
        foreach ($meta['stringKeys'] as $key => $unused) {
            $props[$key] ??= $declaration[$key];
        }

        $props['attributes'] = new ComponentAttributeBag($rest);

        return $props;
    }

    /**
     * Analyze a declaration's static shape: every prop name (plus kebab alias) as a
     * lookup set, and which keys are string-keyed (i.e. carry a default). Mirrors
     * `extractPropNames` — list-style keys take the value as the name — and calls
     * `Str::snake` once (`kebab` is just `snake($v, '-')`), the set deduping collisions.
     *
     * @param  array<array-key, mixed>  $declaration
     * @return array{names: array<string, true>, stringKeys: array<string, true>}
     */
    protected static function analyze(array $declaration): array
    {
        $names = [];
        $stringKeys = [];

        foreach ($declaration as $key => $default) {
            if (is_numeric($key)) {
                $name = $default;            // list-style: the value is the name
            } else {
                $name = $key;
                $stringKeys[$key] = true;    // string-keyed: carries a default
            }

            $names[$name] = true;
            $names[Str::snake($name, '-')] = true;
        }

        return ['names' => $names, 'stringKeys' => $stringKeys];
    }

    /**
     * Drop the memo. For tests that compile many throwaway `@props` sites; never needed
     * in a running app (the maps are tiny and bounded by component count).
     */
    public static function flush(): void
    {
        static::$meta = [];
    }
}
