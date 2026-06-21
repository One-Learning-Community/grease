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
 * Three optimizations, all on the hot `dispatch()` path:
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
     * Memoized listener-presence per event name, so the no-listener fast path
     * doesn't re-scan wildcards on every dispatch of the same event.
     *
     * @var array<string, bool>
     */
    protected $hasListenersCache = [];

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
            && ! ($this->hasListenersCache[$event] ??= $this->hasListeners($event))
            && ! $this->shouldDeferEvent($event)
            && ! $this->shouldBroadcast(Arr::wrap($payload))) {
            return $halt ? null : [];
        }

        return parent::dispatch($event, $payload, $halt);
    }

    /** {@inheritDoc} */
    public function hasListeners($eventName)
    {
        // Identical to the stock check — the listener/wildcard caches are perf state,
        // not a source of truth for presence (a cached empty list is still "none").
        return isset($this->listeners[$eventName])
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
