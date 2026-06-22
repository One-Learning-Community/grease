# Persisted/precompiled blueprint — research (NOTES.md open item #7)

Verdict up front: **PARK.** Measured, the lazy build it would replace is **~470 µs
per model class, once per process** — and **~466 µs of that is the Carbon date-probe
work that a persisted artifact can't safely carry** (it's tz+connection-keyed and
holds closures). The genuinely persistable part — the hydration/cast-metadata slots —
costs **~4.4 µs to build lazily**. So a `model:cache` artifact saves single-digit
microseconds per class on the only workload that benefits (short-lived CLI), while
adding the one risk Grease exists to never take: a **stale cached blueprint after a
model edit is a byte-divergence — the cardinal sin** — and Laravel's own cache
precedent offers **no mtime safety net** to catch it. The cost/benefit is upside-down.

All line refs are against the framework fork at `../../framework` as read on 2026-06-21.
All numbers are from a throwaway `hrtime` micro-bench (now deleted) booting the bench
Capsule and `GreasedSample` (the mixed fixture: 21 casts incl. two timestamps + two
datetime casts), PHP 8.x, `:memory:`, UTC.

---

## What the artifact would actually contain

The whole tier is "persist `static::$greaseBlueprint[$class]` so the first process to
touch the class doesn't rebuild it." So the first question is *what is in there.* After
a full warm-up (hydrate + `toArray()`) of `GreasedSample`, the slots are:

| slot | built by | shape | persistable? |
|---|---|---|---|
| `model` | `HasGreasedHydration::initializeModelAttributes` (`:27`) | `[table, connection, primaryKey, keyType, incrementing]` — scalars | yes (var_export) |
| `castsInit` | `initializeHasAttributes` (`:45`) | `[casts[], dateFormat, appends[]]` | yes |
| `casts` | `HasGreasedAttributes::getCasts` (`:36`) | `array<string,string>` | yes |
| `dates` | `getDates` (`:41`) | `string[]` | yes |
| `getMutators` / `setMutators` | `hasGet/SetMutator` (`:46,51`) | `array<string,bool>` (lazy, per-key) | yes |
| `dateSerialize[$tz][$conn]` | `HasGreasedSerialization::greaseBuildDateSerializePlan` (`:155`) | `'identity'\|'utc_iso'\|false` | yes, **but tz/conn-keyed** |
| `dateCast[$tz][$conn][$type]` | `greaseBuildDateCastRewrite` (`:197`) | **`Closure`** \| `false` | **NO — closure** |

Plus two statics that live **outside** the per-class blueprint by design
(`InteractsWithGreaseBlueprint` docblock; `HasGreasedAttributes.php:25`,
`HasGreasedCasts.php:32`):

- `$greaseCasters` — 19 `ClosureCast` flyweight objects, **global, keyed by cast type**,
  built from a fixed `match()` (`greaseBuildCaster`, `HasGreasedCasts.php:57`). Not
  per-class, not serializable, and **trivially rebuilt** — no reason to persist.
- `$greaseDateFormatByConnection` — `array<string,string>`, connection-keyed, serializable.

**Serializability probe (measured):** `serialize($blueprint)` **throws
`Serialization of 'Closure' is not allowed`**. `array_walk_recursive` finds exactly
**2 closures** — the two `dateCast` rewrites. `var_export()` (Laravel's actual cache
mechanism) would emit `\Closure::__set_state(...)`, which is a fatal on load. So the
artifact **cannot hold the blueprint as-is.** It can hold everything *except* `dateCast`.

The `dateCast` closures are, however, one of exactly **two canonical forms** (the
`$candidates` in `greaseBuildDateCastRewrite:217` — the UTC-ISO rewrite or identity).
So they *could* be persisted as a discriminator tag (`'utc_iso'|'identity'`) and rebuilt
into closures on load — the same tag the `dateSerialize` slot already stores. Doable.
But see the magnitude section: this is the slot that's tz/conn-keyed and the slot whose
*build* is the only thing that costs anything — and persisting it is exactly where the
staleness risk concentrates.

