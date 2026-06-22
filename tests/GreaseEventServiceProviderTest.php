<?php

namespace Grease\Tests;

use Grease\Events\Dispatcher as GreasedDispatcher;
use Grease\Events\GreaseEventServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/** Registers a listener on the framework dispatcher *before* Grease swaps it in. */
class PreGreaseListenerProvider extends ServiceProvider
{
    public static int $hits = 0;

    public function register(): void
    {
        $this->app->make('events')->listen('pre.swap', function () {
            self::$hits++;
        });
    }
}

class GreaseEventServiceProviderTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // Order matters: the pre-listener provider registers first, then Grease swaps.
        return [PreGreaseListenerProvider::class, GreaseEventServiceProvider::class];
    }

    public function test_dispatcher_is_swapped_in_container_facade_and_eloquent(): void
    {
        $this->assertInstanceOf(GreasedDispatcher::class, $this->app->make('events'));
        $this->assertInstanceOf(GreasedDispatcher::class, Event::getFacadeRoot());
        $this->assertInstanceOf(GreasedDispatcher::class, Model::getEventDispatcher());
    }

    public function test_listener_registered_after_swap_fires(): void
    {
        $hits = 0;
        Event::listen('grease.ping', function () use (&$hits) {
            $hits++;
        });

        Event::dispatch('grease.ping');

        $this->assertSame(1, $hits);
    }

    public function test_listener_registered_before_swap_is_migrated(): void
    {
        PreGreaseListenerProvider::$hits = 0;

        Event::dispatch('pre.swap');

        $this->assertSame(1, PreGreaseListenerProvider::$hits);
    }
}
