# Grease

[![tests](https://github.com/One-Learning-Community/grease/actions/workflows/tests.yml/badge.svg)](https://github.com/One-Learning-Community/grease/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/onelearningcommunity/grease.svg)](https://packagist.org/packages/onelearningcommunity/grease)
[![Total Downloads](https://img.shields.io/packagist/dt/onelearningcommunity/grease.svg)](https://packagist.org/packages/onelearningcommunity/grease)
[![License](https://img.shields.io/packagist/l/onelearningcommunity/grease.svg)](LICENSE)

**Opt-in performance for Laravel's hot paths — built from optimizations declined upstream.**

Grease is a single trait you add to a model. It makes attribute hydration, casting,
and serialization measurably faster, with **byte-identical output** to vanilla
Eloquent. Zero changes to the framework. Add it to the models you care about; leave
the rest untouched.

```php
use Grease\Concerns\HasGrease;

class User extends Model
{
    use HasGrease;
}
```

or extend the base model:

```php
class User extends \Grease\GreasedModel { /* ... */ }
```

## Why this exists

Eloquent re-derives class-pure facts on *every* attribute access and *every*
hydrated row: it rebuilds the casts array, re-walks a cast `switch`, re-probes
`method_exists` for mutators, re-resolves the connection's date format, and runs a
fresh `ReflectionClass` per `new Model`. None of it changes for the life of the
class. Grease computes each fact once per class and reuses it.

These optimizations have been proposed to Laravel core and declined on reasonable
grounds — each one is "marginal in isolation," and a framework carries a stability
cost for every branch it adds to everyone's hot path. Individually, maybe.
**Together, on a real request, they aren't.**

The attempts span Laravel 9 through 13, because the wins kept measuring as real —
attribute casting ([#43554](https://github.com/laravel/framework/pull/43554),
[#60550](https://github.com/laravel/framework/pull/60550)), `getDateFormat()` caching
([#55129](https://github.com/laravel/framework/pull/55129)), the event dispatcher
([#51184](https://github.com/laravel/framework/pull/51184)). An opt-in package is the
right home for a change that's a clear win for some apps and unnecessary weight for the
framework. Grease is where this work lives now.

## Benchmarks

In-memory SQLite, real queries, controller-shaped workloads — **end-to-end per
request, including SQL.** Vanilla Eloquent vs. the same models with `HasGrease`.
Output is byte-identical (asserted across every cast type and workload).

| Endpoint (one request, incl. SQL)   | vanilla | + Grease |  Δ   |
| ----------------------------------- | ------: | -------: | :--: |
| index: list 100 users → JSON        | 10.9 ms |   2.9 ms | −73% |
| eager: 100 posts with author → JSON | 20.8 ms |   5.5 ms | −74% |
| bulk: load 150, mutate, save        | 34.8 ms |  27.7 ms | −20% |
| show one post (with author)         | 0.38 ms |  0.21 ms | −45% |

> **Read these as Grease's share of the Eloquent-bound work, not your p99.** The macro
> runs on `:memory:` SQLite, where database I/O is ~zero — so the ORM (and Grease's
> slice of it) is a large fraction of the request. The portable figure is the
> **absolute time removed** (~8 ms off `index`, ~15 ms off `eager`); against a networked
> database the same milliseconds are a smaller percentage of a slower request. The gain
> scales with how much a request hydrates and serializes.

Reproduce: `php benchmarks/realworld.php` (see [`benchmarks/`](benchmarks)).

## Caveats

Two narrow, obscure things change on a greased model's cast path. Custom casts
([`CastsAttributes`](https://laravel.com/docs/eloquent-mutators#custom-casts)), the
documented extension point, **work unchanged** — and so does overriding
`getCastType()` (a subclass override shadows the trait and stays fully live; the
resolved type is otherwise memoized per class, like `getCasts()`). Enum casts are
accelerated; class-castable and encrypted casts defer to vanilla, unaccelerated but
byte-identical. The full cast contract is asserted byte-identical to vanilla in the
test suite (every cast type × edge values × dirty-checking).

1. **Per-instance `$casts` set in a constructor isn't supported.** The cast map is
   cached per class, so assigning a *different* `$casts` per instance inside a
   model's constructor would serve the first instance's map. Use `mergeCasts()` /
   `withCasts()` at runtime instead (these are honored — the cache steps aside).
   This pattern is vanishingly rare in real apps.

2. **A per-key `isEncryptedCastable()` override is not honored.** Overriding that
   undocumented internal to encrypt an attribute whose cast type isn't itself an
   `encrypted:*` type won't decrypt on a greased model. The idiomatic way —
   `'ssn' => 'encrypted:string'` — works perfectly. Nobody overrides this on purpose.

Removing the machinery that preserves that unused flexibility is precisely where a
chunk of the speedup comes from.

Want zero cast caveats at all? Use the tiers à la carte and skip the cast one —
you keep the hydration and metadata wins:

```php
use Grease\Concerns\HasGreasedHydration;
use Grease\Concerns\HasGreasedAttributes;

class User extends Model
{
    use HasGreasedHydration;   // construct / hydration
    use HasGreasedAttributes;  // cast/date/mutator metadata memoization
}
```

## How it works

Every tier is a method override that reads a single per-class "blueprint"
(`static::$greaseBlueprint[$class]`) and falls back to `parent::` for anything it
doesn't accelerate. The blueprint is built lazily on first use and invalidated as a
unit. Non-greased models run pure vanilla Eloquent — Grease adds **zero** cost to
models that don't use it.

| Tier                    | What it memoizes / removes                                              |
| ----------------------- | ----------------------------------------------------------------------- |
| `HasGreasedHydration`   | per-row `ReflectionClass`, casts-array rebuild, redundant `newInstance` |
| `HasGreasedAttributes`  | `getCasts` / `getCastType` / `getDates` / mutator probes / `getDateFormat` |
| `HasGreasedCasts`       | per-access cast `switch` → resolved flyweights, incl. an enum fast path (see caveat) |
| `HasGreasedSerialization` | the Carbon parse-and-reformat round-trip for date serialization (timestamps + `datetime` casts), when a per-class probe proves the output is a byte-identical rewrite |

## Beyond Eloquent: a faster event dispatcher

Grease also ships a drop-in faster event dispatcher (a port of
[laravel/framework#51184](https://github.com/laravel/framework/pull/51184)) — a
no-listener fast path, a cached listener resolver, and pre-compiled wildcard
patterns. It's **behaviour-identical** to the stock dispatcher (same listeners, same
order, same return values) and speeds up *every* dispatch in the app, not just model
events. Opt in by registering the provider (it is **not** auto-discovered):

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Events\GreaseEventServiceProvider::class,
```

It swaps the bound `events` singleton for the greased dispatcher (carrying over any
already-registered listeners) and points Eloquent's dispatcher at it too. On an
event-dense request (a page render's worth of dispatches) this roughly **halves**
the event overhead — ~−56% when most events have no listener, ~−47% per-request when
non-trivial wildcard listeners (model observers, package patterns) are registered.

## Benchmarks

```bash
composer bench                  # phpbench: per-operation A/B + the suite, end-to-end
php benchmarks/realworld.php     # macro: real endpoints, full request incl. SQL
```

`composer bench` runs two phpbench suites over the **same fixtures the tests
prove byte-identical**:

- **`CastBench`** — in-memory A/B with paired `*Vanilla` / `*Greased` subjects, so
  you read the per-operation delta directly (hydrate, read, `toArray`, set+dirty).
- **`SuiteBench`** — drives the real SQL roundtrip test suite (migrate → query →
  write → eager-load) through a booted app, so any covered path's cost is tracked.

Representative `CastBench` deltas (vanilla → greased, in-memory, your hardware will
vary): hydrate −53%, `toArray` −53%, set+dirty −44%, read-all-casts −31%, enum cast
−58%. The event dispatcher tier is measured separately by `DispatcherBench` and
`EventStormBench`.

## Requirements

PHP 8.2+, Laravel 12/13.

## License

Released under the [MIT License](LICENSE) — Copyright © 2026 One Learning Community LTD.

## Built with Claude

Grease was built proudly in collaboration with [Claude](https://claude.com/claude-code)
— a small proof of what a strong engineering mindset and AI can do together: measure
first, keep the parity spine honest, and ship the wins core couldn't.
