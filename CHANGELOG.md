# Changelog

All notable changes to `grease` are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The foundation axis — two new tiers beyond the model trait**, each opt-in,
  byte-/behaviour-identical, targeting the per-request hot paths rather than the model:
  - **`Grease\Container\Container` / `Grease\Container\Application`** — a constructor
    *blueprint*: vanilla `Container::build()` rebuilds class-pure reflection
    (`ReflectionClass`/`getConstructor`/`getParameters` + per-parameter class-name and
    attribute walks) on every transient resolve; the blueprint freezes that per concrete
    and replays it, caching reflection — never resolution, so contextual bindings, `$with`
    overrides, and late rebinds stay live. **−38.8% per resolve** (Linux); end-to-end a
    compounding tier (~−5% boot, −5.4→−7.9% dispatch — resolution is a thin slice of a
    request, and the whole story under Octane). Opt in with a one-line `bootstrap/app.php`
    swap (the container builds itself before any provider runs). Parity: `BlueprintParityTest`
    + a full-boot Testbench parity test.
  - **`Grease\Http\Request`** — per-instance input memoization: vanilla `input()`/`all()`
    rebuild the merged input map on every call (and `__get`/`has`/`only`/`except`/`filled`
    all re-funnel through them); memoize the base arrays + `isJson()`, invalidating on every
    mutation — value mutators and the lifecycle paths (`clone`/`duplicate`, `initialize`,
    `setMethod`). **−41% per request** (Linux). One carve-out: direct input-source-bag
    mutation; `attributes`/`cookies` mutation is safe. Opt in with
    `Grease\Http\Request::capture()` in `public/index.php`.
- **Cumulative-stack pipeline benchmark** (`benchmarks/stack_pipeline.php`,
  `StackPipelineBench`, `StackPipelineParityTest`) — a real request through the kernel
  (four query shapes × JSON + Blade) measured with each tier layered in least→riskiest.
  Full page-load suite **−47%** (Linux); retained memory **+2.3%** for all six tiers
  combined. Parity suite grows to **400 tests / 1066 assertions**.

## [0.3.0] - 2026-06-23

The performance surface roughly doubles. The Eloquent model trait grows from four tiers
to **seven** and gains three byte-identical short-circuits on the hot read/write paths; a
full drop-in Blade view **Factory** joins the compiler as a second render-path axis; the
event dispatcher's view-event guard is memoized; and every published benchmark number is
now generated live from the parity-gated harness. Output stays byte-identical to vanilla
throughout — the parity suite grows to **317 tests / 867 assertions**.

### Added

- **Three new Eloquent model tiers** — `HasGrease` now composes seven, each a method
  override that reads the per-class blueprint and falls back to `parent::`:
  - **`HasGreasedClassAttributes`** — caches `Model::resolveClassAttribute()` (the
    `#[Table]`/`#[Fillable]`/`#[Hidden]`/`#[Appends]`/… lookups the per-instance
    `initialize*` booters run ~13× per `new` model) behind a concat-free
    `[class][attribute]` carve-out, replacing vanilla's freshly-built `"$class@$attr"`
    cache key. Byte-identical bug-for-bug, including vanilla's property-less cache-key
    collision quirk. **−13% on a 2,100-row eager `get()`.**
  - **`HasGreasedInitializers`** — freezes the four surviving `initialize*` trait booters
    (guards / hides / timestamps / touches) per class: the cold path runs `parent::` once
    and snapshots the resulting properties; warm instances apply the snapshot by copy,
    eliminating the `resolveClassAttribute()` calls entirely. **−8.4%** on the eager
    `get()`, on top of the cache above.
  - **`HasGreasedCastProbes`** — memoizes the per-key cast-classification probes
    (`isEnumCastable` / `isClassCastable` / `isClassSerializable`) that
    `addCastAttributesToArray()` reruns on every row during `toArray()`. The verdict is a
    pure function of the cast type; memoized per `[class][probe][key]` (with
    `array_key_exists`, since the common verdict is `false`), reusing the casts-divergence
    guard. **−10% on a rich-cast `get()->toArray()`.**
- **A greased Blade view Factory (`Grease\View\Factory`) — a second Blade render-path
  axis**, bound as the `view` singleton by `GreaseViewServiceProvider` alongside the
  compiler. It overrides the hottest, allocation-shaped frames on the render path, each
  byte-identical and gated by a parity-asserted macro:
  - `@foreach` `$loop` bookkeeping — an in-place by-ref index update instead of an
    `array_merge` of the 10-key state every iteration (**−27% on a `$loop`-heavy table**);
  - `@yield` — one `preg_replace_callback` over three non-overlapping markers in place of
    vanilla's three `str_replace` scans of the whole section (**−19% on a layout**);
  - `@push`/`@prepend` stack assembly — drops the per-pop `tap()` closure (**−18% on
    asset stacks**);
  - a one-line emit seed so class/no-`@props` components build the greased
    `ComponentAttributeBag` from the start, and `getCompiledPath()` memoization.
