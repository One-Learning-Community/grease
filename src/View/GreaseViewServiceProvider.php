<?php

namespace Grease\View;

use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\Support\ServiceProvider;

/**
 * Opt into the greased Blade compiler app-wide. Register this provider (it is
 * deliberately NOT auto-discovered — opt-in is the point) and every component's
 * `@props` recompiles to the tighter, hoisted form via {@see Compiler}.
 *
 *   // bootstrap/providers.php (Laravel 11+) or config/app.php providers array
 *   Grease\View\GreaseViewServiceProvider::class,
 *
 * It `extend`s the bound `blade.compiler` singleton, swapping it for a {@see Compiler}
 * built via `fromBase()` — so every directive, component, and condition already
 * registered (or registered afterwards) is carried over. Register it early so the
 * compiler is greased before any view is compiled; views recompile on next change,
 * and a `view:clear` forces it immediately. Output stays behaviour-identical.
 */
class GreaseViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('blade.compiler', function (BladeCompiler $compiler) {
            return $compiler instanceof Compiler ? $compiler : Compiler::fromBase($compiler);
        });
    }
}
