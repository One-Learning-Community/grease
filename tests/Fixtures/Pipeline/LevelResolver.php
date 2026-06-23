<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Grease\Container\Application as GreasedApplication;
use Grease\Events\GreaseEventServiceProvider;
use Grease\View\GreaseViewServiceProvider;
use Illuminate\Foundation\Application as VanillaApplication;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Orchestra\Testbench\Foundation\Application as TestbenchResolver;

/**
 * Boots a fully-configured Testbench application at a given Grease level — the app class
 * and provider set are chosen by {@see $level}. Each level is a strict superset of the
 * one below (see {@see PipelineHarness::LEVELS}):
 *
 *   >= 2  greased event dispatcher (provider)
 *   >= 3  greased Blade view tier (provider)
 *   >= 4  greased container (Application subclass)
 *
 * Levels 0/1 (vanilla / +models) differ only in which model classes the routes use, which
 * is the harness's concern, not the container's.
 *
 * `$level` is static because each level is benchmarked in its own process (one boot per
 * level), so there's no contention.
 */
final class LevelResolver extends TestbenchResolver
{
    public static int $level = 0;

    protected function resolveApplication()
    {
        $appClass = self::$level >= 4 ? GreasedApplication::class : VanillaApplication::class;

        $providers = [];
        if (self::$level >= 2) {
            $providers[] = GreaseEventServiceProvider::class;
        }
        if (self::$level >= 3) {
            $providers[] = GreaseViewServiceProvider::class;
        }

        return (new ApplicationBuilder(new $appClass($this->getApplicationBasePath())))
            ->withProviders($providers)
            ->withMiddleware(function ($middleware) {
                //
            })
            ->withCommands()
            ->create();
    }
}
