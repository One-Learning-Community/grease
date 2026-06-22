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
 * The dispatcher tier on the path the framework *actually* fires view events from —
 * which is NOT a bare `dispatch()`. Laravel's view layer guards every view event
 * behind a presence check (Illuminate\View\Concerns\ManagesEvents):
 *
 *     public function callComposer($view) {
 *         if ($this->events->hasListeners($event = 'composing: '.$view->name())) {
 *             $this->events->dispatch($event, [$view]);
 *         }
 *     }
 *     // callCreator is the same shape for 'creating: '
 *
 * So on a Blade/Livewire render the hot call is `hasListeners()`, not `dispatch()`.
 * Every `view()` runs `callCreator`; every render runs `callComposer`. This bench
 * reproduces that exact guard idiom — verbatim from ManagesEvents — so the A/B
 * measures what a real render pays, not what {@see EventStormBench} measures (which
 * dispatches directly and never exercises the guard the framework never skips).
 *
 * Shape = a Livewire dashboard: ~30 components, each re-rendered across several
 * roundtrips (wire:model / actions), against a dispatcher carrying the wildcards a
 * real app registers (eloquent.*, cache.*, a package pattern) plus one genuine view
 * composer. Almost every guard returns false — and a stock `hasListeners()` re-scans
 * every wildcard on each of those false answers.
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(200),
    Iterations(10),
    RetryThreshold(3),
]
class ViewEventBench
{
    /** @var string[] the distinct view names a dashboard renders */
    private array $views;

    /** roundtrips a Livewire page goes through — same views, re-rendered */
    private const ROUNDTRIPS = 6;

    private VanillaDispatcher $vanilla;

    private GreasedDispatcher $greased;

    public function setUp(): void
    {
        $views = [];
        for ($i = 0; $i < 30; $i++) {
            $views[] = "livewire.dashboard.widget$i";
        }
        // one view the app genuinely composes
        $views[] = 'layouts.app';
        $this->views = $views;

        $this->vanilla = $this->seed(new VanillaDispatcher(new Container));
        $this->greased = $this->seed(new GreasedDispatcher(new Container));
    }

    /** Wildcards a real app registers + one real composer — the cost a guard scans. */
    private function seed(object $d): object
    {
        foreach (['eloquent.*', 'cache.*', 'Illuminate\\Mail\\*'] as $pattern) {
            $d->listen($pattern, fn () => null);
        }
        // a real view composer: only 'composing: layouts.app' has a listener
        $d->listen('composing: layouts.app', fn () => null);

        return $d;
    }

    /**
     * One full page lifecycle: every component rendered ROUNDTRIPS times, each render
     * firing the creating/composing guards exactly as ManagesEvents does.
     */
    private function renderPage(object $d): void
    {
        for ($r = 0; $r < self::ROUNDTRIPS; $r++) {
            foreach ($this->views as $name) {
                $creating = 'creating: '.$name;
                if ($d->hasListeners($creating)) {
                    $d->dispatch($creating, []);
                }

                $composing = 'composing: '.$name;
                if ($d->hasListeners($composing)) {
                    $d->dispatch($composing, []);
                }
            }
        }
    }

    public function benchVanilla(): void
    {
        $this->renderPage($this->vanilla);
    }

    public function benchGreased(): void
    {
        $this->renderPage($this->greased);
    }
}
