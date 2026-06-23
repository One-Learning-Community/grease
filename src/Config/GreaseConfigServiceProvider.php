<?php

namespace Grease\Config;

use Illuminate\Contracts\Foundation\Application;
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
 * the loaded items carry over verbatim) and drops the Config facade's cached root so `Config::`
 * calls and the `config()` helper both resolve the greased instance.
 *
 * If a fresh `grease:config-cache` artifact exists (see {@see ConfigCacheCommand}), it is loaded
 * as an eager flat leaf-index, so leaf reads are hash hits with no dot-walk or warmup. "Fresh"
 * means at least as new as the config cache — so a plain `config:cache` or `config:clear` after
 * the fact transparently disables the (now-stale) index, falling back to the lazy memo.
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

        $greased = Repository::fromBase($base);

        $this->loadFlatIndex($greased);

        $this->app->instance('config', $greased);

        // The Config facade caches its resolved root, so the container rebind alone wouldn't
        // redirect `Config::`/`config()` — drop the cached instance.
        Facade::clearResolvedInstance('config');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ConfigCacheCommand::class]);
        }
    }

    /**
     * Load the precomputed flat index onto the repository — but only when it is at least as
     * fresh as the config cache it was built from. The mtime check makes the index
     * self-invalidate after a plain `config:cache` (newer config.php) or `config:clear`
     * (config.php gone), so a stale index is never served.
     */
    protected function loadFlatIndex(Repository $repository): void
    {
        if (! method_exists($this->app, 'getCachedConfigPath')) {
            return; // non-standard container without a config cache path
        }

        $flatPath = self::flatIndexPath($this->app);

        if (self::flatIndexIsFresh($flatPath, $this->app->getCachedConfigPath())) {
            $repository->useGreaseFlatIndex(require $flatPath);
        }
    }

    /**
     * The flat index is usable only when it exists and is at least as new as the config cache
     * it was built from. `grease:config-cache` writes it last, so it passes; a later plain
     * `config:cache` (newer config.php) or `config:clear` (config.php gone) makes it fail.
     */
    public static function flatIndexIsFresh(string $flatPath, string $configPath): bool
    {
        return is_file($flatPath)
            && is_file($configPath)
            && filemtime($flatPath) >= filemtime($configPath);
    }

    /**
     * The flat-index file path — a sibling of the framework's config cache, so it lives and
     * clears alongside it. Shared by {@see ConfigCacheCommand} (writer) and the loader above.
     */
    public static function flatIndexPath(Application $app): string
    {
        return dirname($app->getCachedConfigPath()).'/grease_config_flat.php';
    }
}
