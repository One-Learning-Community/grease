<?php

/**
 * Wire the page-shaped fixture (`page-app`) onto a booted app: register the
 * class components and the `partials.nav` view composer. Shared by the macro
 * (blade.php) and the profiler (blade_excimer.php) so both arms render the
 * exact same surface. Harmless for the simple/rich pages, which never touch it.
 *
 * Returns a closure: fn (\Illuminate\Contracts\Foundation\Application $app): void
 */

require_once __DIR__.'/components.php';

use Grease\Benchmarks\Blade\Card;
use Grease\Benchmarks\Blade\Layout;
use Grease\Benchmarks\Blade\Stat;

return function ($app): void {
    $compiler = $app['blade.compiler'];
    $compiler->component(Layout::class, 'layout');
    $compiler->component(Card::class, 'card');
    $compiler->component(Stat::class, 'stat');

    $app['view']->composer('partials.nav', function ($view) {
        $view->with('links', ['Dashboard', 'Projects', 'Team', 'Settings']);
    });
};