- **`Grease\View\ComponentAttributeBag`** — a `ComponentAttributeBag` subclass whose
  `merge()` is two plain `foreach` loops instead of vanilla's ~5-allocation Collection
  pipeline (`partition`/`mapWithKeys`/`->merge`/`->all`). The compiler hands components
  this subclass, so the `$attributes->merge([...])` nearly every component runs takes the
  fast path; `merge()` returns `new static` to stay greased down a chain. Together with
  the compiler and Factory: **−38.7% simple / −30.6% rich** on a 1,000-component render.
- **Live benchmark pipeline** — `bash benchmarks/export-metrics.sh` runs every family on
  the canonical Linux Docker image and writes a single parity-gated
  `docs/.vitepress/data/benchmarks.json` that the docs (and the README's one-line summary)
  render from. The published numbers are now exactly what the harness measured, and a
  `PARITY FAIL` in any family aborts before its JSON is written.
- **A greased Blade compiler (`Grease\View\Compiler`) — a new opt-in tier** that rewrites
  one emit, `@props` resolution, the per-render hot path every component pays. Vanilla
  compiles `@props` to a ~20-line block that, on each render, rebuilds a flat name list
  (`ComponentAttributeBag::extractPropNames`), partitions incoming attributes with
  `in_array` (a linear scan per attribute), evaluates the `@props` array literal *twice*
  (once for the names, once for the `array_filter` that finds the defaults), allocates a
  second attribute bag, and snapshots the whole scope with `get_defined_vars()` to unset
  attribute-named locals. The greased emit collapses all of that into one call —
  `Grease\View\Props::mergeAttributes($site, $decl, $attributes)` — that partitions,
  applies defaults, and returns a single map (the resolved prop locals plus `attributes`,
  the surviving bag) which a tight `$$key = $value` loop binds into scope. The name set
  and which keys carry defaults are memoized per `@props` site (compile-time constants),
  so only fresh default *values* are read each render; the declaration is evaluated once;
  and the loop (not `extract`, which is slower and skips non-identifier keys) reproduces
  vanilla's locals exactly — including the inaccessible `${'icon-name'}` kebab-alias
  local. Bind it by registering the (non-auto-discovered)
  `Grease\View\GreaseViewServiceProvider`, which `extend`s `blade.compiler` via
  `Compiler::fromBase()` (registered directives/components carry over). Behaviour-
  identical, asserted A/B against the stock compiler across declaration/attribute
  scenarios (execution parity, incl. `get_defined_vars`). **~−14%** on a full
  1,000-anonymous-component render (`benchmarks/blade.php`, Taylor's 2024 challenge),
  holding across simple and prop-heavy components — a real, free, parity-safe slice of
  every component render that compounds with the other tiers.

  *Narrowing:* the declaration is evaluated once, not twice — so a non-deterministic
  default (e.g. `@props(['id' => uniqid()])`) yields one value rather than vanilla's
  second-evaluation value. Byte-identical for deterministic defaults (the norm).

### Changed

- **Three byte-identical short-circuits on the model hot paths**, added to the existing
  hydration and serialization tiers:
  - **Empty `fill([])`** — the no-op that `__construct` runs on every hydrated row (via
    `newFromBuilder`'s `new static`) still computed `totallyGuarded()` and
    `fillableFromArray([])` up front. `HasGreasedHydration` now returns `$this` early on
    `[]`. **−5% on an eager `get()`.**
  - **Relation-less `toArray()`** — vanilla wraps every `toArray()` in `withoutRecursion()`
    (a `debug_backtrace` + `Onceable` trace-hash + WeakMap, to guard circular relations).
    With no relations loaded there is nothing to recurse into, so `HasGreasedSerialization`
    returns `attributesToArray()` directly; any loaded relation defers to `parent::`, guard
    intact. Byte-identical, including the circular-relation case. **−27% on a relation-less
    `get()->toArray()`** (index endpoint −81% → −86%).
  - **`fromDateTime()` on dirty-checks** — `originalIsEquivalent()` on `save()` compares a
    date column as `fromDateTime($attr) === fromDateTime($original)`, where the operands are
    storage strings — so vanilla parses each into Carbon and formats it straight back to the
    identical string. A per-class probe certifies the round-trip and the fast path returns
    the string as-is. **−38% on a `save()`-heavy dirty-check.**
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

[Unreleased]: https://github.com/One-Learning-Community/grease/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/One-Learning-Community/grease/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/One-Learning-Community/grease/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/One-Learning-Community/grease/releases/tag/v0.1.0
