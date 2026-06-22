# Write / fill tier — research (NOTES.md open item #4)

**Verdict up front: PARK IT.** The optimization is real and sound, and the
divergence-safe design below is buildable at low risk — but the measured win is
**below the bar of every tier already shipped, and below the hidden/visible tier
already shelved.** For the *recommended* model shape (a `$fillable` list, default
`$guarded = ['*']`) the per-`fill()` saving is **~0.6 µs = −0.2%, inside noise.**
The only non-noise win is the **guarded-list** shape (`$guarded = ['id', …]`,
empty `$fillable`) — **~1.25–4 µs = −5.2%** per fill from killing the per-key
`preg_grep` regex compile — but that shape is the pattern Laravel's own docs
discourage in favour of `$fillable`. And `fill()` is **write-path only**: hydration
(the hot read path, already greased) does **not** go through it. Net: a completeness
add at best, and a smaller one than the date tier by two orders of magnitude. Record
the number and move on — unless a guarded-list-heavy write workload is the target.

All line refs are against the framework fork at `../../framework` as read on 2026-06-21.

---

## What actually happens on `fill()`

`Model::fill()` (`Model.php:680`):

```php
public function fill(array $attributes)
{
    $totallyGuarded = $this->totallyGuarded();          // count(fillable)===0 && guarded==['*']

    $fillable = $this->fillableFromArray($attributes);   // ONE array_flip(fillable) + intersect

    foreach ($fillable as $key => $value) {
        if ($this->isFillable($key)) {                   // <-- O(F) per key (or preg_grep per key)
            $this->setAttribute($key, $value);           // <-- dominates the cost
        } elseif ($totallyGuarded || static::preventsSilentlyDiscardingAttributes()) {
            // throw MassAssignmentException / discarded-violation callback
        }
    }
    // … preventsSilentlyDiscardingAttributes() diff-and-throw …
    return $this;
}
```

`fillableFromArray()` (`GuardsAttributes.php:282`) flips the fillable list once per
call:

```php
protected function fillableFromArray(array $attributes)
{
    if (count($this->getFillable()) > 0 && ! static::$unguarded) {
        return array_intersect_key($attributes, array_flip($this->getFillable()));
    }
    return $attributes;
}
```

`isFillable()` (`GuardsAttributes.php:197`) is the per-key hot spot:

```php
public function isFillable($key)
{
    if (static::$unguarded) {
        return true;
    }
    if (in_array($key, $this->getFillable())) {          // O(F) linear scan, per key
        return true;
    }
    if ($this->isGuarded($key)) {                        // see below
        return false;
    }
    return empty($this->getFillable()) &&
        ! str_contains($key, '.') &&
        ! str_starts_with($key, '_');
}
```

`isGuarded()` (`GuardsAttributes.php:228`) — the expensive branch, the **regex
compile per key**:

```php
public function isGuarded($key)
{
    if (empty($this->getGuarded())) {
        return false;
    }
    return $this->getGuarded() == ['*'] ||
           ! empty(preg_grep('/^'.preg_quote($key, '/').'$/i', $this->getGuarded())) ||
           ! $this->isGuardableColumn($key);
}
```

### The two real-world shapes (they behave very differently)

| shape | `$fillable` | `$guarded` | hot cost per `fill()` |
|---|---|---|---|
| **fillable-list** (recommended) | `['a','b',…]` | `['*']` (default) | `array_flip(F)` once + `in_array` per accepted key. `isGuarded` short-circuits at `==['*']` — **no preg_grep**. |
| **guarded-list** | `[]` | `['id',…]` | `fillableFromArray` returns all attrs; `isFillable` runs `in_array($key, [])` (cheap) then `isGuarded` → **`preg_grep` regex compile per key**, then a cached `isGuardableColumn` schema check. |
| **fully guarded** (default model) | `[]` | `['*']` | `totallyGuarded()` true → any non-fillable key **throws** `MassAssignmentException`. Can't be mass-assigned at all, so not a fill workload. |

So the `O(N·F)` the brief cites is two distinct costs: the `in_array`-over-fillable
scan (fillable-list shape) and the `preg_grep`-per-key compile (guarded-list shape).
The fully-guarded default model is irrelevant — it throws rather than fills.

## What is class-pure here (cacheable)

