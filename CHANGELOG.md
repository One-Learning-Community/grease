# Changelog

All notable changes to `grease` are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.1] - 2026-06-24

A byte-identical hardening release. A full parity audit of the whole package surfaced four
silent output divergences from vanilla, plus a few normal-usage cache-staleness edges. No API
changes; every fix ships with a regression test that fails on the old behaviour, and the
dispatch path actually came out *faster*.

### Fixed

- **Event dispatcher: interface listeners are no longer dropped on a string-dispatched class
  event.** The no-listener fast path gated on `hasListeners()`, which doesn't see interface
  listeners — so `dispatch(SomeEvent::class)` whose only listener was bound to an interface it
  implements silently fired nothing (vanilla fires it via `addInterfaceListeners()`). The fast
  path now also rules out interface listeners, gated on a boot-time flag set only when an
  interface-named listener is registered — so apps without them pay nothing, and no-listener
  dispatch is *faster* than before (the flag replaces a per-dispatch reachability check).

- **Casts: an enum that also implements `Castable` now routes to its `castUsing()` cast.** The
  enum fast path gated on `enum_exists()` alone; vanilla's `isEnumCastable()` excludes `Castable`
  subclasses, so such an enum returned its raw case instead of the cast's output. Now mirrored —
  the extra check is memoized per cast type, so zero hot-path cost.

- **Serialization: out-of-range / zero dates defer to vanilla instead of being rewritten
  verbatim.** The date fast path's shape check was purely syntactic, so a legacy MySQL
  `0000-00-00 00:00:00` (or `2026-02-30`) was emitted as-is while vanilla overflow-normalizes it
  through Carbon (`-0001-11-30`). The guard now also confirms the date is real (`checkdate` + time
  ranges) before fast-pathing; valid dates still skip the Carbon round-trip. Same fix on the
  `fromDateTime()` write path, which feeds the `save()` dirty check.

- **Serialization: `toArray()` no longer drops a relation an appended accessor lazily loads.** The
  relation-less short-circuit checked `relations === []` before running accessors — but an
  `$appends` accessor reading `$this->someRelation` loads it as a side effect, which vanilla emits
  (relations serialize after attributes). The short-circuit now applies only if relations are still
  empty after the attributes are built.

- **Attributes: `getDates()` is no longer poisoned by a timestamps-disabled instance.** The
  per-class cache ignored `$model->timestamps = false` / `withoutTimestamps()`, so a timestamps-off
  instance could blank the cache for timestamps-on instances of the same class (dropping
  `created_at`/`updated_at` from their output). Now keyed by `usesTimestamps()`.

- **Attributes: runtime `setKeyName()` / `setKeyType()` / `setIncrementing()` invalidate the cast
  cache.** These feed `getCasts()`'s key entry; the per-class cache now steps aside for an instance
  that mutates them at runtime, exactly like `mergeCasts()` / `withCasts()`.

- **Request: `isJson()` is read live, not memoized.** A content-type header changed after
  construction (content-negotiation middleware) left the memo stale; it now delegates to vanilla
  every call — the input-base memo already keeps it off the hot path.

### Changed

