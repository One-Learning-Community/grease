<?php

namespace Grease\Container;

use Illuminate\Foundation\Application as BaseApplication;

/**
 * Greased Laravel application container.
 *
 * The container builds itself before any service provider runs, so — unlike the
 * Eloquent traits or the events/Blade singletons — the blueprint tier can't be bound
 * from inside the app. Opt in by instantiating this in place of
 * {@see \Illuminate\Foundation\Application} in `bootstrap/app.php`:
 *
 *     $app = (new \Grease\Container\Application(...))->...;
 *
 * Behaviour-identical to the base application; transient resolutions take the frozen
 * constructor-blueprint fast path. See {@see ResolvesWithGreaseBlueprint}.
 */
class Application extends BaseApplication
{
    use ResolvesWithGreaseBlueprint;
}
