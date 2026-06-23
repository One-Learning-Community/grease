# Grease — project notes

Working log: where this came from, what's built, what's open, and the context that
lives in other repos. Companion to [CLAUDE.md](CLAUDE.md) (the quick orientation).

---

## Origin story (why this exists)

Eloquent re-derives class-pure facts on every attribute access and every hydrated
row — it rebuilds the casts array, re-walks a cast `switch`, re-probes
`method_exists` for mutators, re-resolves the connection's date format, and runs a
fresh `ReflectionClass` per `new Model`. None of it changes for the life of the
class.

These optimizations were proposed to Laravel core repeatedly and declined on
stability grounds — each "marginal in isolation":

- **#60550** — cast objects (memoized flyweight cast dispatch). "I prefer stability
  unless the performance benefits are immense for most applications." 14.x path also
  declined.
- **#55129** — `getDateFormat()` caching (10% on timestamp-heavy hydration). "We
  don't typically merge performance PRs without before/after benchmarks in real
  world scenarios."
- **#51184 / #51179** — Events Dispatcher optimization (20–54% on dispatch). "Not
  sure I want to take on the code changes for the fairly marginal improvement."

The pattern is structural, not a quality problem: a single optimization is always
"marginal for most apps" in isolation, and core's (defensible) fear is silent
breakage of a long tail it can't verify. **Opt-in dissolves the conflict** — the
risk moves to whoever adds the trait and reads one short caveat. Bundled, the wins
compound, and the compounding *is* the pitch.

Framing for a write-up: **"the flexibility tax — what Eloquent's hot path costs you
to preserve extension points you've never used."**

---

## What's built

A complete, installable package. Test suite is the safety contract; benchmarks are
both proof and regression guard.

### Source (`src/`)
- `ClosureCast.php` — flyweight cast object (closures defer to the model at call
  time; one shared instance per cast type).
- `Concerns/InteractsWithGreaseBlueprint.php` — the single per-class blueprint store
  + `flushGreaseBlueprint()` + `clearBootedModels()` hook.
- `Concerns/HasGreasedHydration.php` — Tier 1 (construct/hydration).
- `Concerns/HasGreasedAttributes.php` — Tier 2 (cast/date/mutator metadata memo +
  `mergeCasts` divergence guard).
- `Concerns/HasGreasedCasts.php` — Tier 3 (flyweight cast dispatch).
- `Concerns/HasGreasedSerialization.php` — Tier 4 (date serialization round-trip
  elimination across timestamps and `datetime`/`immutable_datetime` casts —
  probe-certified, byte-identical, defers otherwise).
- `Concerns/HasGrease.php` — umbrella (all four).
- `GreasedModel.php` — abstract base for `extends` users.
- `Events/Dispatcher.php` — **the events dispatcher tier** (port of laravel/framework
  #51184): a drop-in `Illuminate\Events\Dispatcher` subclass — no-listener fast path,
  cached `getListeners()`, pre-compiled wildcard patterns. Behaviour-identical, just
  faster. Not a model trait — a *different axis*: bind it as the `events` singleton.
  `::fromBase($existing)` migrates a live dispatcher's full state (listeners,
  wildcards, resolvers, deferral) for a transparent swap.
- `Events/GreaseEventServiceProvider.php` — opt-in binding (NOT auto-discovered):
  swaps `events` via `fromBase`, clears the `Event` facade's cached root, and points
  Eloquent's static dispatcher at the greased one.
- `Support/WildcardPattern.php` — pre-compiled wildcard regex (reproduces `Str::is`),
  used by the dispatcher so wildcard matching isn't recompiled per call.
