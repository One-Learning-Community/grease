<?php

namespace Grease\View;

use Illuminate\Support\Str;

/**
 * The prop-name resolution for Blade's `@props`, hoisted out of the per-render hot
 * path. Vanilla compiles `@props` to a fresh `ComponentAttributeBag::extractPropNames()`
 * call every render — building a flat name list that is then fed, key by key, to
 * `in_array()` (O(props × attributes)). Both halves are wasteful: the name set is a
 * compile-time constant, and a list forces a linear scan where a keyed set gives O(1).
 *
 * {@see \Grease\View\Compiler::compileProps()} emits a call here instead, passing a
 * stable per-`@props`-site id. The keyed `[name => true]` map is built once per site
 * and reused across every render of that component — so a Livewire page re-rendering
 * the same components pays the build once, not once per render.
 *
 * Byte-for-byte, the membership set is identical to what `extractPropNames()` produces
 * (every name, plus its kebab alias), only shaped as a set for `isset()` instead of a
 * list for `in_array()`. The parity suite asserts the compiled block resolves the same
 * props and the same surviving attributes as vanilla.
 */
class Props
{
    /**
     * Keyed prop-name maps, memoized per compiled `@props` site.
     *
     * @var array<string, array<string, true>>
     */
    protected static array $maps = [];

    /**
     * The keyed prop-name set for one `@props` declaration, built once per site.
     *
     * @param  string  $site  stable id for this `@props` location (compile-time constant)
     * @param  array<array-key, mixed>  $declaration  the `@props` array, names as keys
     *                                  (or values, for list-style entries)
     * @return array<string, true>
     */
    public static function names(string $site, array $declaration): array
    {
        return static::$maps[$site] ??= static::build($declaration);
    }

    /**
     * Build the set: each prop name and its kebab alias mapped to true. Mirrors
     * {@see \Illuminate\View\ComponentAttributeBag::extractPropNames()} — list-style
     * keys take the value as the name — but emits a set and calls `Str::snake` once
     * (`kebab` is just `snake($v, '-')`), letting the set dedupe collisions for free.
     *
     * @param  array<array-key, mixed>  $declaration
     * @return array<string, true>
     */
    protected static function build(array $declaration): array
    {
        $names = [];

        foreach ($declaration as $key => $default) {
            $key = is_numeric($key) ? $default : $key;

            $names[$key] = true;
            $names[Str::snake($key, '-')] = true;
        }

        return $names;
    }

    /**
     * Drop the memo. For tests that compile many throwaway `@props` sites; never
     * needed in a running app (the maps are tiny and bounded by component count).
     */
    public static function flush(): void
    {
        static::$maps = [];
    }
}
