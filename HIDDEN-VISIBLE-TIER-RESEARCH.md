# Hidden/visible flip caching — research (NOTES.md open item #5)

**Verdict up front: PARK.** This is a *validation* of the design already sketched in
[SERIALIZATION-TIER.md](SERIALIZATION-TIER.md), now with the bench numbers that doc
explicitly lacked — and the numbers reverse its tentative "build only if it clears
noise" into a clear **do-not-build**. Two findings drive it:

1. The bottleneck (`array_flip($hidden)` / `array_flip($visible)`) is **already
   near-free**: a 2–5 element flip is ~130 ns. The realistic hidden array (`['password',
   'remember_token']`) is exactly that size.
2. The specifically-recommended **value-compare cache is a net *loss* at that realistic
   size** (−15% to −23% — i.e. *slower* than vanilla), because an `===` comparison of two
   small arrays plus a nested static lookup costs **more** than the `array_flip` it
   avoids. The cheaper divergence-flag variant only breaks even at hidden≥2 and only wins
   meaningfully at hidden≥5 (+11–22%), a size real apps rarely reach.

Even in the most optimistic case the absolute saving is **single-digit microseconds per
50-model collection serialize** — and that sits on top of a `toArray` the date tier
already dominates (~27 µs *per date column per row*). It is a no-op for the no-hidden
majority and a regression-or-noise for the hidden-bearing minority. This is the textbook
"benchmark-inside-noise = legitimate park" outcome from the brief.

All line refs verified against the framework fork at `../../framework` (**Laravel
13.16.1**, `Application::VERSION`) as read on 2026-06-21. Cross-references
[SERIALIZATION-TIER.md](SERIALIZATION-TIER.md) rather than re-deriving the structural
analysis; this doc adds the source re-validation and the missing measurements.

---

## 1. Source re-validation (vs current framework, L13.16.1)

The SERIALIZATION-TIER.md line refs were checked against the live source. **All still
exact**, and the relevant methods are long-standing/structurally stable (no L11→L13
shape change in `getArrayableItems` or the `HidesAttributes` mutators).

### The bottleneck — verbatim

`HasAttributes::getArrayableItems` (`src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:447`):

```php
protected function getArrayableItems(array $values)
{
    if (count($this->getVisible()) > 0) {
        $values = array_intersect_key($values, array_flip($this->getVisible()));
    }

    if (count($this->getHidden()) > 0) {
        $values = array_diff_key($values, array_flip($this->getHidden()));
    }

    return $values;
}
```

The `array_flip($this->getVisible())` (`:450`) and `array_flip($this->getHidden())`
(`:454`) are the only per-call recomputation of a class-pure fact in the whole method.

### Callers (confirmed file:line)

| caller | line | runs when |
|---|---|---|
| `getArrayableAttributes()` | `HasAttributes.php:364` (`return $this->getArrayableItems($this->getAttributes());`) | always |
| `getArrayableAppends()` | `HasAttributes.php:382`, guarded by `if (! count($appends))` at `:378` | only when `$appends` non-empty |
| `getArrayableRelations()` | `HasAttributes.php:438` (`return $this->getArrayableItems($this->relations);`) | always — even with no relations loaded (guard is on `count($hidden/$visible)`, not on `$values`) |

`attributesToArray()` is at `HasAttributes.php:225`; `relationsToArray()` at `:392`.
So per `toArray()` on a hidden-only model with no appends: **2× `array_flip($hidden)`**
(attributes + relations). With both `$visible`/`$hidden` and appends: up to 6 flips.
Matches SERIALIZATION-TIER.md exactly.

### What is NOT redundant (the scope-narrowing claims — re-confirmed)

- `getMutatedAttributes()` is **already class-cached**: `HasAttributes.php:2522` returns
  `static::$mutatorCache[static::class]`, populating it once via `cacheMutatedAttributes`.
  No grease win.
- `getAppends()` (`HasAttributes.php:2461`) just `return $this->appends;` — no flip.
- `getCasts()` / `getDates()` are already memoized by **Tier 2** (`HasGreasedAttributes`).

**Net prize = caching `array_flip($hidden)` and `array_flip($visible)` per class.** That
is the entire optimization. (Re-confirmed verbatim from current source.)

### The mutators (HidesAttributes — confirmed file:line, L13.16.1)

`$hidden`/`$visible` are mutable per-instance after construction:

- `setHidden` (`HidesAttributes.php:53`), `setVisible` (`:93`)
- `makeVisible` (`:123`), `makeHidden` (`:154`), `makeVisibleIf` (`:143`), `makeHiddenIf` (`:170`)
- `mergeHidden` (`:66`), `mergeVisible` (`:106`)
- `getHidden` (`:42`), `getVisible` (`:82`) — plain property returns

