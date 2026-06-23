# Benchmarks

> *"I only trust a benchmark I've falsified myself."* — not Churchill

So don't take ours. Numbers you can trust come from a method you can see — and every
figure below ships with the harness that made it, so you can run it on the hardware,
libc, and allocator you actually deploy on. Everything runs over the **same fixtures the
parity tests prove byte-identical**, so each benchmark times exactly the behaviour a test
certifies.

All numbers here are measured on **Linux** via the repo's Docker image
([`benchmarks/docker`](#a-benchmark-is-a-property-of-the-build)) — not macOS, which
distorts filesystem- and allocation-bound work (the [why](#a-benchmark-is-a-property-of-the-build)
is below).

## End to end, per request (incl. SQL)

In-memory SQLite, real queries, controller-shaped workloads. Vanilla Eloquent vs. the
same models with `HasGrease`. Output is byte-identical.

| Endpoint (one request, incl. SQL) | vanilla | + Grease | Δ |
| --- | ---: | ---: | :---: |
| index: list 100 users → JSON | 3.12 ms | 0.69 ms | **−78%** |
| eager: 100 posts with author → JSON | 6.01 ms | 1.39 ms | **−77%** |
| bulk: load 150, mutate, save | 7.25 ms | 5.90 ms | **−18%** |
| show one post (with author) | 0.11 ms | 0.06 ms | **−47%** |

```bash
php benchmarks/realworld.php
```

The gain scales with how much your request hydrates: wide selects, eager loads, and
serialization-heavy API responses benefit most. A request that does almost no
Eloquent work has almost nothing for Grease to speed up — and that's the honest
shape of it. These are `:memory:` numbers — read the
[methodology note below](#how-to-read-these-honestly) before mapping them onto a
networked database.

## Per operation (CastBench, A/B)

In-memory, paired `*Vanilla` / `*Greased` subjects, so you read the per-operation
delta directly. Representative deltas (vanilla → greased):

| Operation | Δ |
| --- | :---: |
| hydrate a row | **−34%** |
| `toArray()` (serialize) | **−47%** |
| set + dirty-check | **−39%** |
| read all casts | **−27%** |
| read an enum cast | **−48%** |
| date serialization (timestamps / `datetime` casts) | **−87% / −89%** |

```bash
composer bench
```

The standout is **date serialization**: skipping the Carbon parse-and-reformat
round-trip saves roughly 27 µs *per date column per row*. On an API response with a
few timestamps across a hundred rows, that single tier is most of the win.

Building your array by hand — Scout's `toSearchableArray`, a `JsonResource`, an
export — bypasses `toArray()` and so this tier. The
[serialization helpers](/guide/serialization-helpers) hand it back: `greaseSerializeDate()`
(−86% on a hand-picked date) and `greaseSerializeOnly()` (−91% on a curated subset of a
wide model). Validate both with `php benchmarks/serialize_helpers.php`.

## Beyond Eloquent: the dispatcher

Measured separately (`DispatcherBench`, `EventStormBench`), because it's app-wide,
not per-model:

| Scenario | Δ |
| --- | :---: |
| dispatch with no listener | **−53%** |
| dispatch with listeners | **−18%** |
| event-dense request, warm | **−57%** |
| event-dense request, cold (with wildcards) | **−54%** |

On an event-dense request it roughly **halves** the event overhead. There's a further
win on a Blade- or Livewire-heavy page: the framework fires `creating:`/`composing:`
through a `hasListeners()` guard (`callCreator`/`callComposer`), not a bare `dispatch()`,
and the greased dispatcher memoizes that presence check — so re-rendering the same
components stops re-scanning wildcards every time. How much that's worth depends on how
many observer/wildcard listeners you've registered. See
[The Event Dispatcher](/guide/events).

## Beyond Eloquent: Blade components

A third axis again — the render path, not the model. The provider swaps two singletons
(`blade.compiler` and `view`) for greased, byte-identical drop-ins. The macro
([`benchmarks/blade.php`](/guide/blade)) now runs **eight parity-gated variants**, each
asserting the HTML is identical before it times anything:

| Variant | Δ |
| --- | :---: |
| simple (initials + one attribute merge) | **−38.9%** |
| rich (5 props, `@php`, conditionals, slots) | **−29.9%** |
| app page (class components, slots, `@include`/`@each`, composer) | **−21.4%** |
| data table (nested `@foreach`, heavy `$loop` use) | **−27.8%** |
| layout (`@extends`/`@section`/`@yield`/`@push`) | **−19.4%** |
| asset stacks (`@push`/`@prepend` per row into a `@stack`) | **−17.7%** |

```bash
php benchmarks/blade.php
```

That's seven byte-identical wins compounded: `@props` resolution, the
`$attributes->merge()` pipeline, a greased bag for class components, `getCompiledPath`
memoization, `@foreach`'s `$loop` bookkeeping, `@yield`'s content stitching, and the
`@push`/`@prepend` stack assembly — not the halving Taylor asked for. The split is by page shape: **component greasing wins on
component-dense pages, loop greasing on cheap-bodied loops** (tables, lists) — and the two
compose with zero regression. The honest scope, the dead ends we measured and rejected,
and how to profile it are in [Blade Components](/guide/blade).

## How to read these honestly

This package was built measure-first, and the docs hold the same line. A few things
worth knowing so you can map these numbers onto *your* deploy:

::: warning In-memory SQLite inflates the percentage — read the absolute time
The macro runs on `:memory:` SQLite, where database I/O is near-zero. That makes the
ORM layer (and therefore Grease's slice) a *larger fraction* of total request time
than it would be against a networked Postgres/MySQL.

The portable figure is the **absolute time Grease removes from the ORM layer** — that
stays roughly the same regardless of your database. The **percentage** shrinks as
network and I/O take a bigger share of the request. So treat the per-request
percentages as "Grease's share of the Eloquent-bound work," not "your p99 will drop
17%." If your endpoint is I/O-bound, you'll see the same milliseconds saved against a
larger denominator.
:::

- **Per-op vs per-request.** A −34% on `hydrate` is a per-operation figure; it
  becomes a per-request figure only multiplied by how many rows you hydrate. The
  end-to-end table is the one that includes your SQL.
- **The win is workload-shaped.** Grease accelerates hydration, casting, and
  serialization. It does nothing for query building, validation, or your business
  logic. Profile first — if Eloquent isn't your hot path, this isn't your package.
- **Marginal numbers stay marginal numbers.** Where a tier benchmarked inside the
  noise, it was parked, not shipped, and that's recorded openly in the repo's notes.
  The figures here are the ones that cleared the bar.

## A benchmark is a property of the build

Why Linux, not the Mac these were first written on: macOS's `/var`→`/private/var`
symlink confuses opcache's realpath keying and CLI opcache behaves unlike production. It
*inflated* the per-op microbench wins and *understated* Blade — the `is_file()` it ranked
at ~8% of a render is ~3% on Linux. (Xdebug lied too: it ranked `extract` at ~14% of a
render when a micro proved it ~0.6% — it disables JIT and mis-attributes internal-op
cost. Use a sampling profiler; the repo ships an Excimer harness.)

But even "Linux" isn't one number. The same `CastBench`, same machine, **only the libc
and allocator changed**:

| Op | glibc | musl |
| --- | :---: | :---: |
| read all casts | −26.5% | **−33%** |
| `toArray()` | −46% | **−52%** |

Grease's wins are *allocation* wins, and musl's allocator makes the vanilla arm pay more
— so the same optimization reads bigger on musl. (jemalloc via `LD_PRELOAD` didn't even
run — it crashed PHP's JIT; "drop-in allocator" is a myth. And run-to-run under load swung
glibc `setDirty` −39%↔−27%, wider than the libc gap.) **Treat the figures as
representative, not a promise.** Reproduce on *your* build — it's one command.

## Reproduce everything

```bash
# Linux, the canonical environment (glibc; swap Dockerfile.alpine for musl):
docker build -t grease-bench benchmarks/docker
docker run --rm -v "$PWD":/app -w /app grease-bench php benchmarks/realworld.php

# or directly, on whatever PHP you have:
composer test                       # parity tests — the byte-identical contract
composer bench                      # phpbench: CastBench (per-op A/B) + SuiteBench (SQL)
php benchmarks/realworld.php         # the end-to-end macro above
php benchmarks/blade.php             # Taylor's 1,000-component challenge
php benchmarks/serialize_helpers.php # greaseSerializeDate / greaseSerializeOnly, A/B
php benchmarks/blade_excimer.php     # honest sampling profile of the render path
```

Same fixtures, both sides. A bench runs exactly what a test proves identical.
