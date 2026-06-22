# Changelog

All notable changes to `grease` are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **`Grease\Events\Dispatcher::hasListeners()` now memoizes its presence check** per
  event name, against the same cache the `dispatch()` fast path already used (reset on
  `listen()`/`forget()`, so behaviour is identical). Previously only `dispatch()`
  consumed the cache; the public `hasListeners()` re-scanned every wildcard on each
  call. This matters because the framework fires view events through a `hasListeners()`
  guard (`ManagesEvents::callCreator`/`callComposer`), not a bare `dispatch()` — so the
  Blade/Livewire render path never reached the cache before. Re-rendering the same
  components now costs one wildcard scan per distinct view name instead of one per
  render: **−92%** on a Livewire-shaped render with realistic wildcards registered
  (557 μs → 47 μs, new `ViewEventBench`).

## [0.2.0] - 2026-06-22

Two opt-in serialization helpers for code that builds its output array by hand
(Scout `toSearchableArray`, a `JsonResource`, an export) and so bypasses the
`attributesToArray()` date tier. Both are byte-identical to a named vanilla
expression; the parity suite grows to 225 tests.

### Added

- **`greaseSerializeDate(string $key): ?string`** on `HasGreasedSerialization` — the
  date-serialization tier's fast path exposed as a standalone primitive, so code that
  *hand-picks* attributes (Scout `toSearchableArray`, a `JsonResource`, an export)
  captures the date win without routing the whole model through `attributesToArray()`.
  Returns byte-identical output to `serializeDate(asDateTime($this->attributes[$key]))`
  (the `toJSON` form), reusing the existing per-class probe — certified classes skip
  the Carbon round-trip, everything else defers to the exact vanilla composition.
  Eligible for timestamps and plain `datetime` / `immutable_datetime` casts;
  −80% on the hand-pick date-serialization path (62.8μs → 12.3μs, fresh-hydrated).
- **`greaseSerializeOnly(array $keys): array`** on `HasGreasedSerialization` —
  serialize a curated subset of a model to its array form, byte-identical to
  `Arr::only($model->attributesToArray(), $keys)` but without serializing the columns
  the filter would discard. The greased array path (date tier included) runs over the
  narrowed set; the model's own `visible`/`hidden` config is honored and its visible
  list is restored before returning (non-mutating, no `clone`). Picking 3 of 23 cast-
  heavy columns: −87% vs the naive serialize-all-then-filter (175.8μs → 22.1μs), and a
  statistical tie with mutating `setVisible(...)->attributesToArray()` — the win is the
  skipped work plus non-mutation, so no per-key-set precompute was added (it had no
  measurable headroom to recover).

## [0.1.0] - 2026-06-22

First release. Opt-in performance for Laravel's hot paths, with output asserted
**byte-identical** to vanilla Eloquent across every cast type, edge value, and
dirty-check.

### Added

- **`HasGrease`** umbrella trait (and a **`GreasedModel`** base class) composing four
  per-model tiers over a single lazily-built, per-class blueprint:
  - **`HasGreasedHydration`** — removes the per-row `ReflectionClass`, the per-instance
    casts rebuild, and `newInstance`'s redundant work during hydration.
  - **`HasGreasedAttributes`** — memoizes `getCasts` / `getCastType` / `getDates` /
    mutator probes / `getDateFormat`; a divergence guard keeps runtime
    `mergeCasts()` / `withCasts()` correct.
  - **`HasGreasedCasts`** — replaces the per-access cast `switch` with a flyweight
    resolved once per cast type, an enum fast path that delegates conversion to the
    framework, and synonym-cast flyweight deduplication.
  - **`HasGreasedSerialization`** — eliminates the Carbon parse-and-reformat round-trip
    for date serialization when a per-class probe certifies the output is a
    byte-identical rewrite; defers to vanilla otherwise.
- **`Grease\Events\Dispatcher`** — a drop-in, behaviour-identical faster event
  dispatcher (no-listener fast path, cached listener resolution, pre-compiled wildcard
  patterns), opt-in via `GreaseEventServiceProvider`. Speeds up every dispatch
  app-wide, not just model events.
- Parity test suite (the byte-identical contract) and a phpbench + real-world
  benchmark harness driven by the same fixtures the tests prove identical.

### Requirements

- PHP 8.2+
- Laravel 12 / 13

[Unreleased]: https://github.com/One-Learning-Community/grease/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/One-Learning-Community/grease/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/One-Learning-Community/grease/releases/tag/v0.1.0