The **`#[Hidden]`/`#[Visible]` construction trap is still live**: `initializeHidesAttributes`
(`HidesAttributes.php:30`, `#[Initialize]`) runs on every construction and calls
`mergeHidden(static::resolveClassAttribute(Hidden::class,'columns') ?? [])` (`:33`) and
the `Visible` equivalent (`:34`). `mergeHidden` early-returns on `[]` (`:68`), so a classic
`protected $hidden = [...]` model is untouched at birth, but a `#[Hidden]`-attribute model
performs a genuine `[] → ['x']` change *during construction*. This is exactly the wrinkle
that rules out a naive before/after divergence flag (it would flag every `#[Hidden]`
instance "diverged" at birth).

---

## 2. Class-pure vs recomputed

| value | class-pure? | recomputed per call today? | cacheable? |
|---|---|---|---|
| `array_flip($hidden)` | yes, *until* a runtime mutator fires | yes, every `getArrayableItems` | yes, per class + divergence handling |
| `array_flip($visible)` | same | yes | same |

The fact is class-pure for the overwhelming majority (static `$hidden`/`$visible`), but
**per-instance mutable** via the eight mutators above — so any cache needs divergence
handling, same shape as the Tier-2 cast guard but with the `#[Hidden]` complication.

---

## 3. Design (probe / divergence) — and why both variants disappoint

This tier would extend the existing **`HasGreasedSerialization`** (Tier 4) by adding one
override: `getArrayableItems`. The blueprint slot lives in `static::$greaseBlueprint[static::class]`,
cleared atomically by the existing `flushGreaseBlueprint()` / `clearBootedModels()`
(`InteractsWithGreaseBlueprint`).

### Variant A — value-compare (the SERIALIZATION-TIER.md recommendation)

Cache `['src' => $list, 'flip' => array_flip($list)]` per slot; on each call compare the
live `$hidden`/`$visible` against the cached `src` with `===`. Match → reuse; mismatch →
compute locally (a diverged instance never reads/writes the class slot). Correct against
*every* mutation path including `#[Hidden]`, with no mutator overrides.

**Measured: this is a net loss at realistic sizes** (see §5). The `$s['src'] === $list`
array compare plus the nested static fetch costs more than re-flipping a 2–5 element array.

### Variant B — divergence flag (SERIALIZATION-TIER.md rejected it; measured here anyway)

A `$greaseHidesDiverged` bool; when not diverged, `$bp['hiddenFlip'] ??= array_flip(...)`.
The guard is a single bool + `??=`, so it is **cheaper** than value-compare and *does* win
at larger sizes. But:

- It needs **six mutator overrides** to set the flag (`setHidden`/`setVisible`/`make*`/`merge*`).
- The `#[Hidden]` construction trap means a `#[Hidden]`-attribute model is flagged diverged
  at birth → tier silently disabled for it (falls to local flip = **still correct, just
  unaccelerated** — the Grease philosophy, but it quietly excludes a documented feature).
- Distinguishing the construction-time `#[Hidden]` merge from a genuine runtime merge would
  need a "past construction" signal — extra complexity.

Even Variant B only reaches break-even at the realistic hidden=2 size; its wins need
hidden≥5.

---

## 4. Parity risks + every defer/divergence case

Parity bar: **byte-identical `toArray`/`toJson`/`attributesToArray`/`relationsToArray`**
(this is on the serialization output path, so it is a byte-output bar, not behavioural).

Defer / divergence cases that must stay byte-identical to vanilla:

1. **No hidden & no visible** (the majority) — both `!== []` checks false, return `$values`
   untouched. Tier is a pure no-op. *(Must add zero overhead — and note Variant A fails even
   this neutrality goal once hidden is set.)*
2. **Runtime `setHidden`/`setVisible`** — replaces the array; cache must not serve the old flip.
3. **Runtime `makeHidden`/`makeVisible`** (and `makeHiddenIf`/`makeVisibleIf` routing through
   them) — mutate per-instance.
4. **Runtime `mergeHidden`/`mergeVisible`** — append per-instance.
5. **`#[Hidden]`/`#[Visible]` attribute models (L11+)** — construction-time merge; Variant A
   accelerates them, Variant B does not (defers, still correct).
6. **`$appends` + `$hidden`** — appends flow through `getArrayableItems` via
   `array_combine($appends,$appends)` (`HasAttributes.php:382`); hidden must still filter
   appended keys identically.
7. **Eager-loaded relations** — relations route through `getArrayableItems` (`:438`); must
   filter identically with relations present and absent.
