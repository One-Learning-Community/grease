<?php

namespace Grease\Bench;

use Grease\Events\Dispatcher as GreasedDispatcher;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as VanillaDispatcher;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * A/B for the events dispatcher tier (Grease\Events\Dispatcher), the port of
 * laravel/framework#51184. Both dispatchers carry a handful of registered wildcard
 * listeners (the shape of a real app with model observers / Telescope), so the
 * benchmark reflects the cost a stock dispatcher pays re-scanning wildcards rather
 * than an empty-registry best case.
 *
 *  - `*NoListener*`   — the dominant case: an event nothing listens for. Stock walks
 *                       its pipeline and re-checks wildcards; greased early-returns
 *                       off a cached presence check.
 *  - `*WithListeners*`— an event with two direct listeners, dispatched repeatedly;
 *                       greased reuses cached `makeListener()` results.
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(2000),
    Iterations(10),
    RetryThreshold(3),
]
class DispatcherBench
{
    private VanillaDispatcher $vanilla;

    private GreasedDispatcher $greased;

    public function setUp(): void
    {
        $this->vanilla = $this->seed(new VanillaDispatcher(new Container));
        $this->greased = $this->seed(new GreasedDispatcher(new Container));
    }

    /** Register a realistic mix: a few wildcards (observers/Telescope) + one direct listener. */
    private function seed(object $d): object
    {
        for ($i = 0; $i < 6; $i++) {
            $d->listen("eloquent.other$i: *", fn () => null);
        }

        $d->listen('order.placed', fn () => 'a');
        $d->listen('order.placed', fn () => 'b');

        return $d;
    }

    public function benchNoListenerVanilla(): void
    {
        $this->vanilla->dispatch('eloquent.retrieved: App\\Models\\User');
    }

    public function benchNoListenerGreased(): void
    {
        $this->greased->dispatch('eloquent.retrieved: App\\Models\\User');
    }

    public function benchWithListenersVanilla(): void
    {
        $this->vanilla->dispatch('order.placed', ['x']);
    }

    public function benchWithListenersGreased(): void
    {
        $this->greased->dispatch('order.placed', ['x']);
    }
}
