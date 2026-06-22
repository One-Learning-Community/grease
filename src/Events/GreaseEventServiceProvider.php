<?php

namespace Grease\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

/**
 * Opt into the greased event dispatcher app-wide. Register this provider (it is
 * deliberately NOT auto-discovered — opt-in is the point) and every dispatch goes
 * through {@see Dispatcher}:
 *
 *   // bootstrap/providers.php (Laravel 11+) or config/app.php providers array
 *   Grease\Events\GreaseEventServiceProvider::class,
 *
 * It swaps the bound `events` singleton for a greased dispatcher built via
 * `fromBase()`, so any listeners already registered are carried over, and points
 * Eloquent's static dispatcher at the new one (model events are cached separately).
 * Register it as early as practical so the most listeners flow straight into the
 * greased dispatcher rather than being migrated.
 */
class GreaseEventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $greased = Dispatcher::fromBase($this->app->make('events'));

        $this->app->instance('events', $greased);

        // The Event facade caches its resolved root, so the container rebind alone
        // wouldn't redirect `Event::` calls — drop the cached instance.
        Facade::clearResolvedInstance('events');

        // Eloquent holds the dispatcher in a static, so the container rebind alone
        // wouldn't cover model events.
        if (class_exists(Model::class)) {
            Model::setEventDispatcher($greased);
        }
    }
}