- `array_flip($this->fillable)` — a class-pure flip of `$fillable` (identical to the
  serialization tier's `array_flip($hidden)` shape).
- A flipped/lowercased set of `$guarded` — replaces the per-key `preg_grep('/^k$/i',
  $guarded)` with an `isset($lowerSet[strtolower($key)])`.
- `totallyGuarded()` — pure derivation of the two lists.

## What is NOT redundant (narrows the win hard)

- **`setAttribute()` dominates `fill()`, not the fillable lookup.** Measured: a
  10-key fill on a fillable-list model is **~44 µs**, of which the fillable lookup is
  **~0.9 µs** (≈ 2%). The other ~43 µs is `setAttribute`'s per-key mutator/cast
  probing — and on a *greased* model that part is **already cut by Tier 2**
  (`hasSetMutator`/cast memo). The fill tier targets only the ~2% crumb that's left.
- **`isGuardableColumn()`'s schema lookup is already class-cached by core**
  (`static::$guardableColumns[get_class($this)]`, `GuardsAttributes.php:251,260`).
  Grease must leave it verbatim — only the `preg_grep` that *precedes* it is the target.
- **`getFillable()`/`getGuarded()` are plain property reads** — no recompute to memoize;
  the cost is the `in_array`/`preg_grep` *over* them, not fetching them.
- **Hydration does not call `fill()`.** `newFromBuilder()` → `newInstance([], true)`
  (`Model.php:799`) passes **empty** attributes, so the hot loop never runs; rows are
  set via `setRawAttributes`. Grease's `HasGreasedHydration::newFromBuilder` bypasses
  it entirely. **This tier touches `create()` / `new Model($attrs)` / `update($attrs)`
  / `->fill()` only — the write path, which is far colder than reads.**

---

## Measured (throwaway micro-bench, hrtime, best-of-5, interleaved, warmed)

Full `fill()` A/B (vanilla vs a flipped-lookup override), `:memory:` Capsule:

| shape | vanilla | greased | delta |
|---|---|---|---|
| fillable-list, 10 keys | 44.05 µs | 43.94 µs | **−0.2%** (≈ noise) |
| guarded-list (`['id']`), 12 keys | 75.12 µs | 71.22 µs | **−5.2%** (~3.9 µs) |

Isolated lookup cost (no `setAttribute` noise), 12 keys:

| operation | vanilla | greased | saved |
|---|---|---|---|
| fillable: `array_flip` + `in_array`×12 | 0.89 µs | 0.28 µs (cached `isset`) | **~0.6 µs/fill** |
| guarded: `preg_grep`×12 | 1.85 µs | 0.60 µs (cached lowercase `isset`) | **~1.25 µs/fill** |

So the win is **~0.6 µs/fill** for fillable-list models and **~1.25–4 µs/fill** for
guarded-list models. Compare to the shipped read tiers: hydrate −61% (~tens of µs/row),
toArray −53% (~187 µs), date serialization ~27 µs/column/row. The fill tier is
**1–2 orders of magnitude smaller in absolute µs, and fires on a far rarer event.**

**Cost of the guard itself:** the value-compare is an `===` on a 2–10 element array
once per fill (not per key) — allocation-free, ~0.05 µs. It does not erase the win.

---

## The correctness trap: runtime divergence (why a naive cache is UNSOUND)

`$fillable`, `$guarded`, and `static::$unguarded` are all mutable at runtime — prior
art removed a naive fillable cache for exactly this reason:

- `fillable(array)` / `mergeFillable(array)` (`GuardsAttributes.php:75,88`)
- `guard(array)` / `mergeGuarded(array)` (`GuardsAttributes.php:117,130`)
- `unguard()` / `reguard()` flip the static (`:143,153`); `unguarded(callable)`
  (`:176`) flips it for the duration of a callback — **`forceFill()` is exactly this**
  (`Model.php:730`: `static::unguarded(fn () => $this->fill($attributes))`).

**The `#[Fillable]` init trap (same shape the serialization research flagged).**
`initializeGuardsAttributes()` (`GuardsAttributes.php:46`) runs on **every**
construction and calls `mergeFillable(resolveClassAttribute(Fillable::class, …))`
plus sets `$guarded` from the `#[Guarded]`/`#[Unguarded]` attributes. A classic
`protected $fillable = [...]` model merges `[]` (early-returns at `:90`, no change),
but a `#[Fillable]`-attribute model performs a genuine merge on every instance. A
before/after divergence **flag** would therefore mark every `#[Fillable]` instance
"diverged" at birth and the cache would never populate. **So the casts tier's flag
pattern does not transplant — use value-compare**, exactly as the serialization tier
concluded for `#[Hidden]`.

Crucially, the `#[Fillable]` merge happens in the constructor (an `#[Initialize]`
method) **before** `fill()`, and is deterministic per class — so by fill-time every
default instance's `$fillable` is identical, and a value-compare baseline matches
across them. The merge is invisible to a value-compare; it would be fatal to a flag.

---

## Recommended design — value-compare flip + ASCII-certified guarded set

Mirror `HasGreasedSerialization::greaseFlip` precisely. New concern
`HasGreasedWrites` (`use InteractsWithGreaseBlueprint`), overriding the two
chokepoints. **Read `static::$unguarded` live every call** (it's a static bool read,
free, and `forceFill`/`unguarded()` flip it dynamically — never cache that decision).

```php
protected function fillableFromArray(array $attributes)
{
    if ($this->fillable !== [] && ! static::$unguarded) {
        return array_intersect_key($attributes, $this->greaseFillFlip());
    }
    return $attributes;
}

public function isFillable($key)
{
    if (static::$unguarded) {
        return true;                       // verbatim vanilla
    }
    if (isset($this->greaseFillFlip()[$key])) {
        return true;                       // replaces in_array($key, $fillable)
    }
    if ($this->isGuarded($key)) {
        return false;
    }
    return $this->fillable === [] &&
        ! str_contains($key, '.') && ! str_starts_with($key, '_');
}

public function isGuarded($key)
{
    if (($g = $this->getGuarded()) === []) {
        return false;
    }
    if ($g === ['*']) {
        return true;
    }
    // Probe-certified: only when the lowercase set reproduces preg_grep for THIS
    // guarded list (pure-ASCII entries); otherwise defer to vanilla preg_grep.
    $set = $this->greaseGuardSet();        // array|false
    if ($set !== false) {
        return isset($set[strtolower($key)]) || ! $this->isGuardableColumn($key);
    }
    return ! empty(preg_grep('/^'.preg_quote($key, '/').'$/i', $g))
        || ! $this->isGuardableColumn($key);
}
```

`greaseFillFlip()` / `greaseGuardSet()` use the **value-compare** baseline (the
serialization tier's exact mechanism): the class slot stores `['src' => $list, …]`;
an instance whose live list `=== src` reuses the cached flip/set, a diverged instance
computes locally and never pollutes the slot. STI-safe and Octane-safe for free
(`$greaseBlueprint[static::class]`, cleared by `flushGreaseBlueprint()` /
`clearBootedModels()`).

**Why `greaseGuardSet()` must be probe-certified, not blind.** `preg_grep('/^k$/i',
$guarded)` is a case-insensitive **whole-string** match (`preg_quote` makes the key a
literal). For ASCII column names this is byte-identical to `isset($lowered[strtolower
($key)])`. But PCRE `/i` case-folding and PHP `strtolower` can **disagree on
non-ASCII** (locale/Unicode vs byte-ASCII). Column names are virtually always ASCII,
but a non-ASCII guarded entry is a silent-divergence risk. Certify once per class:
build the lowercase set **only if every guarded entry is pure ASCII** (or, stronger,
verify the set reproduces `preg_grep` against the guarded list itself); otherwise
return `false` and the code falls to verbatim `preg_grep`. Defer = correct, unaccelerated.

---

## Parity risks + every defer/divergence case

Parity bar here is **behavioural + state** (same attributes set to same values, same
`MassAssignmentException`/discarded-violation behaviour), **not byte-output** — but
the resulting attribute array is fully observable and must match vanilla exactly.

1. **`static::$unguarded` true** (global `unguard()`, inside `forceFill`/`unguarded()`):
   read live → both methods short-circuit to "everything fillable", verbatim vanilla.
   Never cached.
2. **Runtime `fillable([...])` / `mergeFillable([...])`**: instance `$fillable !== src`
   → value-compare computes the flip locally, class slot untouched. Correct, unaccelerated
   for that instance; a fresh default instance afterward still hits the cache.
3. **Runtime `guard([...])` / `mergeGuarded([...])`**: same value-compare on the guarded
   list.
4. **`#[Fillable]` / `#[Guarded]` / `#[Unguarded]` attribute models** (L11+): deterministic
   per-class init → baselines match → accelerated (the whole reason for value-compare).
5. **Non-ASCII guarded entries**: `greaseGuardSet()` returns `false` → verbatim
   `preg_grep`. The one true correctness wrinkle; certified away, never guessed.
6. **`isGuardableColumn()` schema path**: untouched — still core's logic and core's
   `static::$guardableColumns` cache. Only the preceding `preg_grep` is replaced.
7. **`totallyGuarded()` / `preventsSilentlyDiscardingAttributes` / discarded-violation
   callback**: the override changes *how* `isFillable` decides, never *what* it decides,
   so the throw/discard branches in `fill()` are reached on exactly the same keys.
8. **STI**: keyed by `static::class`; a child with a different `$fillable`/`$guarded`
   gets its own slot.

Self-correcting like the serialization tier: worst case (first-ever filled instance is
a runtime-mutated one) seeds the baseline from its list; later default instances then
`!== src` and fall to the local-compute path — still correct, just uncached.

---

## Parity test plan (the spine)

1. **Fixtures:** a fillable-list pair, a guarded-list pair (`$guarded=['id', …]`), a
   `#[Fillable]`/`#[Guarded]` attribute pair (L11+), each greased vs vanilla, identical
   but for the trait. (No DB needed except the guarded path's `isGuardableColumn` —
   migrate a table as `SqlRoundtripTest` does.)
2. **Equivalence:** after `fill()` / `forceFill()` / `new Model($attrs)` /
   `create($attrs)` / `update($attrs)`, the resulting `getAttributes()` is identical to
   vanilla — including which keys were dropped.
3. **Mass-assignment exceptions:** a fully-guarded model and a `preventsSilentlyDiscarding`
   model throw `MassAssignmentException` (or invoke the discarded-violation callback)
   on the same keys, same message.
4. **Divergence:** after `fillable()`, `mergeFillable()`, `guard()`, `mergeGuarded()`,
   `unguard()`/`reguard()`, and inside `forceFill`/`unguarded()` — output matches vanilla,
   **and** a mutated instance does not poison the class baseline for a fresh default
   instance filled afterward (port the cast tier's "doesn't poison the cache" test).
5. **Non-ASCII guarded entry:** a `$guarded` with a multibyte key — confirm the tier
   defers to `preg_grep` and stays identical to vanilla (the certification guard fires).
6. **Case-insensitivity:** mixed-case input keys vs lowercase guarded entries — confirm
   the lowercase set matches `preg_grep`'s `/i` for ASCII.

## Bench plan

Add a `fill` subject to `CastBench` over both a fillable-list and a guarded-list
fixture (paired vanilla/greased). **Gate on measured delta vs `rstdev`:** the
fillable-list arm is expected to land inside noise (−0.2% here) — if it does, that arm
is not worth shipping and that's a legitimate "park it". The guarded-list arm (~−5%)
is the only candidate; size it on a realistic guarded list (3–5 entries) and key
count. Record both numbers either way. (The `realworld.php` macro's `bulk_update`
endpoint is the request-level home, but it re-reads via hydration — fill is a thin
slice of it; expect sub-1% there.)

---

## Bottom line / honest verdict

- **Expected magnitude:** −0.2% per `fill()` (fillable-list, the recommended shape —
  inside noise); −5.2% / ~4 µs per `fill()` (guarded-list shape only). Absolute saving
  ~0.6–4 µs/fill.
- **Risk:** low, *if* the guarded set is ASCII-certified (the one real wrinkle) and the
  divergence is value-compare (not a flag — the `#[Fillable]` init trap rules out the
  flag, same as the serialization tier).
- **What it stacks with:** nothing on reads (fill isn't on the read path); on writes it
  sits beside Tier 2, but `setAttribute` — already greased — dominates `fill()`, leaving
  this as the ~2% leftover.
- **Worth it?** **No, not as a headline; park it next to the hidden/visible tier (item
  #5) and the "NOT worth it" item #8.** The fillable-list win is noise; the guarded-list
  win is real but small and only benefits the model shape Laravel discourages. Build it
  only if (a) a guarded-list-heavy *write* workload is an explicit target, or (b) it's
  bundled as a pure completeness item with the measured numbers stated honestly — never
  sold as a perf headline. The portfolio thesis ("marginal in isolation, compounds
  bundled") still applies, but this one is marginal even by that standard.
