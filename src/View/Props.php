<?php

namespace Grease\View;

use Illuminate\Support\Str;

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
 * {@see resolve()}, which returns both halves it needs:
 *   - **names** — the keyed `[name => true]` set for O(1) `isset()` partitioning,
 *     memoized per `@props` site (a compile-time constant);
 *   - **defaults** — the string-keyed subset of the declaration, with *fresh* values
 *     (defaults can be runtime expressions) but using the cached key-structure, so the
 *     `is_string` key walk is paid once per site, not once per render.
 *
 * The membership set is byte-identical to `extractPropNames` (every name plus its kebab
 * alias), only shaped as a set; the defaults are exactly what `array_filter(decl,
 * 'is_string', ARRAY_FILTER_USE_KEY)` produces. The compiler still binds the locals
 * inline (a helper can't write its caller's scope, and `extract()` skips non-identifier
 * keys like `icon-name`). The parity suite asserts the compiled block resolves the same
 * props and leaves the same attributes as vanilla.
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
     * Resolve a declaration into its partition name set and its default map.
     *
     * @param  string  $site  stable id for this `@props` location (compile-time constant)
     * @param  array<array-key, mixed>  $declaration  the `@props` array (one evaluation)
     * @return array{0: array<string, true>, 1: array<array-key, mixed>}
     */
    public static function resolve(string $site, array $declaration): array
    {
        $meta = static::$meta[$site] ??= static::analyze($declaration);

        // Defaults carry the declaration's *current* values (which may be runtime
        // expressions), filtered to the string keys via the cached structure — no
        // per-render is_string walk, and no second evaluation of the declaration.
        return [$meta['names'], array_intersect_key($declaration, $meta['stringKeys'])];
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
