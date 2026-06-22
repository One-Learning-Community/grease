# The Event Dispatcher

A *different axis* from the model tiers. The traits make individual models faster;
the dispatcher makes **every dispatch in the app** faster — model events, view
rendering, cache operations, your own events. It's a drop-in subclass of Laravel's
own dispatcher, and a faithful port of
[laravel/framework#51184](https://github.com/laravel/framework/pull/51184).

## What it does

Four optimizations, all behaviour-identical to the stock dispatcher:

- **No-listener fast path.** Most dispatched events have no listener. Grease
  short-circuits them off a cached presence check instead of walking the resolution
  machinery every time.
- **Cached listener resolution.** `makeListener` runs once per event, not once per
  dispatch — the resolved listener set is cached and reused.
- **Pre-compiled wildcard patterns.** Wildcard listener patterns (`eloquent.*`,
  observer patterns, package patterns) are compiled once instead of re-scanned,
  uncached, on every call.
- **Memoized presence.** `hasListeners()` caches its yes/no per event name — the one
  the view layer leans on. The framework gates `creating:`/`composing:` behind
  `hasListeners()` (`callCreator`/`callComposer`), so on a Blade/Livewire render *that*
  is the hot call, not `dispatch()`; memoizing it makes re-rendering a component a
  cached lookup instead of a fresh wildcard scan.

There's a subtlety worth spelling out. With a *stock* dispatcher, gating a dispatch
behind `hasListeners()` is a *net loss* — it re-scans every wildcard pattern uncached,
so asking "is anyone listening?" can cost more than just telling nobody. But the
framework's own view layer gates exactly that way and you can't change it from a
package — so Grease memoizes `hasListeners()` itself: the check the framework already
pays for becomes one scan per distinct event name instead of one per call. That's why
the Blade/Livewire render path — the same view names recurring across roundtrips — is
where this memoization pays off most.

## Behaviour-identical, by test

The model tiers promise byte-identical *output*. The dispatcher's bar is
**behavioural**: the same listeners fire, in the same order, with the same return
values, halting on the same `false`. That contract is asserted across a parity suite
of A/B tests against the stock dispatcher.

## What it's worth

| Scenario | Δ |
| --- | :---: |
| dispatch with no listener | **−53%** |
| dispatch with listeners | **−18%** |
| event-dense request, warm | **−57%** |
| event-dense request, cold (non-trivial wildcards) | **−54%** |

On an event-dense request — a page render's worth of dispatches — it roughly
**halves** the event overhead. There's a further win on a Blade/Livewire render: view
events fire through the `hasListeners()` guard (the same components re-rendered across
roundtrips), and the greased dispatcher memoizes that check — so re-rendering stops
re-scanning wildcards every time. How much that's worth depends on how many
observer/wildcard listeners you register. An Eloquent-only benchmark *understates* this
tier on purpose: its real value is app-wide event traffic that a model benchmark never
touches.

## Opt in

It is **not** auto-discovered — you register it explicitly:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Events\GreaseEventServiceProvider::class,
```

Registering the provider:

- swaps the bound `events` singleton for the greased dispatcher,
- carries over any already-registered listeners,
- clears the `Event` facade's cached root,
- and points Eloquent's static dispatcher at the greased one.

**Register it first** in the providers array (or as early as practical). It is not a
correctness requirement — listeners registered before the swap are carried over, and
listeners registered after land on the greased dispatcher directly, so either way they
all fire. But going first means every later provider that resolves `events` gets the
greased instance, rather than one that resolved and *stored* `events` earlier holding
onto the original. Grease already handles the two it can reach (the `Event` facade and
Eloquent's static dispatcher); registering first covers any others.

Everything keeps working exactly as before — just faster. Because the parity bar
here is behavioural rather than byte-output, it's the one place worth a smoke test
in your own suite if you lean heavily on event ordering; the package's own
integration tests cover the swap landing correctly in the container, facade, and
Eloquent.