- **Documented two runtime-surgery narrowings** — reassigning a model's table (`setTable()` on a
  query prototype) or date format (`setDateFormat()` per instance) *after it's in use* — on the
  [Caveats](https://one-learning-community.github.io/grease/guide/caveats) page, alongside a new
  guiding principle: Grease optimizes the 95–99% case; if you do something unusual with your models
  or the request, run your own tests with Grease enabled.
- **Added a compiler drift guard** pinning the class-component opening emit byte-for-byte to the
  framework's static plus Grease's one seed line, so a future Laravel change to that emit (including
  a 12-vs-13 difference) fails CI instead of shipping a divergence.

## [0.5.0] - 2026-06-23

### Added

- **A greased validator — memoized rule parsing.** `ValidationRuleParser::parse` is a pure,
  context-free function of the rule string, but vanilla recomputes it constantly: `getRule()` loops
  every rule of an attribute and parses each, reached from many probes per validation — roughly
  O(rules²) parses per attribute within one `passes()`. `Grease\Validation\Validator` overrides that
  one looped parse site with a static memo (non-string rules bypass and parse live). Behaviour-identical
  — same pass/fail, error bag, and message order. Measured **−45.6%** end-to-end on a real six-field
  validation (Linux). Opt in with the (non-auto-discovered) `Grease\Validation\GreaseValidationServiceProvider`,
  which points the validation Factory's resolver at the greased validator. Parity: `ValidatorParityTest`
  + `GreaseValidationServiceProviderTest`.

- **`grease:view-cache` — an eager, opcache-interned view-resolution index.** `view:cache` compiles
  every Blade template but discards the *resolution* it computed: at runtime `FileViewFinder::find()`
  re-stat-walks `paths × extensions` to map a view name to its file (and never memoizes a miss, so
  dynamic / `@include($var)` / `<x-dynamic-component>` names re-stat on every render forever), and the
  engine re-hashes the path per render. `grease:view-cache` (a `view:cache` twin) records `name =>
  source path` and `source path => compiled path` into a constant `return [...]` file opcache interns
  into shared memory; `Grease\View\GreaseViewServiceProvider` seeds a greased `FileViewFinder`
  (`find()` = array hit ?? live `parent::find()`, in its own property so it survives `flush()`/Octane)
  and the compiled-path memo from it when fresh. Byte-identical by construction (each entry is what the
  live finder/compiler returned at build time, so view precedence is captured automatically); a name
  not in the index resolves live. The honest metric is the stat count — 20 views resolve with 0
  `file_exists()` calls vs 20 (Linux: 13.2µs → 1.0µs/request) — and the never-memoized miss is a
  permanent win even under Octane. Freshness-guarded against the compiled-view + config caches (a later
  `view:cache`/`config:cache`/`optimize` disables a stale index); inert in development. Opt in via the
  existing `Grease\View\GreaseViewServiceProvider` + `php artisan grease:view-cache` at deploy. Parity:
  `GreasedViewFinderTest` + `GreaseViewCacheTest`.

- **A greased router — cached middleware resolve+sort.** For the matched route,
  `Router::resolveMiddleware()` expands groups/aliases and sorts via `SortedMiddleware`
  (`class_implements()` + `class_parents()` per middleware) twice per request — uncached, on top
  of the raw name list the route already memoizes. `Grease\Routing\Router` caches the exact
  resolved+sorted array keyed by the literal `(gathered, excluded)` signature; order is preserved
  verbatim. Flushes on every map mutator (`aliasMiddleware`/`middlewareGroup`/`prepend`/`push`/
  `removeMiddlewareFromGroup`/`flushMiddlewareGroups`). Once-per-request work, so small in
  isolation, but pure waste removed every request — the lazy cache halves it, the full win lands
  under Octane (persistent router). Opt in with `Grease\Routing\Router::swap($app)` in
  `bootstrap/app.php` (the kernel takes the router by constructor injection, so a provider rebind
  is too late). No real caveat. Independent of `route:cache` (which caches URL matching + raw names,
  never the resolved list). Parity: `RouterMiddlewareParityTest` + `Boot{Vanilla,Greased}Test`.

- **`grease:route-cache` — an eager, opcache-interned middleware index.** A drop-in twin of
  `route:cache` that also precomputes every route's resolved+sorted middleware into a
  `signature => [classes]` constant `return [...]` file, opcache-interned into shared memory — so
  the greased router's cache starts **pre-seeded** and both the dispatch and terminate passes are
  hits from request one. This is the tier that wins **FPM** (where the lazy cache, on a per-request
  router, can't survive between requests), bringing FPM middleware resolution to ~Octane
  steady-state: measured **~−96% vs the lazy tier** (FPM-cold model). Keyed by the same signature
  as the lazy path, so it only ever serves an exact-match hit (dynamic-middleware routes miss →
  resolve live), live entries are never overwritten, and a map mutation flushes seeded entries.
  Added contract: build==runtime maps (rebuild on deploy; don't gate middleware registration on the
  environment) — the freshness guard disables a stale index after any `route:cache`/`route:clear`/
  `config:cache`/`optimize`, and it's inert in development. Opt in with the (non-auto-discovered)
  `Grease\Routing\GreaseRoutingServiceProvider`. Parity: `GreaseRoutingServiceProviderTest`.

- **A greased config repository — memoized `config()` reads.** `config('a.b.c')` is a
  per-request multiplier (a vanilla Laravel 13 request makes ~50 reads before your code runs;
  a real app makes hundreds-to-thousands), and vanilla re-walks the nested array on every call.
  `Grease\Config\Repository` memoizes the resolved value per key (`array_key_exists`, not `??=`,
  so a stored `null` stays cached; a sentinel distinguishes a stored `null` from an absent key
  in one walk; absent keys are never memoized). Measured **−65%** on a repeat-heavy read mix.
  Writes flush the memo; the one carve-out is out-of-band `$items` mutation (`flushConfigMemo()`
  is the hook). Octane-safe — Octane sandboxes config per request via `clone`, and the greased
  repo is byte-identical through that clone. Opt in with the (non-auto-discovered)
  `Grease\Config\GreaseConfigServiceProvider`. Measured on top of `config:cache`, which optimizes
  boot, not the read path. Parity: `ConfigRepositoryParityTest` + `GreaseConfigServiceProviderTest`.

- **`grease:config-cache` — an eager, opcache-interned flat config index.** A drop-in twin of
  `config:cache` that also emits a flat `'a.b.c' => value` leaf map as a constant `return [...]`
  file, which opcache interns into shared memory — so every leaf read is a hash hit with no
  dot-walk and **no warmup**, from the first call, on every request. A stable **~88% cut of
  config-read time vs vanilla** regardless of call volume; the absolute saving scales with it.
  This is the tier that wins the per-request-cold case the memo can't reach (every FPM request,
  every fresh Octane clone, where the memo resets), and it costs ~0 per-request memory (one copy
  in opcache SHM). The provider loads the index only when it's fresh relative to the config cache,
  so a later plain `config:cache` / `config:clear` transparently disables a stale index.

- **A persistent-worker (Octane) benchmark** (`benchmarks/octane.php`) — a cold-start-vs-warm
  harness over the cumulative-stack fixtures, surfacing the warmup tax a persistent worker
  amortizes. It also retired the container-blueprint precompile idea (measured ~0µs warmup tax —
  a credible negative) on the way to the config flat-index, which is where that lever actually pays.

## [0.4.1] - 2026-06-23

A correctness fix to the Blade view tier, and an honest re-measurement of its benchmarks.

### Fixed

- **`@props` now honors a value passed as `@include` data.** The greased `@props` emit
  bound props with a plain `$$key = $value`, unconditionally overwriting with the declared
  default — so a value reaching a sub-view via `@include('sub', ['propValue' => 1])` (which
  `extract()`s `$propValue` into scope *before* the block runs, rather than arriving in a
  `ComponentAttributeBag`) was discarded and the default rendered instead. Vanilla's emit is
  scope-aware in two ways the greased one had dropped, both now restored byte-identically:
  - props bind with `$$key = $$key ?? $candidate`, so an existing scope local wins over the
    default (the `@include` value), and
  - locals shadowed by a pass-through attribute are `unset()` (vanilla's `get_defined_vars()`
    cleanup) — described in the `Props` docstring but never actually emitted.

  `Props::mergeAttributes()` now returns the prop *candidates* (`passed-attribute ?? default`),
  the surviving attribute bag, and the surviving keys; the compiler finishes the resolution in
  the template frame. Regression coverage: a new `scopeScenarios` provider pre-seeds scope
  locals and A/Bs vanilla vs greased — reverting just the bind to the old form fails 3 of the 5.

### Changed

- **Blade benchmark numbers re-measured on Linux** (`benchmarks/docker`) against the corrected
  emit: **simple −28% / rich −23%** component renders (was −38% / −29.5% through 0.4.0 — those
  measured the byte-divergent emit above). The now-mandatory scope-cleanup loop is intrinsic to
  byte-identical parity and accounts for most of the difference on the cheapest components. The
  live docs JSON (`docs/.vitepress/data/benchmarks.json`) and README are refreshed; the
  model/event/foundation tiers are unchanged.

## [0.4.0] - 2026-06-23

Grease opens a **new axis** beyond the model and view: the per-request *foundation* — the
application container and the HTTP request. Two opt-in, byte-/behaviour-identical tiers
join the bundle, and a flagship cumulative-stack benchmark shows every tier compounding on
a real request through the kernel (JSON + Blade) — the full mixed page-load suite runs
**~−47%** end-to-end for **~+2%** retained memory. The parity suite grows to **400 tests /
1066 assertions**. Honest throughout: the foundation tiers' eye-catching per-op wins
(container −38.8%/resolve, request −41%/request) are *thin slices* of a full request, so
each moves an endpoint a few percent — compounding tiers, not standalone headlines.

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

[Unreleased]: https://github.com/One-Learning-Community/grease/compare/v0.5.1...HEAD
[0.5.1]: https://github.com/One-Learning-Community/grease/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/One-Learning-Community/grease/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/One-Learning-Community/grease/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/One-Learning-Community/grease/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/One-Learning-Community/grease/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/One-Learning-Community/grease/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/One-Learning-Community/grease/releases/tag/v0.1.0