## Cold-start measurement (the thing that decides this)

The tier's entire value is "skip the lazy build on first hit." So I measured that build.

```
autoload + capsule boot          : ~14,400 µs
first-ever cold hydrate+toArray  : ~28,500 µs   (one-time Carbon/class autoload — paid regardless)
warm hydrate+toArray (avg, N=2k) :    ~183 µs
isolated blueprint build         :    ~470 µs   (median, M=500 flush/rebuild deltas, classes warm)
   └─ hydration/cast-meta slots  :     ~4.4 µs  (model, castsInit, casts, dates)
   └─ date-probe slots           :    ~466 µs  (dateSerialize + dateCast: 5 probes each, Carbon round-trips)
blueprint build as % of boot     :     ~3.3 %
```

Three things fall out of this:

1. **99% of the build cost is the Carbon date-probes.** `greaseBuildDateSerializePlan`
   runs `serializeDate(asDateTime($probe))` over 5 probe strings; `greaseBuildDateCastRewrite`
   runs up to 2 candidates × 5 probes × 2 cast types. That's ~25–30 Carbon parse/format
   round-trips at ~27 µs each (the same ~27 µs/round-trip Tier 4's own numbers cite). The
   metadata slots that are *cleanly* persistable cost **4.4 µs** — precompiling them saves
   nothing a human could measure.

2. **The expensive part requires Carbon loaded anyway.** The first cold hydrate+toArray
   is ~28 ms — dominated by autoloading Carbon and friends, a one-time cost any process
   touching a date column pays whether or not the blueprint is precomputed. The probe's
   466 µs sits *on top of* an already-warm Carbon; you can't persist your way out of the
   28 ms, and the 466 µs is small against it.

3. **It is sub-millisecond and a rounding error against boot.** 470 µs is 3.3% of the
   Capsule boot alone, and a real Laravel CLI boot (container, providers, config) is much
   larger. A short-lived `artisan` command touching, say, 1–5 model classes saves
   ~0.5–2.5 ms total — against tens of ms of framework boot it doesn't control.

**The brief asked the decisive question and the answer is clear: the lazy build is
sub-millisecond, so precompiling saves nothing meaningful.** Per CLAUDE.md's measure-first
rule, that alone parks it — but the risk side makes it worse, below.

## Where it could ever matter (and why that's already covered)

- **Octane / long-lived workers:** the runtime-lazy cache warms after the first request
  and *stays* warm for the worker's life. The build happens once, ever, off the hot path.
  A persisted artifact saves one ~470 µs build per worker boot — invisible. **Already
  the right default.**
- **FPM / per-request:** every request rebuilds *everything* (framework included); the
  470 µs blueprint build is noise inside that, and opcache doesn't persist runtime statics
  anyway. A file-based artifact would add a `require` + un-tagging cost per request that
  could plausibly *exceed* the 4.4 µs metadata build it replaces.
- **Short-lived CLI (the only real target):** queue tick, scheduled command, one-off
  artisan. Here the lazy build genuinely happens fresh each process. But it's ~0.5 ms/class
  and the date-probe majority needs the right tz/conn to be reusable at all (next section).

## Prior art: how Laravel persists precomputed artifacts (and its staleness model)

Studied the four core caches as the template (agent-gathered, line refs verified):

- **`config:cache`** — `ConfigCacheCommand.php:56-85`. `var_export()` to
  `bootstrap/cache/config.php` (`Application::getCachedConfigPath`, `:1317`). **Actively
  guards against closures**: re-`require`s the written file and, on failure, `eval`-tests
  each value and throws `LogicException` (`:69-82`). Loaded by `LoadConfiguration.php:37-45`
  on `file_exists`.
- **`route:cache`** — `RouteCacheCommand.php:52-71`. Routes are closure-bearing, so it
  calls `prepareForSerialization()` per route (`:63`), which wraps closures in
  `Opis\SerializableClosure` (`Route.php:1481-1498`). var_export'd to `routes-v7.php`.
  This is the *only* core cache that handles closures, and it needs a dedicated dependency
  to do it.
- **`event:cache`** — `EventCacheCommand.php:31-41`. var_export of discovered
  class@method listener strings (`DiscoverEvents`). **No closure guard** — closures in
  `$listen` just fail. `file_exists` load (`EventServiceProvider.php:108`).
- **Package discovery** — `PackageManifest.php:101-139`. var_export of metadata;
  `getManifest()` rebuilds only when the file `is_file()` is false (`:101-113`).

**The decisive precedent — staleness is *purely explicit*, with no mtime tracking
anywhere.** Validity = "the file exists." The developer must run `php artisan optimize`
after changes and `optimize:clear` on deploy (`OptimizeCommand.php:62-64`,
`OptimizeClearCommand.php:59-70`); each `*:cache` first runs its `*:clear`. **Nothing in
Laravel detects that a source file changed and the cache is now wrong.** That is an
acceptable bargain for config/routes/events, because a stale route cache is a *visible*
404 in dev and you re-cache. It is **not** an acceptable bargain for Grease, because a
stale blueprint is **silent byte-divergence in production output** — the one failure mode
the product promises can't happen.

## The correctness trap: staleness / invalidation hazards

A persisted blueprint must be **byte-identical to the lazily-built one or it's a
correctness bug** (this is the parity bar — see below). Every way it can drift:

1. **Edit a model's `$casts` / `casts()` / `$dateFormat` / `$appends` / `$table` / keys**
   and forget to re-cache → the persisted `casts`/`castsInit`/`model` slots are wrong →
   every hydrate and `toArray()` diverges silently. This is the headline footgun.
2. **Timezone drift.** `dateSerialize` and `dateCast` are keyed by
   `date_default_timezone_get()` (`HasGreasedSerialization.php:142,184`) *precisely because*
   the UTC-ISO rewrite is only valid at a zero offset. A build-time tz of UTC and a runtime
   tz of `America/New_York` means the persisted entry's key never matches → it rebuilds
   anyway (best case: artifact wasted) — unless someone "optimizes" by dropping the tz key
   on load, which would serve a UTC rewrite under a non-UTC zone = **wrong dates**. The
   safe behaviour (rebuild on key miss) makes the persisted date slots useless exactly when
   the app isn't UTC; the unsafe behaviour is the cardinal sin.
3. **Connection drift.** Same shape, keyed by connection name — a different default
   connection (or Octane reconfig) changes `getDateFormat()` and invalidates the date slots.
4. **Closure rehydration mismatch.** Persisting `dateCast` as a tag and rebuilding the
   closure on load means the *rebuilt* closure must be byte-identical to
   `greaseBuildDateCastRewrite`'s output for all inputs. A future edit to that builder
   without bumping an artifact version → silent drift.
5. **STI subclasses.** Keyed by `static::class`, so the artifact must enumerate every
   concrete model class — a discovery step (à la `DiscoverEvents`) that can miss
   dynamically-defined or package-provided models, leaving them un-cached (harmless) or,
   if mis-discovered, stale.
6. **Laravel version skew.** `casts`/`dates`/mutator results are derived from the
   running framework's `parent::` methods; a `composer update` that changes Eloquent's cast
   normalization invalidates the artifact with no signal.

**How a `model:cache` flow would have to detect these:** Laravel's precedent (explicit
re-run, `file_exists` only) is *insufficient* here — it relies on the dev remembering, and
the failure is silent. To be safe Grease would need *more* than core does: a content hash
of every greased model's class file (mtime is too coarse and breaks on deploy-copies with
reset timestamps) **plus** the running tz, connection config, and framework version baked
into the artifact, **plus** a load-time check that the artifact's hashes match the live
environment and a **hard rebuild-or-skip on any mismatch**. That's a non-trivial
invalidation engine guarding a ~0.5 ms/class saving — and any gap in it reintroduces the
cardinal sin.