- `View/*` — **the Blade render tier**, a *third axis* (like the dispatcher: not a model
  trait). Opt-in via `View/GreaseViewServiceProvider.php` (binds a faster `blade.compiler`;
  NOT auto-discovered). Two greased hot paths, both byte-identical:
  - `View/Compiler.php` + `View/Props.php` — the `@props` emit. Vanilla's per-render block
    (flat name list + `in_array`, declaration eval'd twice, `get_defined_vars()` scope
    snapshot) collapses to one `Props::mergeAttributes()` call (name set memoized per
    compile-time site) + a tight `$$key = $value` bind loop. `Compiler::fromBase()`
    reflection-clones the base compiler for a transparent swap.
  - `View/ComponentAttributeBag.php` — the `$attributes->merge([...])` nearly every
    component runs. Vanilla's Collection pipeline (`partition`/`mapWithKeys`/`->merge`/
    `->all`, ~5 Collection allocs/render) becomes two plain `foreach` loops. Subclasses the
    base bag (so every framework `instanceof ComponentAttributeBag` still holds — e.g.
    `sanitizeComponentAttribute`'s no-escape guard for forwarded bags); `Props` hands the
    component its surviving `$attributes` as this subclass and `merge()` returns
    `new static`, so the fast path stays live down any chain.

### Tests (`tests/`) — green on real Laravel
- `CastParityTest` — every cast type × read + type-identity + `toArray` + all-null;
  encrypted deferral; enum/custom-class.
- `CastEquivalenceParityTest` — the ported cast-objects ~40-case differential matrix
  (type-mismatched scalars, whitespace/reordered JSON, equal dates, enums from
  differently-typed scalars, custom-cast round-trips), asserted against vanilla AND
  the documented expectation. Applies per-case casts via `mergeCasts()`.
- `DirtyEquivalenceTest` — subtle dirty cases + genuine changes, vanilla vs greased.
- `RuntimeBehaviorTest` — `mergeCasts`/`withCasts` divergence guard; a diverged
  instance doesn't poison the class cache; STI cast isolation; accessor + appends;
  set-mutator/write; `flushGreaseBlueprint`.
- `SqlRoundtripTest` — real driver: migrate → insert → select/find/where, insert+
  reread, update, `fresh()`, eager-loaded relations.
- `EventsDispatcherParityTest` — events tier: A/B vs the stock dispatcher (same
  listeners, order, return values) across no-listener/direct/wildcard/interface/halt/
  false-break/forget/cache-invalidation, plus a 72-case `WildcardPattern` ≡ `Str::is`
  matrix. Pure unit tests (no DB).
- `DateSerializationParityTest` — Tier 4, both paths. Timestamps: UTC ISO fast path,
  non-UTC defer, storage-format `serializeDate` identity (any tz), custom `dateFormat`
  defer, sub-second / date-only / Carbon-instance / null per-value defers, zero-offset
  non-UTC zones, DST zones both hemispheres, no-timestamps model, and the tz-keyed
  plan staying correct across a runtime tz change. Casts: `datetime`/`immutable_datetime`
  fast path under UTC, non-UTC defer, custom-format (`datetime:Y-m-d`) and `date` casts
  always defer, Carbon-instance defer, and runtime `withCasts` divergence (added and
  overridden casts). White-box assertions prove the fast path is actually engaged.
- `Fixtures/` — `SampleData` (the shared raw row), `Vanilla*`/`Greased*` model
  pairs, `Status` enum, `UpperCast`, `DefinesSampleCasts` (cast map via the `casts()`
  method — a trait can't redeclare `Model::$casts`).
- `ViewPropsParityTest` — Blade `@props`: compiles each declaration both ways, executes
  the emitted PHP, asserts identical prop locals + surviving attributes (incl. the kebab-
  alias junk local); plus emit-shape and per-site memo-key assertions.
- `ComponentAttributeBagMergeParityTest` — Blade `merge()`: A/B vanilla-vs-greased
  `getAttributes()` + `__toString()` across 14 scenarios (class/style append, `Str::finish`
  `;`, `AppendableAttributeValue`, escaping, `escape=false`, ordering, de-dup), plus the
  `sanitizeComponentAttribute` forwarded-bag guard (a standalone reimpl would be e()'d).

### Benchmarks (`benchmarks/`)
- `Bench/Support/BootsEloquent.php` — shared Capsule boot for every bench, **with a
  real stock event dispatcher wired in** so model events actually fire (every
  hydrated row → `retrieved`, every save → 4 events). Zero listeners by design — the
  realistic "dispatcher present, nothing on the hot path" baseline.
- `realworld.php` — macro, Capsule + real queries, vanilla vs greased, drift-
  cancelled, reports per-request µs incl. SQL. Doubles as a parity gate.
- `Bench/CastBench.php` — phpbench in-memory A/B, paired `*Vanilla`/`*Greased`
  subjects over `SampleData`.
- `Bench/DateSerializationBench.php` — Tier 4 in isolation, two paired
  `*Vanilla`/`*Greased` subjects: a timestamps-only model
  (`SampleData::timestampsRow()`) and a `datetime`/`immutable_datetime`-cast model
  (`SampleData::datetimeCastRow()`). Pinned to UTC, with a parity guard that refuses
  to time a non-identical state. The harness counterpart to `DateSerializationParityTest`.
- `Bench/DispatcherBench.php` — events tier A/B (greased vs stock dispatcher), both
  seeded with wildcard listeners (the real-app shape): no-listener −53%, with-listeners −18%.
- `Bench/EventStormBench.php` — the events tier where it *matters*: a page-render-shaped
  storm of ~165 dispatches. Lean (warm, mostly no-listener) −56%; cold (fresh
  per-request dispatcher + non-trivial wildcards) −47%. The request-level answer the
  Eloquent macro can't show.
- `Bench/SuiteBench.php` + `Bench/Support/DrivesTestSuite.php` — phpbench-via-phpunit
  bridge: drives each no-arg `test*` of `SqlRoundtripTest` as a subject through a
  booted Testbench app (skips `tearDown` — it fatals under the phpbench runtime).
- `blade.php` — **the Blade macro**: Taylor's 1,000-anonymous-component challenge, two
  booted Testbench apps (vanilla vs greased compiler, separate compiled-view caches),
  HTML asserted byte-identical before timing, on a simple and a rich avatar. Doubles as
  the render-parity gate.
- `blade_excimer.php` — **the honest profiler**: a single-arm greased render under Excimer
  (sampling, JIT-on), writing a speedscope flamegraph + a self-time ranking. Trust this for
  self-time. Run with `-d xdebug.mode=off -d opcache.jit=tracing`.
- `blade_profile.php` + `cachegrind_top.php` — the Xdebug companions. Useful for **call
  counts** (how the `merge` lever was found, ~9 Collection allocs/render) but NOT self-time:
  Xdebug disables JIT and over-attributes internal-op cost to the calling frame (it ranked
  `extract` at ~14% when it's ~0.6%). Prefer `blade_excimer.php` for "where does time go."

### Infra
- `.github/workflows/tests.yml` — PHP 8.2–8.5 × Laravel 11/12/13 (matched Testbench),
  phpunit + a real-query parity smoke on every leg.
- `composer.json` scripts: `test`, `bench`, `bench:suite`.

### Measured results
**Measurement environment (read first).** All numbers below are now measured on **Linux
via `benchmarks/docker`** (php:8.4-cli + opcache + JIT, no Xdebug). macOS was distorting
them and is no longer trusted: its `/var→/private/var` symlink confuses opcache's realpath
keying and CLI opcache behaves unlike production. Concretely, Mac **inflated** the per-op
microbench wins (a fatter vanilla baseline) and **understated** Blade (the `is_file` it
ranked at ~8% of a render is ~3% on Linux — the whole isFile detour was a Mac artifact).
The Docker box is Linux/arm64, so absolute µs and CPU-bound `%`s still vary by host — the
harness is the source of truth, reproduce on your target. Earlier macOS figures are kept in
git history for contrast.

**A benchmark is a property of the build, not the code.** Measured the same CastBench across
libc/allocator variants on the same machine (`Dockerfile` glibc vs `Dockerfile.alpine` musl):
the deltas swung **~3–6 pt** on allocation-heavy ops (read −26.5%→−33%, toArray −46%→−52%
glibc→musl), because Grease's wins are allocation wins and musl's allocator makes the vanilla
arm pay more — so the *same optimization looks bigger on musl*. jemalloc via `LD_PRELOAD`
didn't even run (`munmap_chunk(): invalid pointer` — incompatible with PHP's JIT on this
build; "drop-in allocator" is a myth). And run-to-run under machine load swung glibc setDirty
−39%↔−27% — bigger than the libc difference. Lesson: quote a range and ship the harness; never
quote a single number as if it were portable.

- **Blade render tier** (`blade.php`, 1,000 anonymous components, parity ✔): @props+merge,
  **simple −38%, rich −29.5%** (vanilla 16.1/23.1 ms → 9.9/16.2 ms). (Mac read this as
  −24–27%.) The remaining cost is the compiled-view body (~60–70%, mostly real template
  work) — see Open/to-explore #10.
- **Real endpoints** (`realworld.php`, end-to-end incl. SQL, 3-run medians): index_users
  **−78%** (3.12 ms → 0.69 ms), posts_with_author **−77%** (6.0 → 1.4 ms), show_post **−47%**
  (113 → 60 µs), bulk_update **−18%** (7.25 → 5.9 ms). Endpoint `%`s held up well vs Mac
  (the ORM share dominates on `:memory:` SQLite either way).
- **Date serialization** (`DateSerializationBench`, UTC, rstdev ~1%): timestamps-only
  **21.5 µs → 2.9 µs = −87%**; datetime-casts-only **31.5 µs → 3.4 µs = −89%**. (Mac read
  −92% off a slower Carbon baseline.)
- **Per-op** (`CastBench`, rstdev ~1.5%, two runs): hydrate **−34%** (7.4 → 4.9 µs), read
  **−27%** (51 → 38 µs), set+dirty **−39%** (31 → 18.5 µs), toArray **−47%** (107 → 56 µs),
  enum read **−48%** (2.8 → 1.46 µs). All notably lower than the Mac figures (hydrate was
  claimed −61%) — the Mac baseline was inflated. Endpoint `%`s are higher than per-op
  because the greased event dispatcher + compounding ride on top.
- **Event dispatcher** (`DispatcherBench` / `EventStormBench`): no-listener **−53%**
  (0.40 → 0.19 µs), with-listeners **−18%** (0.73 → 0.60 µs); storm lean **−57%**, cold
  **−54%**. These matched Mac closely (dispatch isn't filesystem- or Carbon-bound).
- Caveat for honesty: the endpoint `%`s use `:memory:` SQLite (fastest possible DB), so the
  ORM is a larger share than against a networked DB. The portable figure is absolute time
  removed per request, which is DB-independent.

---

## Open / to explore

Roughly highest-leverage first.

1. **Events dispatcher tier (#51184).** ✅ **Harness now faithful** — the benches boot
   with a real stock dispatcher (`BootsEloquent`), so events fire. **Now measured**
   (the honest envelope, before building anything):
   - `retrieved` dispatch with **zero listeners ≈ 1.5 µs/row**; a `save()`'s ~4 events
     ≈ **7 µs/save** (~1.77 µs/event). Roughly **constant** w.r.t. the number of
     *unrelated* registered listeners (1.5→1.7 µs from 0→20 wildcards — Laravel's
     wildcard matching doesn't balloon). Only many `eloquent.*`-matching wildcards
     would change that.
   - Wiring it barely moved the macro (index_users −73.3% → −72.4%) because dispatch
     is cheap *and* vanilla + greased pay it equally (the dispatcher is a global
     singleton — a *different axis* than the per-model tiers).
   - **Tier upside** = letting a greased model **skip the dispatch when there's no
     listener for that specific event**, recovering ~1.5 µs/row on reads (~5% of a
     greased read request) + ~7 µs/save on writes. Real and it stacks, but **modest —
     not a date-tier headline.** Fits the portfolio thesis ("marginal in isolation,
     compounds bundled") exactly.
   - **Design + risk:** override `fireModelEvent` to short-circuit when no listener
     exists for `"eloquent.{$event}: ".static::class`. The parity bar here is
     *behavioral* (did the listener fire?), not byte-output — get it wrong and you
     silently drop a real event.
   - **Measured the obvious design and it doesn't work.** A *live*
     `$dispatcher->hasListeners(...)` gate is a **net loss**: it recovers almost none
     of the dispatch cost (read 1.69→1.49 µs, ~12%) and with a handful of registered
     `eloquent.*` wildcards it's **~2× *slower* than just dispatching** (read 1.46→3.40
     µs; save 7.0→14.0 µs). Reason: `dispatch()` caches the resolved (empty) listener
     set per event name, but `hasListeners()`/`hasWildcardListeners()` re-scans every
     wildcard pattern uncached on every call — asking "is anyone listening?" costs more
     than telling nobody.
   - **So a per-model skip is the wrong shape.** The winning shape is to optimize the
     *dispatcher itself* — which is exactly laravel/framework#51184.
   - ✅ **BUILT: the events dispatcher tier** (`Grease\Events\Dispatcher`, port of
     #51184). Three optimizations, all behaviour-identical (83 A/B parity tests):
     no-listener fast path off a cached presence check, cached `getListeners()`
     (`makeListener` once per event, not per dispatch), and pre-compiled
     `WildcardPattern`s (the fix for the uncached re-scan that sank the live check).
     Measured (rstdev ~1.3%): **no-listener dispatch −53%** (0.97→0.45 µs, *constant*
     regardless of registered wildcards — where stock and the per-model skip both
     degrade), **with-listeners −18%**. This is the "Grease is more than Eloquent"
     axis: opt in by binding it as the `events` singleton; it speeds up *every*
     dispatch (views, cache, model events), not just Eloquent.
   - **Macro: now full-stack A/B** — `realworld.php` runs the vanilla arm on the stock
     dispatcher and the greased arm on `Grease\Events\Dispatcher`. The dispatcher's
     incremental contribution there is **~1%** (index_users greased 2976→2944 µs):
     model events are zero-listener and dispatch (~0.3–0.5 µs/row) is dwarfed by the
     ORM work the model tiers already cut. **The Eloquent macro understates this tier
     on purpose** — its real value is *app-wide* event traffic (view rendering, cache,
     custom events), which an Eloquent benchmark doesn't touch. The truer number is
     `DispatcherBench` (−53% per no-listener dispatch).
   - ✅ **Event-heavy bench done** (`EventStormBench`): a page-render-shaped storm
     (~165 dispatches) is **−56%** lean/warm (the fast path) and **−47%** cold/per-request
     with non-trivial wildcards (the `WildcardPattern` win). Roughly halves a request's
     event overhead — the answer the Eloquent macro (~1%) structurally can't show.
     Verdict: **the tier is worth the opt-in.**
   - ✅ **Opt-in binding done** (`GreaseEventServiceProvider` + `Dispatcher::fromBase`):
     register the (non-auto-discovered) provider and it swaps `events`, carries over
     already-registered listeners, clears the `Event` facade's cached root, and points
     Eloquent's static dispatcher at the greased one. Covered by Testbench integration
     tests (swap lands in container/facade/Eloquent; pre-swap listeners migrate).
   - **Tier complete.** Remaining is optional polish: a `prefer-lowest` CI leg and a
     note in the README caveats about the behavioural (not byte) parity bar.
2. **Date-cast round-trip elimination.** ✅ **DONE for timestamps** — Tier 4
   (`HasGreasedSerialization`). The headline insight from building it: the *default*
   `serializeDate` (`toJSON`) does **not** produce the stored string — `2026-01-01
   00:00:00` → `2026-01-01T00:00:00.000000Z`, with real tz math under a non-UTC zone
   — so "the stored string already matches the format" is generally false and you
   can't blind-skip. The safe move is **probe-certified**: run the model's real
   `serializeDate(asDateTime($probe))` once per class and adopt a Carbon-free rewrite
   *only* when it's byte-equal (UTC-default ISO, or a storage-format `serializeDate`).
   Keyed by tz+connection so it can't go stale; per-value strict-shape guard so
   sub-second / date-only / Carbon values defer. **Also done:** the *date-cast* path
   (`published_at => 'datetime'`, `immutable_datetime`) in `addCastAttributesToArray`
   — certified keys are rewritten and handed to `parent::` on the skip-list, so every
   other cast is byte-for-byte vanilla. Worth ~27 µs per date column per row.
   **Still open (smaller):** `date` / `immutable_date` casts (startOfDay truncation +
   date-only stored values need a different shape guard/rewrite), and custom-format
   datetime casts (`datetime:Y-m-d` → arbitrary `->format()`, no cheap rewrite). And a
   non-UTC default-`serializeDate` app gets nothing today; an offset-aware certified
   rewrite is possible but DST makes a single-probe generalization unsafe — would need
   careful per-offset probing.
3. **Enum / custom-class / encrypted cast flyweights.** ✅ **Enum DONE**
   ([ENUM-CAST-TIER-RESEARCH.md](ENUM-CAST-TIER-RESEARCH.md)) — `HasGreasedCasts`
   now has an enum fast path that *delegates* the conversion to the framework's own
   `getEnumCastableAttributeValue()` (byte-identical, no probe) while skipping the
   redundant `parent::` re-walk (2nd `getCastType`, encrypted probe, 14-arm switch,
   `isEnumCastable`). Keyed by resolved type → divergence/STI-safe for free.
   Measured **−56% on an enum-column read** (4.9→2.1 µs; read + `toArray` paths).
   Dirty-tracking is a raw-scalar compare that never enters `castAttribute`, so it's
   untouched — which also side-steps the `isClassComparable`/`compareClassCastableAttribute`
   comparators (absent on L11/early-L12; never referenced). **Class-castable and
   encrypted PARKED:** class-castable reads are already object-cached (gain ≈
   first-read only, high parity surface) and encrypted reads are decryption-bound
   (dispatch shave is noise).
4. **Write / `fill` tier.** ⏸️ **PARKED** ([FILL-TIER-RESEARCH.md](FILL-TIER-RESEARCH.md)).
   `fill(N)` is O(N·F) (per-key `in_array` over fillable + a `preg_grep` per key for a
   real guarded list). A flipped lookup makes it O(N), and the divergence trap is
   solvable — but measured **−0.2% (~0.6 µs)** for the recommended fillable-list shape
   (inside noise), only **−5.2%** for the discouraged guarded-list shape, and `fill()`
   is write-path only (hydration bypasses it). 1–2 orders below the shipped read tiers.
   Build only as a completeness add or if a guarded-list-heavy write workload is targeted.
5. **Hidden/visible flip caching** ⏸️ **PARKED — do not build**
   ([HIDDEN-VISIBLE-TIER-RESEARCH.md](HIDDEN-VISIBLE-TIER-RESEARCH.md)). `getArrayableItems`
   rebuilds `array_flip(hidden/visible)` per `toArray()`, but at realistic sizes (hidden=2)
   the flip is ~130 ns and the recommended value-compare cache is **measurably slower than
   vanilla** (−15% to −23%); the whole pair is ~0.4% of a `toArray`. This revises the
   tentative "build if it clears noise" in [SERIALIZATION-TIER.md](SERIALIZATION-TIER.md) —
   it doesn't clear noise, it regresses.
6. **Flyweight alias dedup.** ✅ **DONE**
   ([ALIAS-DEDUP-TIER-RESEARCH.md](ALIAS-DEDUP-TIER-RESEARCH.md)) — synonym cast types
   (`real`/`float`/`double`, `integer`, `boolean`, `array`/`json:unicode`,
   `custom_datetime`, `immutable_custom_datetime`) fold onto one canonical flyweight
   key. Stateless flyweights + textually identical synonym closures → zero behavioural
   change; `decimal` correctly excluded (carries a precision parameter). Honest
   magnitude: tidiness, not speed (≤8 duplicate `ClosureCast`s, ~10 KB, once per
   process) — folded in opportunistically alongside the enum work.
7. **Persisted/precompiled blueprint** ⏸️ **PARKED**
   ([PERSISTED-BLUEPRINT-TIER-RESEARCH.md](PERSISTED-BLUEPRINT-TIER-RESEARCH.md)). A
   `model:cache`-style artifact for CLI cold-start, but the economics are inverted: of
   the ~470 µs/class build, ~466 µs is tz/connection-keyed Carbon date-probes that
   **can't** be safely persisted; the cleanly-persistable metadata builds in ~4.4 µs.
   The blueprint also holds closures (`var_export`/`serialize` fail outright). And it'd
   be the only footgun in the package that fails toward *wrong output* on staleness.
   Runtime-lazy stays the right default.
8. ✅ **`getCastType` memoization DONE** (not from the original list). `getCastType()`
   is undocumented internal plumbing and a pure function of `getCasts()[$key]` (already
   frozen per class by Tier 2) — yet it was re-walked live on every cast access. Now
   cached per key in the blueprint, riding the existing divergence flag (no new branch).
   Real subclass overrides shadow the trait method and stay live. Measured **~3–7 µs/row
   on read, ~4 µs on `toArray`, ~7 µs on `setDirty`** (it's on the hottest path — every
   cast access, plus the enum/custom-class deferral and dirty checks all call it);
   `hydrate` (which doesn't cast) is flat, the control.
9. **NOT worth it:** a per-class read-dispatch `plan[key]→kind` overriding
   `getAttribute`. `toArray` uses `addCastAttributesToArray`, not `getAttribute`, so
   it wouldn't help the serialization-heavy path — limited upside for real risk.
10. **Blade render tier (Taylor's 1,000-component challenge).** ✅ **Two clean wins
    shipped**, both byte-identical and macro-gated (`blade.php`):
    - ✅ **`@props` emit** (`Compiler` + `Props`): one memoized `mergeAttributes()` call +
      a tight bind loop, replacing the flat-name-list / double-eval / scope-snapshot block.
      −14%. The lesson: the win wasn't `in_array`→`isset` (~−4-5%), it was killing the
      *structural* multi-pass over attributes.
    - ✅ **`ComponentAttributeBag::merge()`** (greased subclass): Collection pipeline →
      two `foreach` loops, no allocations. Found by profiling — `merge` was the single
      biggest Collection source (~5 of ~9 allocs/render). Got the macro to **−25%**.
    - **⚠️ Measurement lesson — Xdebug's cachegrind self-times LIE.** `blade_profile.php` +
      `cachegrind_top.php` ranked the per-render `require`/`extract($__data)` closure at ~14%
      self. A micro-A/B proved real `extract` is **~0.6%** of a render — Xdebug overrides
      `zend_execute_ex` (so JIT is off) and over-attributes internal-op cost to the calling
      PHP frame. The CALL COUNTS were trustworthy (that's how `merge` was found); the
      self-time **percentages** were not. **Use `blade_excimer.php` (Excimer, sampling,
      JIT-on) for honest self-time.** Run benches with `-d xdebug.mode=off -d opcache.jit=tracing`.
    - **❌ extract→bind-loop in getRequire: DEAD.** Tested two loops vs `extract(EXTR_SKIP)`
      (pure-binding micro, JIT on): `extract` is a C builtin and ~2× faster than any userland
      loop for ~12 vars (`get_defined_vars` snapshot loop +86%, skip-list loop +114%). The
      realized full-render change was a +1.3% regression. `extract` is already optimal.
    - **❌ isFile memoization: DROPPED — it was chasing a Mac artifact.** Excimer-on-Mac
      ranked the per-render `is_file()` stat at ~8% (memoizing it measured a "clean ~6.5%").
      On **Linux it's ~3%** (`benchmarks/docker`): macOS's stat cache thrashes (same path,
      same process: 16 ns warm vs ~1.5 µs in-render), which the Linux VFS doesn't. So the
      lever was mostly a Mac measurement artifact. It was *also* the wrong thing on principle
      — caching `is_file` imposes a freshness assumption PHP/Laravel leave to the OS/FS
      (NFS-without-caching relies on the re-stat), and that `is_file` is load-bearing for
      `CompilerEngine`'s recompile-on-missing recovery. Doubly correct to drop.
    - **⚠️ Benching trap found the hard way:** a provider `boot()` that EAGER-resolves the
      Blade engine captures the compiled-view path *before* a bench sets `view.compiled`,
      breaking per-arm cache isolation and producing a bogus **−87%**. (Harmless in prod —
      config is set before providers boot — but it poisons the macro. Keep view-tier wiring
      in `register`/lazy, or set `view.compiled` before booting the provider in benches.)
    - **Honest standing numbers (Linux, `benchmarks/docker`, JIT on):** @props+merge is
      **simple −38% / rich −29.5%** (vanilla 16.1/23.1 ms → 9.9/16.2 ms). (Mac read this as
      −24% — it understated the win.) Render self splits ≈ compiled-view body ~60–70%
      (mostly real work + the `Str::of` chain), then `e()`, `merge`, `Component::resolve`,
      the Factory machinery; `is_file` ~3%. **The −33% goal is already met/exceeded by the
      shipped tier on Linux** — no filesystem hack needed.
    - **Still open — component resolution (~15%, the real remaining lever).**
      `AnonymousComponent::resolve` + the Factory run a per-render factory/resolver lookup.
      Lever: cache resolution per component name. Behaviour-identical bar; risk is shared-
      state bleed between components. This is the next thing to point Excimer at — measure
      first, parity-gate via the macro. **The compiled-view body (~70%) is mostly genuine
      work + user template content, not framework overhead we can grease.**
    - **✅ Phase 4 — broadened the inspection past the single-avatar micro.** Built a
      page-shaped fixture (`benchmarks/blade/views/page-app.blade.php` + `components.php`/
      `register.php`): a `<x-layout>` with named/slotted slots, `<x-card>`/`<x-stat>` class
      components, a nested `<x-avatar>`, `@include`, `@each`, and a `partials.nav` view
      composer. Added it as the third macro variant and profiled it on Linux (Excimer,
      `benchmarks/dump_compiled.php` prints the emitted boilerplate). On the realistic page
      the shipped @props+merge win **dilutes to ~−13%** (vanilla 47 ms) — the greasable
      fraction shrinks as genuine template work + per-component framework machinery grows.
    - **✅ `getCompiledPath` memoization shipped** (`Grease\View\Compiler`). It's a pure
      `hash('xxh128', 'v2'.path)` recomputed on EVERY view render (`CompilerEngine::get`),
      and a page is a tree of renders (every component/slot/`@include`/`@each` is one). The
      framework already memoizes its siblings (`Factory::normalizeName`→`normalizedNameCache`,
      `getEngineFromPath`→`pathEngineCache`) but **missed this one** — a clean oversight. Memo
      keyed by path; byte-identical. **App page −13.3%→−15.3% (+2pp)**, neutral on the micros
      (few distinct paths there). It scales *with* render count, so it helps realistic pages
      more — the opposite of the @props/merge dilution. Test: `test_compiled_path_matches_
      vanilla_and_is_memoized`.
    - **❌ `View::gatherData` Renderable scan: measured dead end** (`_gatherdata_ab.php`
      A/B, since removed). The `foreach … instanceof Renderable` is 46% of gatherData, and
      in the common case it's pure tax (only `View`/`Mailable` implement `Renderable`; slots
      are `Htmlable`, `errors` is `Stringable`). But it's *unskippable*: 54% is the
      unavoidable `array_merge`, and the scan cost is dominated by the per-render `$this->data`
      (un-memoizable), not the stable `getShared()` (3 keys). Memoizing "shared has no
      Renderable" recovers only −2.7% of gatherData ≈ 0.1% of a render — not worth a
      `Factory`+`View` subclass. Two tempting refactors are also parity-blocked: building the
      union from `$this->data` first flips key order (the `@props` path reads
      `get_defined_vars()` — order is load-bearing), and lazy/skip-rendering unused
      Renderables changes side effects (`@push`/`@section`) that affect *other* views' output.
    - **✅ Class-component `$attributes->merge()` greased via a one-line emit seed** (was
      ~8% inclusive vanilla Collection-merge on page-app). Class (and class-less, no-`@props`)
      components build their bag via `Component::newAttributeBag()` → a *vanilla*
      `ComponentAttributeBag`, so their template merge took the slow Collection path (Grease's
      greased bag previously reached only `@props` components, via `Props::mergeAttributes`).
      The trap: the opening boilerplate calls `$component->data()` *inside* `startComponent`,
      and `data()` lazily creates `$this->attributes` (`?: newAttributeBag()`) and returns it
      as the template's `attributes` — BEFORE `withAttributes` runs. `withAttributes` then only
      *mutates that same object in place*; you can't reclass it after. **The escape:** after
      `resolve()`/`withName()` the public `$attributes` is still null, so Grease overrides the
      static `compileClassComponentOpening` to emit one extra line —
      `$component->attributes ??= new \Grease\View\ComponentAttributeBag([])` — *before*
      `startComponent`. `data()`'s `?:` then adopts the greased bag, `withAttributes` populates
      it in place, and the template's `merge()` takes the Collection-free path. Byte-identical
      (greased bag overrides only `merge`, parity-proven; empty seed == vanilla's lazy
      `newAttributeBag()`; `??=` preserves a constructor-set bag; `@props` components no-op the
      seed since `Props` re-bags anyway). Reached via the `static::` call in `compileComponent`
      (late-binds to `Grease\View\Compiler`) — no ComponentTagCompiler/`@component` rewrite.
      Profile confirms the swap: vanilla `merge` (8% incl) + its `getArrayableItems`/partition
      tail **gone**, greased `merge` now ~3.3% incl. **App page −15.5% → −21.0%** (p50, Linux).
      Test: `test_class_component_opening_seeds_a_greased_attribute_bag`.
    - **✅ `@foreach` `$loop` bookkeeping greased — the biggest lever of the phase**
      (`Grease\View\Factory`, bound as the `view` singleton). Found by aiming Excimer at a
      `@foreach`-heavy page (`page-table`) instead of the component pages: the `$loop`
      machinery (`ManagesLoops`) is **~35% of a loop-heavy render** — `incrementLoopIndices`
      alone 25.6% self, because it `array_merge`s the 10-key loop-state array *every
      iteration*; `getLastLoop`/`addLoop` reach the stack top via `Arr::last` (closure-default
      overhead). The Grease factory overrides the three: `incrementLoopIndices` updates the
      state **in place by reference** (no merge), `getLastLoop`/`addLoop` use a direct index.
      Byte-identical — same loop-state shape, and crucially `getLastLoop` keeps the *fresh
      `(object)` cast every call* so a template stashing `$loop` across iterations still sees
      distinct snapshots (the micro proved reusing one object is both unsafe AND ~10% slower
      than the fresh cast — so there's no tension). Bound via `Factory::fromBase()`
      (reflection-clone of all state + **re-`share('__env', $new)`** so compiled views'
      `$__env->…` reach the greased methods). Did NOT fuse the two per-iteration calls via a
      `compileForeach` emit override — that would couple the compiled-view *cache* to the
      greased factory being present (poisons across config flips); the vanilla emit calls
      methods that exist on both factories, so a greased-compiled view still renders on a
      vanilla factory. Profile after: `incrementLoopIndices` 25.6%→6.1%, `Arr::last` gone,
      **+44% render throughput**; `getLastLoop` (the `(object)` cast) is now the byte-safe
      floor at ~21%. **Data-table macro −27.8%** (p50, Linux). Micro: `benchmarks/
      loop_microbench.php` (−40% of the machinery). Tests: `FactoryLoopParityTest` (countable
      / single / non-countable generator / nested-with-parent + `fromBase` `__env` re-point).
      **Owning the `view` Factory is now the foothold for further `ManagesLoops`/`Manages*`
      wins.** Standing Linux numbers: **simple −38.9% / rich −29.9% / app page −21.4% /
      data table −27.8%**.
    - **✅ `@yield`/`yieldContent` — three full-content `str_replace` → one
      `preg_replace_callback`** (`Grease\View\Factory`, the Factory foothold paying off).
      `@yield('content')` hands the *whole page body* to `yieldContent`, which vanilla scans
      THREE times (`@@parent`→marker, strip placeholder, marker→`@parent`) — **29% of a
      layout render's self-time** (`page-layout` fixture: `@extends`/`@section`/`@yield`/
      `@push`). The net is one substitution over three *non-overlapping* markers, and neither
      sequential nor single-pass re-scans its output (`@parent` matches none of the markers),
      so one `preg_replace_callback` over the alternation is byte-identical — proven against
      a verbatim three-pass oracle across plain/marker/adjacency/pathological inputs
      (`FactorySectionParityTest`, 14 cases). **Measurement lesson:** a `str_contains`
      short-circuit was a DEAD END (−0.6% micro) — `str_replace`-with-no-match doesn't
      allocate, so the cost is the *scan*, not the copy, and 3 `str_contains` still scan 3×.
      `strtr` (one pass, multi-key) is +47% — a trap (it checks every position against every
      key). Only `preg_replace_callback` wins, because PCRE's literal-alternation scan rips
      through the no-match common case: **−87% on the micro**, `yieldContent` 29%→11% self,
      **+21% layout throughput**, **page-layout macro −19.4%** (p50, Linux). The remaining
      11% is the one unavoidable scan — the byte-safe floor (going lower needs tracking
      marker-presence during section assembly: fragile, not worth it).
    - **Map of the rest (page-app tail, all measured, all blocked):** `Component::resolve`/
      `data`/`extractPublic*` are statics/methods on the *user's* component class — not
      reachable without a Grease base class (breaks the drop-in opt-in). `componentData` /
      `AnonymousComponent::data` are genuine `array_merge` assembly with no byte-identical
      shortcut. `normalizeName`/`getEngineFromPath`/`ignoredParameterNames`/
      `extractConstructorParameters` are already framework-memoized. **Net for the component
      path:** the clean reachable surface is largely exhausted; the remaining big slice (the
      per-component compiled boilerplate) lives behind the `@component`/`ComponentTagCompiler`
      emit — a focused, higher-risk session. **But owning the Factory reopened the field:**
      the other `Manages*` traits (Stacks/Translations) are still reachable for the same
      treatment — build the fixture that exercises them and point Excimer at it (the lesson
      that keeps paying: `page-table` surfaced `@foreach`, `page-layout` surfaced `@yield`).
    - **❌ ComponentTagCompiler per-instance boilerplate: investigated → measured DEAD END**
      (the deferred "higher-risk session" — closed without the rewrite). Dumped the emitted
      per-`<x-…>` scaffolding (`dump_compiled.php page-app`) and classified every statement.
      The expensive calls inside it — `X::resolve()`, `$component->data()`,
      `startComponent`/`renderComponent` — are the *already-recorded* off-limits dead ends
      (user-class statics / genuine `array_merge` assembly); the boilerplate only *looks*
      dominant in the compiled-body self-time because that frame **inlines** them, not because
      the `isset`/`instanceof`/`unset` opcodes are expensive. Of the pure scaffolding, the
      save/restore dance is **load-bearing and irreducible byte-identically**: the `isset`
      save-guards are required for composability (the view can be `@include`d into a scope
      that already has `$component`/`$attributes`), and the `unset` on restore is required for
      loop correctness (without it a stale `$__…Original{hash}` from a prior iteration would be
      wrongly restored when `$attributes` is unset that pass). The **one** genuinely removable
      redundancy is the `isset($attributes) && $attributes instanceof Bag` predicate evaluated
      *twice* per component (resolve()-arg + the `except` guard; `$attributes` is unchanged
      between them). Micro-A/B'd it (`benchmarks/_ab_boilerplate.php`, since removed — hoisted
      the double eval into one temp, parity-gated identical HTML, page-app/200 components,
      Linux `benchmarks/docker`, JIT on): hoisting is a **consistent ~0.7–1.0% REGRESSION**
      (6831→6883, 6884→6953, 6991→7040 µs/render across 3 interleaved pairs). Same shape as
      the `str_contains` and `extract→loop` dead ends — the op is too cheap for fewer-evals to
      pay, and the temp's ASSIGN+read costs more than the saved re-eval. **Verdict: no
      reachable win behind the ComponentTagCompiler emit; the full rewrite the deferral
      feared is not worth its risk.** The live lever remains the `Manages*` traits behind the
      owned Factory (Stacks/Translations) — build the heavy fixture and point Excimer at it.
    - **✅ `@push`/`@prepend` stack assembly (`ManagesStacks::stopPush`/`stopPrepend`)** — the
      `Manages*` lever, and it paid. Built a push-heavy fixture (`page-stacks`: a layout with
      `@stack`, a `@for` row loop each doing `@push('scripts')…@endpush` + `@prepend('head')…
      @endprepend`, plus a `@pushOnce`) — the cheap-bodied-loop regime that surfaces this the
      way `page-table` surfaced `@foreach`. Excimer on it (Linux, `benchmarks/docker`): vanilla
      `stopPush`/`stopPrepend` each wrap their pop in `tap(array_pop($this->pushStack),
      function ($last) { $this->extendPush($last, ob_get_clean()); })` — a **fresh bound
      `Closure` allocated per `@endpush`/`@endprepend`** for a return value the compiled emit
      (`$__env->stopPush();`) discards. The two stack `tap` closures were **6.88% + 6.10% ≈ 13%
      of self-time**. Override drops the `tap` for the inlined pop + `extendPush($last,
      ob_get_clean())` + same returned section name. Byte-identical (same exception/message,
      same pop order, same `extendPush`/`extendPrepend` call, same return). Profile after: the
      two closures **gone**; `Grease\View\Factory::stopPush`/`stopPrepend` now carry the inlined
      `extend*` work (7.7% / 8.6%, ~3–4pp net off each path). **page-stacks macro −17.7% p50**
      (parity ✔), zero regression on the other 7 variants. Tests: `FactoryStacksParityTest`
      (10 cases — push/prepend/mixed/renderCount-buckets/inline-content/nested-LIFO/pushOnce +
      both unbalanced-stop exceptions; oracle = the real vanilla `BaseFactory`). 8th macro
      variant. Factory now overrides: addLoop, incrementLoopIndices, getLastLoop, yieldContent,
      stopPush, stopPrepend.
    - **❌ `ManagesTranslations` (`@lang`/`@choice`): dead lever, not pursued.** `@choice`
      compiles straight to `app('translator')->choice(...)` (never touches the Factory), and
      `@lang`'s `startTranslation`/`renderTranslation` are just `ob_start()` + `translator->
      get(trim(ob_get_clean()), $replacements)` — the cost is the translator lookup, genuine
      i18n work that's off-limits the same way `e()`/`htmlspecialchars` is. No allocation or
      structural overhead to grease byte-identically.
    - **❌ `ManagesLayouts` (beyond `@yield`): nothing left.** Audited the trait while building
      the composite: `stopSection`/`appendSection` already use a direct `array_pop` (no `tap`
      closure, unlike `ManagesStacks` — so the Stacks win does NOT transfer here), `extendSection`
      is a genuine `@parent` `str_replace`, and `yieldContent` is the one already greased. Lean.
    - **✅/📊 `page-full` composite — the honest realistic ceiling + a fresh exhaustion check.**
      Built a standard full page (`page-full`: `@extends` a primary layout filling
      head/styles/scripts/footer/content, a `@parent` footer override, a 100-row `@foreach`
      table with real per-cell work, 3 class components) — every tier firing at once. **−9.3%
      p50** (parity ✔), *lower* than any single-axis variant — the regime insight, quantified:
      on a realistic page the greasable framework slice is small. Excimer (greased) self-time:
      **~53% the compiled template bodies** (3 view files: 39.7 + 7.3 + 5.8% — user markup),
      **~24% `e()`** (≈500 escaped cells, htmlspecialchars-bound), then everything else thin and
      **either already greased** (`getLastLoop` 4.8, `yieldContent` 2.8, `incrementLoopIndices`
      1.1, `merge`, `addLoop`) **or an established dead end** (`isFile`, `evaluatePath`/extract,
      `gatherData`, `Component::resolve`/`data`/`extractPublicMethods`, `componentData`). **No
      new reachable lever** — the composite confirms the strand compounds cleanly (zero
      regression) and the ceiling is genuine template work + escaping. 9th macro variant.
      **Net: the reachable Blade surface is exhausted** — 7 wins shipped, the rest measured-dead
      or genuine work.

11. **Eloquent `Model::resolveClassAttribute` per-instance tax (NEXT TIER CANDIDATE — strong).**
    Went looking for an eager-load *matching* tier (`Relation::match`/`buildDictionary`);
    measure-first killed it (`benchmarks/eager_excimer.php`, Excimer on docker, `GUser::
    with('posts')->get()`, 100 users × 20 posts): matching is **thin** — `getDictionaryKey`
    1.1%, `applyInverseRelationToCollection` 1.2%, `buildDictionary` not even in the top frames.
    A matching tier would be marginal. **But the same profile surfaced the real lever: `Model::
    resolveClassAttribute` at 37% self-time** — the single dominant frame on the eager/hydration
    path, *and this is with `HasGrease` already on*. What it is: resolves class-level PHP
    attributes (`#[Table]`/`#[Fillable]`/`#[Hidden]`/`#[Appends]`/`#[Connection]`/`#[Touches]`/
    `#[DateFormat]`/`#[Guarded]`/`#[Visible]`…, a newer L11/L12 feature). It *is* cached
    (`static::$classAttributes`) — but keyed by a **freshly-concatenated `$class.'@'.$attributeClass`
    string built on every call**, and it's called **~13× per model instance** from the per-instance
    `initialize*` trait booters (GuardsAttributes/HidesAttributes/HasAttributes/HasTimestamps/
    HasRelationships) + `getTable`/`getConnectionName`. At ~2,100 hydrated instances/`get()` ×
    ~13 = ~27k cache-key string allocations per request — the allocation tax Grease specializes
    in. **Greasing hypothesis (byte-identical, model-axis — fits HasGrease):** the resolved
    values are per-class constants; (a) cheapest: a Grease trait overrides `resolveClassAttribute`
    (a trait method overrides the inherited `Model` method) with a **concat-free nested cache**
    `[$class][$attributeClass]` to kill the per-call string alloc; (b) deeper: freeze the
    `initialize*`-derived state once per class in the blueprint and skip the calls entirely
    (bigger override surface). Measure-first: A/B (a) before (b) — don't trust the structural
    hunch (str_contains/strtr/predicate-hoist all looked right and lost). Parity bar: byte-
    identical hydrated model (same fillable/hidden/appends/table/casts). Harness: `eager_excimer.php`
    (kept, the reusable model/eager profiler — analogue of `blade_excimer.php`).
    - ✅ **SHIPPED: `HasGreasedClassAttributes`** (5th model tier, in `HasGrease`). The chain,
      and why measure-first earned its keep at every step:
      - Micro-A/B of the cache shape: concat key vs nested `[class][attr]` = **−39%/call**
        (49.4 → 30.1 ns) — the concat alloc *is* a real cost.
      - **First cut REGRESSED in situ** and the profile caught it: keyed into the 3-level
        blueprint (`[$class]['classAttributes'][$attr]`), traversed 3×/call, the extra level
        out-cost vanilla's concat — `resolveClassAttribute` only fell 37% → 33.7% self. The
        micro had been *unfaithful* (it modelled a flat 2-level static, not the 3-level
        blueprint). Lesson re-learned: make the micro match the real shape.
      - Fix: a flat **2-level carve-out static** `[$class][$attr]`, sub-array fetched once
        into a local → warm path is one class lookup + one `array_key_exists`, no alloc.
        Re-profile: 33.7% → 27.9% self (the rest is irreducible per-call frame overhead ×
        ~25k calls — only the deeper (b) freeze could touch it).
      - **Honest throughput (tier-isolated A/B, 4 tiers vs full HasGrease, parity OK):
        −13.3%/−13.5% on a 2,100-row eager `get()`** (~485 µs/get(), matching the micro's
        per-call × call-count estimate). Self-time % understated it; throughput is the truth.
      - Byte-identical incl. vanilla's **property-less cache-key collision quirk** (`#[Table]`
        resolved with vs without a property returns whichever was cached first) — reproduced
        and pinned by `HasGreasedClassAttributesParityTest` (battery / absent-null / parent-chain
        / the quirk both orderings / integration getters / carve-out memo). `realworld` held at
        −77.8% / −47% / −19.9% (zero regression). 291 tests / 735 assertions.
    - ✅ **SHIPPED: `HasGreasedInitializers`** (6th model tier, in `HasGrease`) — the deeper
      "option b" freeze, and it landed clean. The setup: after the cache above, the eager profile
      put `resolveClassAttribute` at **28.7% self (child) + 1.4% (parent)** — but now the bulk was
      call *frequency*, not per-call cost. Each warm instance still made ~6–8 calls from the four
      `initialize*` booters the prior tiers don't touch: `initializeGuardsAttributes` (`#[Fillable]`/
      `#[Unguarded]`/`#[Guarded]`), `initializeHidesAttributes` (`#[Hidden]`/`#[Visible]`),
      `initializeHasTimestamps` (`#[WithoutTimestamps]`/`#[Table]` timestamps),
      `initializeHasRelationships` (`#[Touches]`). (`initializeModelAttributes`/
      `initializeHasAttributes` — the other two — were *already* frozen by `HasGreasedHydration`,
      and `getTable` reads `$this->table` directly in this framework version, so these four were
      the entire remaining warm-path surface.)
      - The fix is the proven freeze pattern (same as `HasGreasedHydration`'s two booters): a trait
        method overrides each inherited framework booter (different inheritance levels → no
        `insteadof`; the `initialize<Trait>` *name* is what `bootTraits()` registers, so the
        override dispatches), cold path runs `parent::` once and snapshots the resulting properties
        into the blueprint, warm path applies by copy. Snapshots: `[fillable, guarded]` /
        `[hidden, visible]` / `[timestamps]` / `[touches]`.
      - **The feared trap didn't apply.** The session brief warned `initializeGuardsAttributes`
        calls `static::unguard()` (a static mutation a snapshot-and-skip might drop). The *installed*
        framework (the one CI runs) sets `$this->guarded = []` instead — a pure property write. Every
        one of the four booters writes only `$this->{property}`; none touch static/global state. So
        the snapshot captures everything, and no divergence guard is needed (unlike `getCasts`, these
        properties are read straight off the instance — `getFillable()` etc. — never re-derived from
        a per-class cache a runtime `mergeFillable()` could leave stale). The snapshot captures each
        booter's *post-merge* result, so the warm copy is an overwrite, never a double-merge.
      - **Result:** `resolveClassAttribute` drops out of the eager profile's top 28 frames entirely
        (28.7% → <1%; `totallyGuarded` is the newly-revealed #1 — a future lever). Tier-isolated
        throughput A/B (prior-5 vs full-6, parity-gated, mean of 3 interleaved repeats): **−8.4% on
        a 2,000-child eager `get()`**, on top of the −13.3% from the cache. `realworld` *improved*:
        index_users −77.8% → **−79.1%**, show_post −47% → **−50.8%**, bulk_update held −20.0% (zero
        regression, byte-identical parity probe green). Pinned by `HasGreasedInitializersParityTest`
        (plain / every-attribute / `#[Unguarded]` / warm==cold / STI no-shared-snapshot / runtime
        mutation after init / unguard static orthogonality / user-trait initializer coexistence) —
        oracle = vanilla getters. 299 tests / 751 assertions. Profiler: `benchmarks/eager_excimer.php`;
        A/B harness: `benchmarks/initializers_ab.php`.
    - ✅ **SHIPPED follow-on: empty-fill short-circuit** (in `HasGreasedHydration`). Freezing the
      booters made `totallyGuarded` the eager profile's new #1 at **~27.6% self** — and the profile
      handed us *why*: it's only called from `fill()`, and `__construct` runs `$this->fill([])` on
      every `new` model (so every hydrated row, via `newFromBuilder`'s `new static`). `fill([])` is
      provably a no-op — `fillableFromArray([])` is `[]`, the loop is empty, the discard guard is
      `count([]) !== count([])` (false) — yet vanilla still computes `totallyGuarded()` and
      `fillableFromArray([])` up front. `if ($attributes === []) return $this;` deletes that work
      byte-identically (a class-level `fill()` override shadows it; non-empty fills defer to
      `parent::`). **`totallyGuarded` drops out of the top frames entirely** (`enum_value` is the
      next revealed #1); tier-isolated A/B (`benchmarks/fill_ab.php`, vanilla-fill vs short-circuit,
      parity-gated, mean of 3): **−5.2%** on the 2,000-child eager `get()`. `realworld` held
      (index_users −79.0%, parity green). Pinned by `HasGreasedFillParityTest` (empty no-op returns
      `$this` / empty under `preventSilentlyDiscardingAttributes` doesn't throw / non-empty respects
      fillable / totally-guarded non-empty still throws). 303 tests / 759 assertions.
    - **Dead end (recorded — a measurement trap):** with `totallyGuarded` gone, the `jit=tracing`
      profile shows `Illuminate\Support\enum_value` rocket to #1 at ~32% self. **It's a JIT
      misattribution artifact, not a real lever.** `enum_value` is a two-`instanceof` `match()` leaf;
      it can't be 32% self. Re-profiling with `opcache.jit=off` makes it vanish from the top frames
      entirely — its caller `getConnectionName()` → `enum_value($this->connection)` (run once per row
      by the greased `newFromBuilder`) gets inlined under tracing JIT, and the inlined caller's
      samples land on the tiny callee. **Lesson:** when a trivial leaf dominates a `jit=tracing`
      self-time profile with *no caller frame visible*, cross-check with `jit=off` (call counts stay
      reliable; self-times don't) before believing it. The `totallyGuarded` win above was real
      because its work (the up-front compute in `fill([])`) was genuine and survived the cross-check;
      `enum_value` does not.
    - **Truthful remaining frames (jit=off):** #1 is the PDO fetch closure (`Connection.php(412)`,
      ~17% — irreducible SQL/driver work); then `initializeTraits`' dynamic-dispatch loop (~6% self —
      the booters' *bodies* are already frozen, this is the per-`$this->{$method}()` call overhead,
      only reducible by the wholesale `initializeTraits` override the brief flagged as higher-risk);
      then the per-row `retrieved` model event (`Dispatcher::dispatch`/`getListeners`/`shouldDeferEvent`
      ~14% incl — a *different axis*, already targeted by `Grease\Events\Dispatcher`, not the stock
      dispatcher the bench wires in). No clean, low-risk model-tier lever stands out next; the eager
      hydrate path is largely down to SQL + event dispatch + irreducible construction.
    - ⚠️ **Honesty correction — the `eager_excimer.php` proxy is NOT representative of the realworld
      endpoints.** Everything above profiled the synthetic `GUser::with('posts')->get()` loop, whose
      models carry a *single integer cast*. Profiling the actual `realworld.php` endpoints
      (`benchmarks/realworld_excimer.php`, same rich-cast models — boolean/decimal:2/array/datetime,
      same seed, greased dispatcher, jit=off) shows they live on **different frames** than the proxy:
      - `index_users` / `posts_with_author`: dominated by `addCastAttributesToArray` (~9–10% self,
        **~46% incl** — the `toArray()` cast path for the non-date casts, which `HasGreasedSerialization`
        correctly defers to `parent::`), the cast-type probes (`isEnumCastable`/`isClassCastable`/
        `isClassSerializable`), `Brick\Math\BigNumber` (the `decimal:2` cast), and a **~13% cumulative**
        chunk in the `withoutRecursion` recursion-guard machinery (`Onceable::hashFromTrace` +
        `getRecursiveCallStack` + `clearRecursiveCallValue` + `getRecursionCache`). The hydration path
        my two session wins target is a *smaller slice* here than the proxy implied.
      - `show_post` (single `find()`): SQL-bound — `Connection::run` ~19% incl, `compileComponents`
        ~12% incl, PDO fetch ~11% self. Hydration is noise at one row.
      - `bulk_update` (write path): `getDirty` ~58% incl / `originalIsEquivalent` ~55% incl, under which
        `Carbon::rawCreateFromFormat`/`asDateTime` (~30% incl) **re-parse date columns during dirty-
        checking**. None of the read-path tiers touch this.
      - **Consequence:** the initializer-freeze (−8.4%) and fill short-circuit (−5.2%) are real wins on
        a *hydration-heavy* workload, but on these specific endpoints they're diluted — which is the
        honest reason the macro moved only −77.8%→−79.5% (index_users) etc., not by the per-op delta.
      - **New levers the proxy hid:** (1) ✅ **SHIPPED** — the per-key cast-classification probes (see
        next entry); (2) the Carbon re-parse in `originalIsEquivalent`/`getDirty` on the write path
        (`bulk_update`) — date *input* comparison, distinct from the date *output* the serialization tier
        already greases; (3) `getCasts` still ~6% self on index_users *despite* being memoized — pure
        call frequency from the per-attribute cast loop; (4) the `withoutRecursion`/`Onceable`
        recursion-guard overhead on `toArray()` (~13% on index_users, now relatively more prominent) — a
        per-model guard Laravel runs on every serialize. (2)/(3)/(4) unvalidated; measure-first each.
    - ✅ **SHIPPED: `HasGreasedCastProbes`** (7th model tier, in `HasGrease`) — the lever the
      profile-the-real-endpoints exercise surfaced, which the hydration-shaped `eager_excimer` proxy had
      hidden entirely. Vanilla's `addCastAttributesToArray()` classifies every cast key on every row via
      `isEnumCastable()`/`isClassCastable()`/`isClassSerializable()` (and the third re-runs the first
      two) — **~10% cumulative self** on the `index_users`/`posts_with_author` profile (rich casts:
      boolean/decimal:2/array/datetime). The verdict is a pure function of `getCasts()[$key]`, class-pure
      exactly like `getCastType` — so memoize it per `[class][probe][key]`. Two correctness points: (a)
      **`array_key_exists`, not `??=`** — the common verdict is `false`, which `??=` would treat as unset
      and re-probe every row (the same null-memo trap as `HasGreasedClassAttributes`); (b) reuse the
      `greaseCastsDiverged` flag — a runtime `mergeCasts()`/`withCasts()` defers to live `parent::`, so a
      key whose cast type changed is never answered stale (this couples the tier to `HasGreasedAttributes`,
      always paired in `HasGrease`). Because vanilla calls the probes through `$this->`, the overrides are
      hit even from *inside* the `parent::addCastAttributesToArray()` loop `HasGreasedSerialization`
      delegates to — the win lands with no array-builder reimplementation. **Measured:** the three probe
      frames (~10% self) collapse to one memoized `greaseCastProbe` (~4.7%); tier-isolated A/B
      (`benchmarks/castprobes_ab.php`, prior-6 vs full-7, parity-gated, mean of 3): **−10.2%** on a
      rich-cast `get()->toArray()`. Unlike the hydration wins this one moves the macro — **index_users
      −79.5% → −81.2%, posts_with_author → −80.9%** (it targets the endpoints' dominant frame). Pinned by
      `HasGreasedCastProbesParityTest` (oracle = vanilla probes across every cast kind / cached-false-is-a-
      real-hit / runtime divergence reflected / full toArray byte-identical via json_encode). 308 tests /
      845 assertions.
    - ✅ **SHIPPED: relation-less `toArray()` recursion-guard short-circuit** (in `HasGreasedSerialization`)
      — lever (4) above, and the biggest macro mover of the whole arc. Vanilla wraps *every* `toArray()`
      in `withoutRecursion()`, which runs a `debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)` + an
      `Onceable` trace-hash + `WeakMap` churn — a guard whose only job is to stop a *circular relation*
      from recursing forever in `relationsToArray()`. With `$this->relations === []` there is nothing to
      recurse into: `relationsToArray()` is `[]`, so `toArray()` is exactly `attributesToArray()` and the
      entire guard (a per-call `debug_backtrace` — ~13% self of the serialize profile) is dead weight.
      Override: `if ($this->relations === []) return $this->attributesToArray();` else `parent::toArray()`.
      - **Byte-identical, incl. the scary case.** Traced the guard semantics: when relations is `[]` the
        only cycle the guard actually *terminates* — a circular relation, where the nested re-entry hits
        `relationsToArray()` with the default (attributes-only) and stops — cannot arise. A pathological
        self-referential *accessor* (an append calling `$this->toArray()`) infinite-loops in vanilla too
        (the guard's default re-triggers it), so there's no input where vanilla terminates and the
        short-circuit doesn't — the outputs match exactly. Any loaded relation defers to `parent::`, so
        the guard stays fully intact where a real cycle is possible.
      - **Measured:** the `withoutRecursion`/`hashFromTrace`/`getRecursiveCallStack`/`clearRecursiveCallValue`/
        `getRecursionCache` frames all drop out of the profile (profiler loop 7,012× → 9,209×). Tier-isolated
        A/B (`benchmarks/toarray_recursion_ab.php`, vanilla-toArray vs short-circuit, parity-gated, mean of
        3): **−27.2%** on a relation-less rich-cast `get()->toArray()`. Macro: **index_users −81.2% → −86.3%,
        posts_with_author −80.9% → −83.7%** (nested relation-less models short-circuit even under `with()`),
        show_post −53.6% → −55.7%, bulk_update held (~−19.6%, no toArray on its hot path). Pinned by
        `HasGreasedToArrayRecursionParityTest` (relation-less / appends / loaded-relation deferral /
        self-circular + mutually-circular guard still terminates). 313 tests / 853 assertions.
      - **The irony worth recording:** core's circular-recursion guard pays a full `debug_backtrace` on
        every `toArray()` to memoize "am I already running on this object?" — the most expensive way
        imaginable to answer a question that's `false` for ~100% of real calls (no model is mid-serialize
        when you serialize it). Exactly Grease's thesis: a dev-ergonomics-first guard, never tuned for the
        hot path where it never fires.

## Shipping checklist
- [ ] Push remote `onelearningcommunity/grease`; confirm the CI matrix goes green
      (the README badge lights up on first run).
- [ ] Verify the version-floor `exclude:`s in `tests.yml` against reality
      (L11+PHP8.5, L13+PHP8.2, and watch L12+PHP8.5) — they're educated guesses.
- [ ] Optional: add a `prefer-lowest` dependency axis to CI (catches under-specified
      version constraints).
- [ ] `CHANGELOG.md` + `v0.1.0` release notes; submit to Packagist.

---

## Cross-repo context

The package is self-contained, but the deep-dive and spike that birthed it live in
the **framework repo** (`../../framework`, i.e. laravel/framework fork):

- `ELOQUENT-PERF-BLUEPRINT.md` (repo root, untracked) — the full ranked bottleneck
  inventory with `file:line` refs, from a 6-agent source analysis. Read this before
  building a new tier.
- `tests/Benchmarks/{spike-tier1,grease-traits,realworld}.php` (untracked) — the
  throwaway spikes; `grease-traits.php` is the pre-package version of the traits.
- Branches `feature/cast-objects` (the rejected 13.x PR #60550) and
  `feature/cast-objects-14x` (the narrowed variant) — source for the cast tier.
- `tests/Database/DatabaseEloquentCastEquivalenceTest.php` — the original of the
  equivalence matrix ported here.

## Design decisions worth remembering
- **Method override, not inline branching.** The rejected core-patch put
  `if ($cache) …` atop every helper → 7–12% tax on *everyone*. Overriding means
  users get the cached path and non-users pay nothing. Opt-in is the perf mechanism,
  not just distribution.
- **One blueprint static, atomic invalidation.** A prior bug was forgetting to clear
  one of several caches → stale *partial* state. One keyed store fixes that.
- **Parity is the spine.** The 89-test suite + benchmark probes assert byte-identical
  vs vanilla. That's what lets someone drop it into a 200-model app without auditing.
