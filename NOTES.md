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
- Blade render tier (`blade.php`, 1,000 anonymous components, parity ✔, Xdebug-inflated
  but A/B): with `@props` only, simple −14.2% / rich −14.5%; **adding the greased
  `merge()`: simple −26.6%, rich −24.5%.** ~half the per-render cost is still vanilla
  (require/extract machinery + component resolution) — see Open/to-explore #10.
- Real endpoints (end-to-end incl. SQL, vanilla → greased), **with Tier 4 (timestamps
  + datetime casts)**: posts_with_author −74.9%, index_users −73.3%, show_post −46.8%,
  bulk_update −19.1%. (Pre-Tier-4: −16.4% / −16.9% / −10.0% / −14.4%. The two read
  endpoints carry a User `email_verified_at` and Post `published_at` datetime cast on
  top of the timestamps — each another per-row Carbon round-trip the cast path now
  eliminates.)
- Tier 4 isolated (`DateSerializationBench`, `attributesToArray`, UTC, rstdev <2%):
  timestamps-only **59.9 µs → 5.1 µs = −92%** (~55 µs/model, two timestamps);
  datetime-casts-only **81.8 µs → 6.6 µs = −92%** (~75 µs/model, two casts). Each
  Carbon parse-and-reformat ≈ 27 µs.
- Per-op (CastBench, rstdev ~1%): hydrate −61%, set+dirty −41%, read −36%,
  toArray **−53%** (350.9 µs → 164.1 µs; was −20% pre-Tier-4 — the mixed fixture's
  timestamps and its `datetime`/`immutable_datetime` casts are all fast-pathed now;
  its `date` and custom-format date columns still defer — see below).
- Caveat for honesty: the macro `%`s use `:memory:` SQLite (fastest possible DB),
  so the ORM is a larger share than against a networked DB — Tier 4's high `%`s are
  most inflated by this. But the absolute µs saved per request (~27 µs × date columns
  × rows) is DB-independent.

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
    - **❌ isFile memoization: PARKED (modest + filesystem-risky).** Excimer flagged the
      per-render `is_file()` existence stat at ~8%; memoizing positives on the view engine's
      Filesystem (scoped, flushed on `terminating`) measured a clean **~6.5%** (isolation:
      17.1 vs 18.5 ms). Dropped anyway: (a) caching `is_file` imposes a freshness assumption
      PHP/Laravel deliberately leave to the OS/FS — NFS-without-caching deployments rely on
      the re-stat; (b) that `is_file` is load-bearing for `CompilerEngine`'s recompile-on-
      missing recovery. Not a clean-enough win for a zero-surprises package.
    - **⚠️ Benching trap found the hard way:** a provider `boot()` that EAGER-resolves the
      Blade engine captures the compiled-view path *before* a bench sets `view.compiled`,
      breaking per-arm cache isolation and producing a bogus **−87%**. (Harmless in prod —
      config is set before providers boot — but it poisons the macro. Keep view-tier wiring
      in `register`/lazy, or set `view.compiled` before booting the provider in benches.)
    - **Honest standing numbers (Excimer/clean, JIT on):** vanilla ~24 ms, greased compiler
      (@props+merge) ~18.5 ms = **−24%** (confirms the shipped figure). Render self splits ≈
      compiled-view body ~70% (mostly real work + the `Str::of` chain), then `e()`, `merge`,
      `Component::resolve`, the Factory machinery.
    - **Still open — component resolution (~15%, the real remaining lever).**
      `AnonymousComponent::resolve` + the Factory run a per-render factory/resolver lookup.
      Lever: cache resolution per component name. Behaviour-identical bar; risk is shared-
      state bleed between components. This is the next thing to point Excimer at — measure
      first, parity-gate via the macro. **The compiled-view body (~70%) is mostly genuine
      work + user template content, not framework overhead we can grease.**

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