## The parity bar (the spine)

**A persisted blueprint slot, when loaded, must produce output byte-identical to the
slot the lazy path would build in the same environment — for every model, every
attribute, every `toArray()`/`toJson()`.** Equivalently: loading the artifact must be
*observationally indistinguishable* from never having had it. The existing parity suite
(`CastParityTest`, `DateSerializationParityTest`, `SqlRoundtripTest`) would need to run a
second time *with a pre-loaded artifact* to prove the persisted path matches the lazy
path — and a deliberate-staleness test (edit a fixture's casts, load a stale artifact,
assert the loader *rejects* it rather than serving divergent output). The bar is the same
byte-identical contract as the rest of Grease; this tier just adds a second way to violate
it.

## If it were ever built (design sketch, not a recommendation)

For completeness, the least-bad shape:

- An `grease:blueprint-cache` artisan command that discovers greased models (scan for
  `HasGrease`/`GreasedModel`), instantiates each, runs a full warm-up (hydrate a blank +
  `getCasts`/`getDates` + a `toArray` probe), then var_exports `$greaseBlueprint` **with
  `dateCast` closures replaced by their `'utc_iso'|'identity'` tag** and a header carrying
  `{php_version, framework_version, default_tz, connections[], per_class_file_hash}`.
- A loader in `InteractsWithGreaseBlueprint` that, on first access, validates the header
  against the live environment and **only** seeds `$greaseBlueprint` on an exact match;
  any mismatch → ignore the file, fall to lazy build (never serve a suspect slot).