8. **STI subclasses** — different `$hidden` per subclass; keyed by `static::class`, so safe.
9. **A diverged instance must not poison the class baseline** for a later default instance
   (the ported "doesn't poison the cache" guarantee).

---

## 5. Bench results (the numbers SERIALIZATION-TIER.md lacked)

Throwaway micro-bench (`hrtime`, best-of-5, interleaved, warmed; isolates the
`getArrayableItems` pair = one `toArray`'s attributes + relations calls; both arms share
`getVisible()`/`getHidden()` cost so the delta is purely the flip vs cache machinery). PHP
8.4.19. Numbers are ns per `toArray`-shaped pair.

**Realistic User-shaped model (12-key attributes, hidden = `['password','remember_token']`):**

| case | vanilla | value-compare (A) | flag (B) |
|---|---|---|---|
| hidden [2] (the common case) | 575–649 ns | **−15% to −23% (slower)** | −1% to +0% (noise) |
| hidden [5] | ~670 ns | −19% (slower) | +11% |
| hidden [10] | ~683 ns | −5% (slower) | +13% |
| hidden [20] | ~752 ns | +5% | +23% |
| both visible[3]+hidden[2] | ~782 ns | −26% (slower) | n/a |

- **Raw `array_flip([2 elems])` alone: ~132 ns.** That is the entire prize per flip — and
  the cache machinery to avoid it costs ≥ that at small sizes.
- **Per-request (50-model collection serialize):** even taking Variant A's *loss* at face
  value it is ~−5 to −10 µs/request; Variant B at the realistic hidden=2 is **within noise
  (≈0 µs)**. The win only becomes a few µs/request at hidden≥5 with Variant B.

**Context for magnitude (honesty):** the whole `getArrayableItems` pair is ~0.65 µs out of
a greased `toArray` measured at **164 µs** in `CastBench` (NOTES.md) — i.e. **~0.4% of a
toArray**, of which we could save at most ~130 ns ≈ **0.08%**. Per-op, not request-level;
and far below the `:memory:`-inflated macro percentages. This is structurally a rounding
error next to the date tier (−92%).

---

## 6. Parity test plan (if ever built — unchanged from SERIALIZATION-TIER.md, restated)

Before trusting any number:

1. **Fixtures:** `$hidden`-only, `$visible`-only, and both-set greased/vanilla pairs
   (identical but for the trait, per the CLAUDE.md fixture convention) — plus a `#[Hidden]`
   attribute pair for case #5. *(Today's `VanillaSample`/`GreasedSample` set neither, so the
   tier benchmarks at exactly 0% — fixtures are a hard prerequisite.)*
2. **Equivalence:** `toArray`/`attributesToArray`/`relationsToArray`/`toJson` byte-identical
   to vanilla for each fixture, with and without an eager-loaded relation.
3. **Divergence:** after each of `setHidden`/`setVisible`/`makeHidden`/`makeVisible`/
   `mergeHidden`/`mergeVisible`/`makeHiddenIf`/`makeVisibleIf` — output matches vanilla, and
   a mutated instance does not poison the class baseline for a fresh default instance
   (ported from the cast tier's "doesn't poison the cache" test).
4. **`#[Hidden]`/`#[Visible]` (L11+)** — output identical (and, for Variant B, assert it
   correctly defers rather than mis-caches).
5. **Appends + hidden** (case #6) — appended keys filtered identically.

## 7. Bench plan (if ever built)

Add a `toArray` subject over a hidden-bearing fixture to `CastBench` (paired
vanilla/greased) and extend `realworld.php`'s `index_users` to a User with realistic
`$hidden`. **Gate on measured delta clearing `rstdev`.** Per §5 it will not — record the
number and file the tier alongside NOTES item #8 ("NOT worth it") with the proof.

---

## 8. Honest verdict — PARK, with the number

- The optimization target is real but **already near-free** (130 ns/flip at realistic size).
- The design SERIALIZATION-TIER.md recommended (value-compare) **regresses** at the realistic
  hidden=2 size (−15% to −23%); the cheaper flag variant is **within noise** there and only
  wins at hidden≥5, a size real apps seldom hit — and it costs six mutator overrides plus the
  `#[Hidden]` carve-out.
- Best-case absolute saving is **single-digit µs per 50-model request**, ~0.08% of a `toArray`,
  dwarfed by the banked date tier.
- **Recommendation: do not build.** This belongs with NOTES item #8, now with measurements.
  It is the legitimate "benchmark inside noise" park. Revisit only if a future model shape
  with a genuinely large (≥10-element) `$hidden`/`$visible` on a hot serialization path
  appears — and then prefer the flag variant, measured against that shape.
