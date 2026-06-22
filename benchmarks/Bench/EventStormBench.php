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
 * The dispatcher tier where it actually matters: an event-dense request, not the
 * Eloquent path. The "storm" approximates a page render — ~50 view components each
 * firing `creating:`/`composing:`, plus repeated cache/model events and a few the
 * app genuinely listens to (~165 dispatches total).
 *
 * Two profiles, because the win comes from different optimizations:
 *  - **lean** (warm, a couple specific listeners, no wildcards): most events have no
 *    listener, so the no-listener fast path — which the stock dispatcher lacks
 *    entirely — carries it. Dispatchers are reused across revs (steady-state/Octane).
 *  - **cold** (a fresh dispatcher per rev with realistic *non-trivial* wildcards —
 *    `eloquent.*`, `cache.*`, package patterns — the FPM-per-request shape): stock
 *    can't amortize via `wildcardsCache` across requests, so it recompiles `Str::is`
 *    regex for every unique event name; greased uses the pre-compiled `WildcardPattern`.
 *    (A `*` catch-all like Telescope's is NOT a win — both dispatchers short-circuit
 *    it without regex — so this uses patterns that actually have to match.)
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(200),
    Iterations(10),
    RetryThreshold(3),
]
class EventStormBench
{
    /** @var string[] */
    private array $storm;

    private VanillaDispatcher $leanVanilla;

    private GreasedDispatcher $leanGreased;

    public function setUp(): void
    {
        $storm = [];
        for ($i = 0; $i < 50; $i++) {            // a page's worth of view components
            $storm[] = "creating: pages.dashboard.widget$i";
            $storm[] = "composing: pages.dashboard.widget$i";
        }
        for ($i = 0; $i < 20; $i++) {            // repeated framework chatter
            $storm[] = 'cache.hit';
        }
        for ($i = 0; $i < 10; $i++) {
            $storm[] = 'eloquent.retrieved: App\\Models\\User';
        }
        for ($i = 0; $i < 5; $i++) {             // an event the app actually handles
            $storm[] = 'user.activity';
        }
        $this->storm = $storm;

        ($this->leanVanilla = new VanillaDispatcher(new Container))->listen('user.activity', fn () => null);
        ($this->leanGreased = new GreasedDispatcher(new Container))->listen('user.activity', fn () => null);
    }

    private function fire(object $d): void
    {
        foreach ($this->storm as $event) {
            $d->dispatch($event);
        }
    }

    /** Build a fresh dispatcher with realistic non-trivial wildcards (the per-request shape). */
    private function coldFire(string $class): void
    {
        $d = new $class(new Container);

        foreach (['eloquent.*', 'cache.*', 'composing: admin.*', 'Illuminate\\Mail\\*'] as $pattern) {
            $d->listen($pattern, fn () => null);
        }
        $d->listen('user.activity', fn () => null);

        $this->fire($d);
    }

    public function benchLeanVanilla(): void
    {
        $this->fire($this->leanVanilla);
    }

    public function benchLeanGreased(): void
    {
        $this->fire($this->leanGreased);
    }

    public function benchColdVanilla(): void
    {
        $this->coldFire(VanillaDispatcher::class);
    }

    public function benchColdGreased(): void
    {
        $this->coldFire(GreasedDispatcher::class);
    }
}
