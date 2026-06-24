<?php

namespace Grease\View;

use Illuminate\View\Compilers\BladeCompiler;
use ReflectionClass;

/**
 * A drop-in faster Blade compiler — overrides a single emit, `compileProps()`, so the
 * per-render prop/attribute resolution every component pays gets cheaper. Bind it as
 * the `blade.compiler` singleton (see {@see GreaseViewServiceProvider}) and every
 * component recompiles to the tighter form; behaviour stays identical to vanilla.
 *
 * Vanilla's `@props` compiles to a block that, on EACH render, rebuilds a flat name
 * list (`extractPropNames`), partitions incoming attributes with `in_array` (a linear
 * scan per attribute), and finally calls `get_defined_vars()` to snapshot the whole
 * scope just to unset attribute-named locals. This emit replaces all three:
 *   - the name set is built once per `@props` site and memoized ({@see Props::names});
 *   - membership is an `isset()` on a keyed set, O(1) instead of `in_array`;
 *   - the scope snapshot becomes a targeted `unset()` over the pass-through attributes
 *     (provably equivalent: `unset` of an absent local is a no-op, and a pass-through
 *     key can never name a prop local — the partition is mutually exclusive).
 *
 * Everything else the compiler does is inherited untouched.
 */
class Compiler extends BladeCompiler
{
    /**
     * Per-instance counter giving each compiled `@props` a stable, unique site id.
     * Combined with the view path it disambiguates every `@props` location, so each
     * gets its own memoized name map at runtime.
     */
    protected int $greasePropsSite = 0;

    /**
     * Memoized compiled-file paths, keyed by source view path.
     *
     * Vanilla recomputes `cachePath.'/'.hash('xxh128', 'v2'.path).'.php'` on every
     * render — `CompilerEngine::get()` calls it once per view, and a page is a tree
     * of view renders (every component, slot, @include and @each iteration is one).
     * The result is a pure function of the path (cachePath/basePath/extension are
     * fixed for the compiler's life), so the hash is paid once per path, not per
     * render. Byte-identical output; just memoized.
     */
    protected array $greaseCompiledPaths = [];

    /**
     * Build a greased compiler that takes over from an existing one, copying its full
     * state (registered directives, components, conditions, paths, …) so it's a
     * transparent drop-in. Reflection-clones every base property rather than naming
     * them, so it survives BladeCompiler gaining state across framework versions.
     */
    public static function fromBase(BladeCompiler $base): static
    {
        $new = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach ((new ReflectionClass(BladeCompiler::class))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($base)) {
                continue;
            }

            $property->setValue($new, $property->getValue($base));
        }

        return $new;
    }

    /**
     * Memoized per source path — see {@see $greaseCompiledPaths}. The base computes a
     * hash of the path on every call; this pays it once. Identical return value.
     */
    public function getCompiledPath($path)
    {
        return $this->greaseCompiledPaths[$path] ??= parent::getCompiledPath($path);
    }

    /**
     * Pre-seed the compiled-path memo from an eager `grease:view-cache` index (source path =>
     * compiled path), so the first render of each view in a fresh process skips the `xxh128` hash.
     * Live-computed entries win (`+`), and each value is the exact deterministic hash vanilla would
     * produce, so output stays byte-identical.
     *
     * @param  array<string, string>  $paths
     */
    public function useGreaseCompiledPaths(array $paths): void
    {
        $this->greaseCompiledPaths += $paths;
    }

    protected function compileProps($expression)
    {
        $site = md5(($this->getPath() ?: 'inline').':'.$this->greasePropsSite++);

        // `Props::mergeAttributes` can only partition (it has no access to the caller's
        // scope), so it returns the prop *candidates* (passed-attribute ?? default), the
        // surviving `attributes` bag, and the surviving keys. We finish the job here, in the
        // template frame, to stay byte-identical to vanilla:
        //   - props bind with `$$key = $$key ?? $candidate`, so an existing scope local wins
        //     (the load-bearing case: `@include('sub', ['propValue' => 1])` extracts
        //     `$propValue` before this block runs — vanilla preserves it, so must we);
        //   - then `unset()` any local a pass-through attribute shadows (vanilla's
        //     `get_defined_vars()` cleanup, here a targeted unset — unset-of-absent is a
        //     no-op, and a pass-through key can never name a prop, the partition being
        //     mutually exclusive).
        return "<?php \$__grease = \\Grease\\View\\Props::mergeAttributes('{$site}', {$expression}, \$attributes ?? new \\Illuminate\\View\\ComponentAttributeBag);

foreach (\$__grease['props'] as \$__key => \$__value) {
    \$\$__key = \$\$__key ?? \$__value;
}

\$attributes = \$__grease['attributes'];

foreach (\$__grease['rest'] as \$__key => \$__value) {
    unset(\$\$__key);
}

unset(\$__grease, \$__key, \$__value); ?>";
    }

    /**
     * Seed every rendered component's attribute bag as a greased one, so the bag the
     * template merges is {@see ComponentAttributeBag} (fast `merge()`) instead of vanilla.
     *
     * The opening emit fixes the bag's class identity before the template ever sees it:
     * `startComponent($component->resolveView(), $component->data())` runs first, and
     * `data()` lazily creates `$this->attributes` (`?: newAttributeBag()`) — a *vanilla*
     * bag — then returns it as the `attributes` variable. `withAttributes()` later only
     * mutates that same object in place; you can't reclass it after the fact. So the win
     * has to land *before* `data()`.
     *
     * After `resolve()`/`withName()` the component's public `$attributes` is still null,
     * so one `??=` seeds an (empty) greased bag that `data()`'s `?:` then adopts and
     * `withAttributes()` populates in place — the template ends up holding a greased bag
     * and its `$attributes->merge([...])` takes the Collection-free path. Byte-identical:
     * the greased bag overrides only `merge()` (parity-proven), an empty seed is
     * indistinguishable from vanilla's lazy `newAttributeBag()`, and `??=` leaves any
     * constructor-set bag untouched. Covers class components and class-less components
     * without `@props` alike (for `@props` ones {@see Props} already greases the bag, so
     * the seed is a harmless no-op there). Overrides the parent's static verbatim plus the
     * one seed line; reached via the `static::` call in `compileComponent()`.
     */
    public static function compileClassComponentOpening(string $component, string $alias, string $data, string $hash)
    {
        return implode("\n", [
            '<?php if (isset($component)) { $__componentOriginal'.$hash.' = $component; } ?>',
            '<?php if (isset($attributes)) { $__attributesOriginal'.$hash.' = $attributes; } ?>',
            '<?php $component = '.$component.'::resolve('.($data ?: '[]').' + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>',
            '<?php $component->withName('.$alias.'); ?>',
            '<?php if ($component->shouldRender()): ?>',
            '<?php $component->attributes ??= new \Grease\View\ComponentAttributeBag([]); ?>',
            '<?php $__env->startComponent($component->resolveView(), $component->data()); ?>',
        ]);
    }
}
