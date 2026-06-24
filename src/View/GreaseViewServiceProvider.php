<?php

namespace Grease\View;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory as BaseFactory;
use Illuminate\View\FileViewFinder as BaseFinder;

/**
 * Opt into the greased Blade tier app-wide. Register this provider (it is deliberately
 * NOT auto-discovered — opt-in is the point) and the view singletons get swapped for greased,
 * behaviour-identical drop-ins:
 *
 *   // bootstrap/providers.php (Laravel 11+) or config/app.php providers array
 *   Grease\View\GreaseViewServiceProvider::class,
 *
 * - `blade.compiler` → {@see Compiler}: faster `@props` resolution, a memoized compiled-path
 *   lookup, and a greased attribute bag seeded onto every component.
 * - `view` → {@see Factory}: faster `@foreach` `$loop` bookkeeping (`ManagesLoops`).
 *
 * If a fresh `grease:view-cache` artifact exists (see {@see ViewCacheCommand}), it additionally
 * swaps `view.finder` → {@see FileViewFinder} and pre-seeds the eager name→path index and the
 * compiled-path memo, so view resolution is a single array hit from request one (no stat-walk, no
 * per-render path hash). "Fresh" means at least as new as the compiled-view cache and the config
 * cache, so a later plain `view:cache` / `config:cache` / `optimize` transparently disables a stale
 * index. In development (no artifact) it falls back to live resolution, byte-identical.
 *
 * The swaps use `fromBase()` reflection-clones that carry over the existing instance's full state,
 * so they're transparent. Register it early so the compiler is greased before any view compiles.
 */
class GreaseViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $index = $this->freshViewIndex();

        $this->app->extend('blade.compiler', function (BladeCompiler $compiler) use ($index) {
            $compiler = $compiler instanceof Compiler ? $compiler : Compiler::fromBase($compiler);

            if ($index !== null) {
                $compiler->useGreaseCompiledPaths($index['compiled']);
            }

            return $compiler;
        });

        $this->app->extend('view', function (BaseFactory $factory) {
            return $factory instanceof Factory ? $factory : Factory::fromBase($factory);
        });

        // Only swap the finder when there's an index to seed it with — a greased-but-empty finder
        // is pure overhead. `view.finder` is a bind resolved once inside the `view` singleton, so
        // this extend must (and does) run before `view` first resolves.
        if ($index !== null) {
            $this->app->extend('view.finder', function (BaseFinder $finder) use ($index) {
                $finder = $finder instanceof FileViewFinder ? $finder : FileViewFinder::fromBase($finder);
                $finder->useGreaseViewIndex($index['finder']);

                return $finder;
            });
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ViewCacheCommand::class]);
        }
    }

    /**
     * Load the eager view index when present and at least as fresh as the artifacts it depends on
     * (the compiled-view cache and, if present, the config cache — `view.paths` lives in config).
     * A plain `view:cache`/`config:cache`/`optimize` after the fact makes one of those newer, so the
     * now-stale index is ignored and resolution falls back to live, byte-identical.
     *
     * @return array{finder: array<string, string>, compiled: array<string, string>}|null
     */
    protected function freshViewIndex(): ?array
    {
        $path = self::indexPath($this->app);
        $compiled = $this->app['config']['view.compiled'] ?? null;
        $config = method_exists($this->app, 'getCachedConfigPath') ? $this->app->getCachedConfigPath() : null;

        if (! self::indexIsFresh($path, is_string($compiled) ? $compiled : null, $config)) {
            return null;
        }

        $index = require $path;

        return isset($index['finder'], $index['compiled']) ? $index : null;
    }

    /**
     * The index is usable only when it exists and is at least as new as the compiled-view cache it
     * was built alongside and — if config is cached — the config cache (which holds `view.paths`).
     * `grease:view-cache` writes it last, so it passes; a later `view:cache`/`config:cache`/`optimize`
     * makes one of those newer and the index is ignored (live fallback, byte-identical).
     */
    public static function indexIsFresh(string $path, ?string $compiledDir, ?string $configPath): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $indexTime = filemtime($path);

        if ($compiledDir !== null && is_dir($compiledDir) && filemtime($compiledDir) > $indexTime) {
            return false;
        }

        if ($configPath !== null && is_file($configPath) && filemtime($configPath) > $indexTime) {
            return false;
        }

        return true;
    }

    /**
     * The index file path — a sibling of the framework's route/config caches in bootstrap/cache.
     * Shared by {@see ViewCacheCommand} (writer) and the loader above.
     */
    public static function indexPath(Application $app): string
    {
        return $app->bootstrapPath('cache/grease_views.php');
    }
}
