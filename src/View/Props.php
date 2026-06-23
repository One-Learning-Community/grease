<?php

namespace Grease\View;

use Grease\View\ComponentAttributeBag as GreaseComponentAttributeBag;
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
 * {@see mergeAttributes()}, which does the partition, defaults and surviving bag — and
 * returns the prop *candidates*, the `attributes` bag, and the surviving keys. The compiler
 * binds the candidates with a tight `$$key = $$key ?? $value` loop (deferring to any existing
 * scope local, exactly as vanilla's `get_defined_vars()`-aware emit did — an `@include`'d
 * `['propValue' => 1]` still wins over the declared default), then `unset()`s any local a
 * pass-through attribute shadows. The loop (not `extract`, which is slower and skips
 * non-identifier keys) reproduces vanilla's locals exactly, including the inaccessible
 * `${'icon-name'}` kebab-alias local.
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
     * The partitioning half of `@props` resolution in one call: split the incoming
     * attributes into props (consumed) and the rest, apply defaults to the string-keyed
     * props, and hand back the three pieces the compiler's emit binds into scope — the
     * resolved prop *candidate* values, the surviving `attributes` bag, and the surviving
     * (non-prop) keys. The surviving bag is a {@see GreaseComponentAttributeBag}, so the
     * `$attributes->merge([...])` nearly every component template runs takes the
     * Collection-free fast path — byte-identical to vanilla's.
     *
     * Crucially this is *only* the partition: it cannot apply vanilla's `$$key = $$key ??`
     * scope-deferral, because the existing scope locals (e.g. a variable an
     * `@include('sub', ['propValue' => 1])` extracted before the `@props` block ran) live in
     * the caller's frame, not here. So the returned `props` are *candidates* — vanilla's
     * `passed-attribute ?? default` — and the compiler binds them with `$$key = $$key ?? $candidate`
     * so an existing local still wins, exactly as vanilla does. Likewise `rest` is returned
     * so the emit can `unset()` any scope local a pass-through attribute shadows (vanilla's
     * `get_defined_vars()` cleanup, done as a targeted unset since unset-of-absent is a no-op).
     *
     * One wrinkle the compiler's `$$key` bind loop accepts: a prop reached via a
     * *non-identifier* key (the kebab alias, e.g. `icon-name`) lands in `props` as
     * `${'icon-name'}` — exactly as vanilla's value landed in that same inaccessible local.
     * The rendered output is identical; only the (unusable) junk local differs.
     *
     * @param  string  $site  stable id for this `@props` location (compile-time constant)
     * @param  array<array-key, mixed>  $declaration  the `@props` array (one evaluation)
     * @return array{props: array<array-key, mixed>, attributes: GreaseComponentAttributeBag, rest: array<array-key, mixed>}
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

        return [
            'props' => $props,
            'attributes' => new GreaseComponentAttributeBag($rest),
            'rest' => $rest,
        ];
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