- Re-key the date slots on load by the *current* tz/conn, accepting that a tz/conn miss
  just rebuilds them.

Even done perfectly this saves ~4.4 µs/class reliably (the metadata) and ~466 µs/class
*only when build-time and runtime tz/conn agree* — i.e. the expensive slot is the fragile
one. The complexity-to-payoff ratio is poor.

## Bottom line / recommendation

1. **PARK it.** The runtime-lazy cache is already the correct default (Octane-safe,
   warms after the first hit, zero staleness surface). This add-on targets only
   short-lived CLI, where it saves **sub-millisecond per class**.
2. **The persistable part is nearly free to build (~4.4 µs); the expensive part (~466 µs
   of Carbon probing) is the part that can't be safely persisted** — it's tz/connection-
   keyed and closure-bearing. The economics are inverted.
3. **It is the one tier whose failure mode is the cardinal sin.** Every other tier fails
   *toward* vanilla (defer to `parent::`); a stale persisted blueprint fails *toward silent
   byte-divergence*, and Laravel's own cache precedent (explicit re-run, no mtime) gives no
   safety net. Buying ~0.5 ms with that risk is a bad trade for a package whose entire
   pitch is "drop it into a 200-model app without auditing."
4. If a future user files a real CLI cold-start complaint with numbers, revisit with the
   design sketch above and a content-hash invalidation engine. Until then this belongs
   beside NOTES #8 ("NOT worth it") — now with the measurement that proves it.

---

### 5-line summary

- **Tier:** E. Persisted/precompiled blueprint — a `model:cache`-style artisan artifact to skip the lazy per-class blueprint build on CLI cold-start.
- **Mechanism:** var_export `$greaseBlueprint[$class]` to a cache file (dateCast closures tag-ified, à la config:cache/route:cache), load + validate at boot.
- **Expected magnitude:** ~470 µs saved per class per process — but ~466 µs of that is tz/conn-keyed Carbon date-probes that can't be safely persisted; the cleanly-persistable metadata builds in ~4.4 µs. Net meaningful saving ≈ nil; ~3.3% of Capsule boot.
- **Top risk:** a stale cached blueprint after a model/tz/connection/framework change = silent byte-divergence (the cardinal sin), and Laravel's cache precedent has no mtime/staleness detection to catch it.
- **Recommendation:** **PARK.** Cold-start savings are sub-millisecond and don't justify the only footgun in the package that fails toward wrong output instead of vanilla.
