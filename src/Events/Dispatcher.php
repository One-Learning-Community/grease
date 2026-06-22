<?php

namespace Grease\Events;

use Grease\Support\WildcardPattern;
use Illuminate\Events\Dispatcher as BaseDispatcher;
use Illuminate\Support\Arr;

/**
 * A drop-in faster event dispatcher — Grease's port of laravel/framework#51184
 * (declined upstream as "marginal in isolation"). Bind it as the `events`
 * singleton and every dispatch in the app gets faster; behaviour stays identical
 * to the stock dispatcher (same listeners, same order, same return values), which
 * the events-dispatcher parity suite asserts A/B against the real framework.
 *
 * Four optimizations on the hot dispatch and presence-check paths:
 *   1. **No-listener fast path** — a string event with no listener, nothing to
 *      broadcast, and no active deferral can't do anything, so we skip parsing and
 *      the whole pipeline. The guard mirrors exactly when the stock pipeline no-ops.
 *   2. **Listener cache** — `getListeners()` memoizes the resolved direct listeners
 *      per event name, so `makeListener()` runs once per event, not once per
 *      dispatch (the win on tight loops that dispatch the same event repeatedly).
 *   3. **Pre-compiled wildcard patterns** — wildcard matching uses a cached
 *      {@see WildcardPattern} regex instead of `Str::is` recompiling it every call
 *      (the cost that makes a stock `hasListeners()` check expensive when wildcard
 *      listeners — model observers, Telescope — are registered).
 *   4. **Memoized presence** — `hasListeners()` caches its yes/no per event name.
 *      The framework fires view events through a `hasListeners()` guard
 *      (`ManagesEvents::callCreator`/`callComposer`), so on a Blade/Livewire render
 *      this — not `dispatch()` — is the hot call; memoizing turns a per-render
 *      wildcard re-scan into one scan per distinct view name.
 */
class Dispatcher extends BaseDispatcher
{
    /**
     * Cached resolved direct listeners, keyed by event name.
     *
     * @var array<string, array>
     */
    protected $listenersCache = [];

    /**
     * Pre-compiled wildcard patterns, keyed by wildcard event.
     *
     * @var array<string, WildcardPattern>
     */
    protected $wildcardPatterns = [];

    /**
     * Memoized listener-presence per event name. Consumed by both the no-listener
     * fast path in `dispatch()` and the public `hasListeners()` itself — the latter
     * is the framework's view-event guard, so this is what keeps a Blade/Livewire
     * render from re-scanning wildcards on every component.
     *
     * @var array<string, bool>
     */
    protected $hasListenersCache = [];

    /**
     * Build a greased dispatcher that takes over from an existing one — copying its
     * full state (listeners, wildcards, resolvers, deferral) so it's a transparent
     * drop-in. Reads the base's protected state directly (legal: this is a subclass).
     * Use it when swapping the bound `events` singleton after listeners may already
     * have been registered.
     */
    public static function fromBase(BaseDispatcher $base): static
    {
        $new = new static($base->container);

        $new->listeners = $base->listeners;
        $new->wildcards = $base->wildcards;
        $new->wildcardsCache = $base->wildcardsCache;
        $new->queueResolver = $base->queueResolver;
        $new->transactionManagerResolver = $base->transactionManagerResolver;
        $new->deferredEvents = $base->deferredEvents;
        $new->deferringEvents = $base->deferringEvents;
        $new->eventsToDefer = $base->eventsToDefer;

        foreach (array_keys($new->wildcards) as $pattern) {
            $new->wildcardPatterns[$pattern] = new WildcardPattern($pattern);
        }

        return $new;
    }

    /** {@inheritDoc} */
    public function listen($events, $listener = null)
    {
        parent::listen($events, $listener);

        // Registration happens at boot, not on the hot path — a blanket reset keeps
        // the caches honest without replicating listen()'s version-specific internals.
        $this->listenersCache = [];
        $this->hasListenersCache = [];
    }

    /** {@inheritDoc} */
    protected function setupWildcardListen($event, $listener)
    {
        parent::setupWildcardListen($event, $listener);

        $this->wildcardPatterns[$event] = new WildcardPattern($event);
    }

    /** {@inheritDoc} */
    public function dispatch($event, $payload = [], $halt = false)
    {
        // Fast path: a string event that has no listener, nothing broadcastable in
        // its payload, and no active deferral produces exactly an empty result in
        // the stock pipeline — so skip it. Each guard clause matches a stock no-op
        // condition, so this never changes observable behaviour.
        if (is_string($event)
            && ! $this->hasListeners($event)
            && ! $this->shouldDeferEvent($event)
            && ! $this->shouldBroadcast(Arr::wrap($payload))) {
            return $halt ? null : [];
        }

        return parent::dispatch($event, $payload, $halt);
    }

    /** {@inheritDoc} */
    public function hasListeners($eventName)
    {
        // Memoize presence. The framework fires view events through a hasListeners()
        // guard (ManagesEvents::callCreator/callComposer), so this — not dispatch() —
        // is the hot call on a Blade/Livewire render, and a stock check re-scans every
        // wildcard on each (usually false) answer. The result only changes when
        // listeners are added (listen() resets this cache) or removed (forget() resets
        // it wholesale), so caching is behaviour-identical to the stock recomputation.
        return $this->hasListenersCache[$eventName] ??= isset($this->listeners[$eventName])
            || isset($this->wildcards[$eventName])
            || $this->hasWildcardListeners($eventName);
    }

    /** {@inheritDoc} */
    public function hasWildcardListeners($eventName)
    {
        foreach ($this->wildcards as $key => $listeners) {
            if (($this->wildcardPatterns[$key] ??= new WildcardPattern($key))->matches($eventName)) {
                return true;
            }
        }

        return false;
    }

    /** {@inheritDoc} */
    public function getListeners($eventName)
    {
        // Identical shape and order to the stock method (direct, then wildcard, then
        // interface listeners) — only the direct-listener resolution is memoized and
        // wildcard matching uses the pre-compiled patterns.
        $listeners = array_merge(
            $this->listenersCache[$eventName] ??= $this->prepareListeners($eventName),
            $this->wildcardsCache[$eventName] ?? $this->getWildcardListeners($eventName),
        );

        return class_exists($eventName, false)
            ? $this->addInterfaceListeners($eventName, $listeners)
            : $listeners;
    }

    /** {@inheritDoc} */
    protected function getWildcardListeners($eventName)
    {
        if (! array_key_exists($eventName, $this->wildcardsCache)) {
            $wildcards = [];

            foreach ($this->wildcards as $key => $listeners) {
                if (($this->wildcardPatterns[$key] ??= new WildcardPattern($key))->matches($eventName)) {
                    foreach ($listeners as $listener) {
                        $wildcards[] = $this->makeListener($listener, true);
                    }
                }
            }

            $this->wildcardsCache[$eventName] = $wildcards;
        }

        return $this->wildcardsCache[$eventName];
    }

    /** {@inheritDoc} */
    public function forget($event)
    {
        parent::forget($event);

        if (str_contains($event, '*')) {
            unset($this->wildcardPatterns[$event]);
        } else {
            unset($this->listenersCache[$event]);
        }

        // A removed wildcard can change presence for any event name, so reset wholesale.
        $this->hasListenersCache = [];
    }
}
