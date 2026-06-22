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

### Tests (`tests/`) — 198 tests / 522 assertions, green on real Laravel
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

### Infra
- `.github/workflows/tests.yml` — PHP 8.2–8.5 × Laravel 11/12/13 (matched Testbench),
  phpunit + a real-query parity smoke on every leg.
- `composer.json` scripts: `test`, `bench`, `bench:suite`.

### Measured results
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
3. **Enum / custom-class / encrypted cast flyweights.** Currently deferred to
   `parent::castAttribute` (correct, unaccelerated). The 14.x design has these
   flyweights, but their comparators call helpers (`isClassComparable`,
   `compareClassCastableAttribute`) that may not exist on vanilla Eloquent — port
   carefully and self-contained.
4. **Write / `fill` tier.** `fill(N)` is O(N·F): per-key `in_array` over fillable +
   a `preg_grep` regex compile per key when guarded is a real list. A flipped
   fillable lookup makes it O(N) — but prior art removed a *naive* fillable cache as
   unsound (runtime `fillable()`/`guard()`/`$unguarded`), so it needs divergence
   detection.
5. **Hidden/visible flip caching** (would extend the existing Tier 4
   `HasGreasedSerialization`). `getArrayableItems` rebuilds `array_flip(hidden/visible)`
   ×3 per `toArray()`. Only helps models with hidden/visible set — bench fixtures
   have none, so add fixtures first or it shows nothing. Full design + parity plan
   in [SERIALIZATION-TIER.md](SERIALIZATION-TIER.md) (the value-compare approach, to
   sidestep the `#[Hidden]`-attribute divergence trap). Much smaller than the date
   win — likely a completeness add, not a headline.
6. **Flyweight alias dedup.** Grease keys flyweights by raw cast type, so
   `real`/`float`/`double` build 3 instances; 14.x collapsed them to 1 via a
   canonical key. Minor memory, not correctness. Cheap to add if desired.
7. **Persisted/precompiled blueprint** (a `model:cache`-style artisan flow) for
   CLI cold-start. The current runtime-lazy cache is the right default (Octane-safe,
   no staleness footgun); this is an optional add-on.
8. **NOT worth it:** a per-class read-dispatch `plan[key]→kind` overriding
   `getAttribute`. `toArray` uses `addCastAttributesToArray`, not `getAttribute`, so
   it wouldn't help the serialization-heavy path — limited upside for real risk.

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
