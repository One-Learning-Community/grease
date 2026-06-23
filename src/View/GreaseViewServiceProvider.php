<?php

namespace Grease\View;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as BaseFactory;

/**
 * Opt into the greased Blade tier app-wide. Register this provider (it is deliberately
 * NOT auto-discovered — opt-in is the point) and two singletons get swapped for greased,
 * behaviour-identical drop-ins:
 *
 *   // bootstrap/providers.php (Laravel 11+) or config/app.php providers array
 *   Grease\View\GreaseViewServiceProvider::class,
 *
 * - `blade.compiler` → {@see Compiler}: faster `@props` resolution, a memoized
 *   compiled-path lookup, and a greased attribute bag seeded onto every component.
 * - `view` → {@see Factory}: faster `@foreach` `$loop` bookkeeping (`ManagesLoops`).
 *
 * Both are built via `fromBase()` — a reflection-clone that carries over the existing
 * instance's full state (registered directives/components, engines, finder, events,
 * shared data) so the swap is transparent. Register it early so the compiler is greased
 * before any view is compiled; views recompile on next change, and a `view:clear` forces
 * it immediately. Output stays behaviour-identical.
 */
class GreaseViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('blade.compiler', function (BladeCompiler $compiler) {
            return $compiler instanceof Compiler ? $compiler : Compiler::fromBase($compiler);
        });

        $this->app->extend('view', function (BaseFactory $factory) {
            return $factory instanceof Factory ? $factory : Factory::fromBase($factory);
        });
    }
}
