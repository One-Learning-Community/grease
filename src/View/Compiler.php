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
     * {@inheritDoc}
     *
     * Behaviour-identical to the parent emit, but the name set is hoisted to a memoized
     * keyed map, membership is `isset()`, and the `get_defined_vars()` cleanup is a
     * targeted `unset()`. See the class docblock for why each is equivalent.
     */
    protected function compileProps($expression)
    {
        $site = md5(($this->getPath() ?: 'inline').':'.$this->greasePropsSite++);

        return "<?php \$attributes ??= new \\Illuminate\\View\\ComponentAttributeBag;

\$__propNames = \\Grease\\View\\Props::names('{$site}', {$expression});

\$__newAttributes = [];

foreach (\$attributes->all() as \$__key => \$__value) {
    if (isset(\$__propNames[\$__key])) {
        \$\$__key = \$\$__key ?? \$__value;
    } else {
        \$__newAttributes[\$__key] = \$__value;
    }
}

\$attributes = new \\Illuminate\\View\\ComponentAttributeBag(\$__newAttributes);

unset(\$__propNames);
unset(\$__newAttributes);

foreach (array_filter({$expression}, 'is_string', ARRAY_FILTER_USE_KEY) as \$__key => \$__value) {
    \$\$__key = \$\$__key ?? \$__value;
}

foreach (\$attributes->all() as \$__key => \$__value) {
    unset(\$\$__key);
}

unset(\$__key, \$__value); ?>";
    }
}
