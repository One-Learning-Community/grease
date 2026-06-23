<?php

namespace Grease\Config;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

/**
 * Opt into the greased config repository app-wide. Register this provider (deliberately NOT
 * auto-discovered — opt-in is the point) as early as practical and every `config('a.b.c')`
 * read goes through {@see Repository}'s per-key memo:
 *
 *   // bootstrap/providers.php (Laravel 11+) or config/app.php providers array
 *   Grease\Config\GreaseConfigServiceProvider::class,
 *
 * It swaps the bound `config` singleton for a greased repository built via `fromBase()` (so
 * the loaded items carry over verbatim) and drops the Config facade's cached root so
 * `Config::` calls and the `config()` helper both resolve the greased instance. The repository
 * is a long-lived singleton, so under a persistent worker (Octane) the memo survives across
 * requests — config reads become pure hash hits for the worker's whole life.
 *
 * Register it BEFORE providers that read config heavily so the most reads flow through the
 * greased path. The swap is idempotent (a no-op if config is already greased).
 */
class GreaseConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $base = $this->app->make('config');

        if ($base instanceof Repository) {
            return;
        }

        $this->app->instance('config', Repository::fromBase($base));

        // The Config facade caches its resolved root, so the container rebind alone wouldn't
        // redirect `Config::`/`config()` — drop the cached instance.
        Facade::clearResolvedInstance('config');
    }
}
