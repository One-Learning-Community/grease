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

Two further opt-in strands go beyond the model trait, on their own axes: a drop-in
[faster event dispatcher](#beyond-eloquent-a-faster-event-dispatcher) and a
[faster Blade component render](#beyond-eloquent-faster-blade-components). Same rule
throughout — behaviour/byte-identical to vanilla, opt in only where you want it.

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

> *"I only trust a benchmark I've falsified myself."* — not Churchill
>
> So don't take ours. Every number below ships with the harness that made it: `docker build
> -t grease-bench benchmarks/docker && docker run --rm -v "$PWD":/app -w /app grease-bench php
> benchmarks/realworld.php`. Swap in `Dockerfile.alpine` for musl, your own base image for
> your libc/allocator, your own silicon — and read what *your* build actually does.

In-memory SQLite, real queries, controller-shaped workloads — **end-to-end per
request, including SQL.** Vanilla Eloquent vs. the same models with `HasGrease`.
Output is byte-identical (asserted across every cast type and workload). Measured on
Linux via [`benchmarks/docker`](benchmarks/docker) (PHP 8.4, opcache + JIT) — see the
note below on why not macOS.

| Endpoint (one request, incl. SQL)   | vanilla | + Grease |  Δ   |
| ----------------------------------- | ------: | -------: | :--: |
| index: list 100 users → JSON        | 3.12 ms |  0.69 ms | −78% |
| eager: 100 posts with author → JSON | 6.01 ms |  1.39 ms | −77% |
| bulk: load 150, mutate, save        | 7.25 ms |  5.90 ms | −18% |
| show one post (with author)         | 0.11 ms |  0.06 ms | −47% |

> **Read these as Grease's share of the Eloquent-bound work, not your p99.** The macro
> runs on `:memory:` SQLite, where database I/O is ~zero — so the ORM (and Grease's
> slice of it) is a large fraction of the request. The portable figure is the
> **absolute time removed** (~2.4 ms off `index`, ~4.6 ms off `eager`); against a
> networked database the same milliseconds are a smaller percentage of a slower request.
> The gain scales with how much a request hydrates and serializes.

> **Why Linux/Docker, not macOS.** macOS distorts these: the `/var`→`/private/var`
> symlink confuses opcache's realpath keying and CLI opcache behaves unlike production,
> which inflates filesystem-bound work and the microbench baselines. The numbers here are
> from [`benchmarks/docker`](benchmarks/docker) (Linux/glibc). Your **build** will differ,
> not just your hardware: CPU, libc (glibc vs musl), and the memory allocator all move the
> numbers — and Grease's wins are mostly *allocation* wins, so that axis matters most. (We
> measured ~3–6 pt swings on alloc-heavy ops between glibc and musl; see `Dockerfile.alpine`.)
> Treat the figures as representative, not a promise — reproduce on your target; the harness
> makes that one command.

Reproduce: `docker build -t grease-bench benchmarks/docker && docker run --rm -v "$PWD":/app -w /app grease-bench php benchmarks/realworld.php`.

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

### Serializing hand-picked dates

`attributesToArray()` is where the date tier fires — but plenty of code builds its
output array by hand (Scout's `toSearchableArray`, a `JsonResource`, a CSV export),
reading one attribute at a time and so bypassing the tier entirely. For those,
`greaseSerializeDate()` exposes the same fast path as a one-attribute primitive:

```php
public function toSearchableArray(): array
{
    return [
        'title'      => $this->title,
        'created_at' => $this->greaseSerializeDate('created_at'), // was: $this->created_at?->toJSON()
    ];
}
```

It returns exactly what `attributesToArray()` would emit for that key (the `toJSON`
form, e.g. `2026-01-01T00:00:00.000000Z`) — byte-identical, just without the
per-field Carbon parse. It accelerates timestamps and plain
`datetime` / `immutable_datetime` casts; a `date` cast, custom-format datetime, or
custom serializer simply falls back to the exact vanilla composition, so it is always
safe to call. On a fresh-hydrated row, swapping `?->toJSON()` for it cut the hand-pick
date path **−87%** (18.2μs → 2.4μs) in the bench (Linux, `benchmarks/docker`).

### Serializing a curated subset

When you want a few columns of a wide model in array form, the line everyone writes is
`Arr::only($model->attributesToArray(), $keys)` — which serializes *every* column
(each cast, date, enum, decimal) and then throws most of them away.
`greaseSerializeOnly()` narrows the model's visibility first, so only the keys you
asked for are ever serialized:

```php
public function toSearchableArray(): array
{
    return $this->greaseSerializeOnly(['title', 'status', 'created_at']);
}
```

The output is byte-identical to `Arr::only($model->attributesToArray(), $keys)` — the
whole greased array path (date tier included) runs over the narrowed set, and the
model's `visible`/`hidden` config is still honored. It is **non-mutating**: unlike
`setVisible($keys)->attributesToArray()` it restores the model's visible list before
returning (and skips a `clone`). Picking 3 of 23 cast-heavy columns, it ran **−91%**
against the naive serialize-all-then-filter (43.3μs → 3.9μs; Linux, `benchmarks/docker`).

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
already-registered listeners) and points Eloquent's dispatcher at it too. Register it
**first** in the array (or as early as practical): already-registered listeners are
carried over either way, but going first means listeners other providers add land
directly on the greased dispatcher and nothing has captured the original. On an
event-dense request (a page render's worth of dispatches) this roughly **halves**
the event overhead — ~−57% when most events have no listener, ~−54% per-request when
non-trivial wildcard listeners (model observers, package patterns) are registered
(`EventStormBench`, Linux). The framework also fires view events (`creating:`/`composing:`)
through a `hasListeners()` guard that the greased dispatcher memoizes, so re-rendering the
same components stops re-scanning wildcards every time — the win there compounds with the
render itself, though the exact figure depends on how many observers you register.

## Beyond Eloquent: faster Blade components

The third strand is the Blade render path. It starts with a [tweet Taylor posted in April
2024](https://x.com/taylorotwell/status/1781039378376146970):

> Sometimes I get obsessed with figuring out if Blade component rendering performance can be
> supercharged. Rendering 1,000 anonymous components takes about 14ms on my MBP. Can we cut
> this in half? 🤔 Dig in and help me figure it out?

We've been chewing on *reliably* trimming that number ever since — on and off for two years.
"Reliably" is the hard part: it's easy to shave a render by changing what it emits; the bar
here is the same as everywhere else in Grease — **byte-identical output**, asserted before any
number is timed. Honest scope, too: this is **one challenge of limited breadth**, not a Blade
overhaul. We greased the two hot paths *every* component pays on *every* render:

- **`@props` resolution** — vanilla rebuilds a name list, `in_array`-scans it, evaluates the
  declaration twice, and snapshots the whole scope with `get_defined_vars()` on each render.
  Grease compiles it to one memoized call plus a tight bind loop.
- **`$attributes->merge([...])`** — vanilla runs it through the Collection pipeline (~5
  allocations a render); Grease does the identical partition + append in two `foreach` loops.

Opt in with the (not auto-discovered) provider, which swaps the `blade.compiler`; components
recompile to the tighter form on next change, or immediately after `view:clear`:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\View\GreaseViewServiceProvider::class,
```

On Taylor's exact challenge — 1,000 anonymous components, output asserted identical — that
lands **−38%** on a simple component and **−29.5%** on a rich one (Linux, `benchmarks/docker`;
`benchmarks/blade.php`). Not the halving he asked for: the rest of a render is the compiled
template executing and the per-component resolution machinery, and we haven't yet found a
*clean, parity-safe* win in that machinery (we've measured several and rejected them — see
[NOTES.md](NOTES.md)). So: two years on, what we'll stand behind is reliably about a third
off, byte-for-byte — and the harness is right there if you want to chase the other half.

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

Representative `CastBench` deltas (vanilla → greased, in-memory, Linux/`benchmarks/docker`,
your hardware will vary): hydrate −34%, `toArray` −47%, set+dirty −39%, read-all-casts
−27%, enum cast −48%; date serialization (timestamps / `datetime` casts) −87% / −89%. The
event dispatcher tier is measured separately by `DispatcherBench` (no-listener −53%,
with-listeners −18%) and `EventStormBench` (−54% to −57% per render-shaped storm).

## Requirements

PHP 8.2+, Laravel 12/13.

## License

Released under the [MIT License](LICENSE) — Copyright © 2026 One Learning Community LTD.

## Built with Claude

Grease was built proudly in collaboration with [Claude](https://claude.com/claude-code)
— a small proof of what a strong engineering mindset and AI can do together: measure
first, keep the parity spine honest, and ship the wins core couldn't.
