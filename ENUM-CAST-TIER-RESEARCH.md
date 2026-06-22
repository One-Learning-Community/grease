# Enum / custom-class / encrypted cast flyweights — research (NOTES.md open item #3)

Verdict up front: **build the enum half; park the class-castable and encrypted halves.**
Enum casts are the only one of the three with a real, parity-safe, recurring win.
Measured: a greased enum `castAttribute` drops **2.30 µs → 0.99 µs (−57%, ~1.3 µs/op
saved)**, and a hydrate+read of an enum column drops **~1.4 µs/row** — the same
order as the events-dispatcher tier (1.5 µs/row), and it stacks the same way. The
win is **read-path only**: it accelerates `castAttribute` (so reads *and* the
`toArray` storable-enum step ride on it), but does **nothing** for dirty-tracking —
enums never call `castAttribute` on the dirty path. Class-castable reads are already
object-cached after first access and encrypted reads are dominated by decryption, so
flyweighting either is risk for ~0 gain. The whole tier is a **~4-line addition to
the existing `HasGreasedCasts::castAttribute`** plus one static map — no comparator
helpers, no new override surface.

All line refs are against the framework fork at `../../framework` (Laravel **13.16.1**,
`13.x`) as read on 2026-06-21, cross-checked against `v11.44.2` and the 12.x history.

---

## The bottleneck — what a deferred cast costs today

`HasGreasedCasts::castAttribute` (`src/Concerns/HasGreasedCasts.php:34`) resolves a
built-in flyweight and, for everything else, hands back to the framework:

```php
// src/Concerns/HasGreasedCasts.php:42
$caster = static::$greaseCasters[$castType] ??= $this->greaseBuildCaster($castType);

if ($caster !== null) {
    return $caster->get($this, $key, $value, $this->attributes);
}

// enum / custom-class / encrypted — outside the built-in subset; defer to
// the framework's flyweight-free handling (still correct, just unaccelerated).
return parent::castAttribute($key, $value);
```

For an **enum** key, `greaseBuildCaster($castType)` returns `null` (the `match` has no
arm for a class-name cast type — `HasGreasedCasts.php:73`), so it falls to
`parent::castAttribute`. That re-walks the *entire* vanilla dispatch a second time:

```php
// framework HasAttributes.php:848
protected function castAttribute($key, $value)
{
    $castType = $this->getCastType($key);                 // (2nd time — greased already called it at :35)

    if (is_null($value) && in_array($castType, static::$primitiveCastTypes)) {
        return $value;
    }

    if ($this->isEncryptedCastable($key)) {               // hasCast() -> getCasts() + in_array
        $value = $this->fromEncryptedString($value);
        $castType = Str::after($castType, 'encrypted:');
    }

    switch ($castType) {                                   // 14 arms, all miss for an enum class name
        case 'int': ... case 'timestamp': ...
    }

    if ($this->isEnumCastable($key)) {                     // getCasts() + primitive check + Castable check + enum_exists()
        return $this->getEnumCastableAttributeValue($key, $value);   // the only line that does real work
    }

    if ($this->isClassCastable($key)) { ... }
    return $value;
}
```

The actual enum conversion is `getEnumCastableAttributeValue` (`HasAttributes.php:952`),
which is cheap:

```php
// framework HasAttributes.php:952
protected function getEnumCastableAttributeValue($key, $value)
{
    if (is_null($value)) { return; }
    $castType = $this->getCasts()[$key];
    if ($value instanceof $castType) { return $value; }
    return $this->getEnumCaseFromValue($castType, $value);   // backed: $class::from($value); pure: constant("$class::$value")
}
```

So for an enum read, ~everything between the two `castAttribute` entries is pure
ceremony: a **second** `getCastType`, an `isEncryptedCastable` (`hasCast`→`getCasts`),
a 14-arm `switch` that misses, and an `isEnumCastable` (`getCasts` + `enum_exists`).
The conversion itself (`$class::from($v)`) is ~0.14 µs; the ceremony around it is the
other ~1.3 µs.

Enum reads are **not** object-cached: `classCastCache` (`HasAttributes.php:926`) only
caches *class-castable* objects, never enums. So **every** read of an enum attribute —
and the `toArray` pass — re-runs `castAttribute` in full. The ceremony is paid every
single time, not just on first access.

---

## What's class-pure vs recomputed

| Fact | Status today | Note |
|---|---|---|
| "is this key an enum?" (`isEnumCastable`) | recomputed every `castAttribute` | class-pure; `getCasts()` + `enum_exists()` per call |
| `getCastType($key)` | **already cached** in framework `static::$castTypeCache` (`HasAttributes.php:977`) | computed twice per greased enum read (greased + parent), but each is a cache hit |
| the enum conversion `$class::from($v)` | genuinely per-value | not cachable — depends on the stored scalar |
| `getCasts()` inside the re-walk | memoized by Tier 2 (`HasGreasedAttributes::getCasts`) | the un-memoized `array_merge` cost is already banked by grease |

The recompute worth removing is **"is this key an enum, and route straight to its
conversion"** — a class-pure (per cast-type) fact. Everything the conversion itself
needs is already cheap or already cached.

---

## Measurement (throwaway micro-bench, hrtime, best-of-7, interleaved)

Booted via `BootsEloquent::capsule()` on the shared `SampleData::row()` fixture
(`status_val => Status::class`, a backed string enum). All three `castAttribute`
variants invoked through the **same** `ReflectionMethod::invoke` harness so the
comparison is fair; the prototype is the parity-safe design below (delegates to the
framework's own `getEnumCastableAttributeValue`).

```
castAttribute(enum) vanilla           3.41 µs
castAttribute(enum) greased now       2.30 µs      (defers to parent::)
castAttribute(enum) PROTO flyweight   0.99 µs      (skip the re-walk, delegate conversion)

Marginal win of the tier (greased-now -> proto): 1.31 µs/op (-57%)

hydrate+getAttribute(status): vanilla 28.4   greased-now 14.5   proto 13.1 µs
  proto vs greased-now: ~1.4 µs/row saved
```

Three honest framing points:

1. **vanilla→greased on this column is mostly *not* this tier.** greased-now is
   already 2.30 vs vanilla 3.41 µs *while still deferring to parent* — that gap is
   Tier 2 (`getCasts()` memoized so the re-walk's three `getCasts()` calls are cheap),
   not enum-specific. The honest marginal number for **this** tier is greased-now →
   proto = **1.31 µs/op**, not vanilla → proto.
2. **Guard cost is ~nil.** The fast path is one extra `static::$map[$castType] ??=
   enum_exists($castType)` lookup (memoized after first) and one `getEnumCastable...`
   call. The prototype already pays it and still lands at 0.99 µs.
3. **`:memory:` does not apply here** — this is a pure per-op CPU number, DB-independent.

Per-op the win is real but **small in absolute terms** (~1.3 µs), an order of
magnitude under the date tier's ~27 µs/column. Its case is *frequency*: enum casts are
everywhere in real apps (status/role/type columns), read on every row and re-cast on
every access, so the µs accumulate across a collection the way the events tier does.

---

## Proposed design — extend `HasGreasedCasts::castAttribute` (enum only)

The existing tier already does the right thing structurally: it keys off the **live**
`getCastType($key)` (which reflects runtime `withCasts`/`mergeCasts` via Tier 2's
divergence-aware `getCasts()`), then dispatches. Add one branch *between* the
flyweight lookup and the `parent::` fallback:

```php
protected function castAttribute($key, $value)
{
    $castType = $this->getCastType($key);

    if (is_null($value) && in_array($castType, static::$primitiveCastTypes, true)) {
        return $value;
    }

    $caster = static::$greaseCasters[$castType] ??= $this->greaseBuildCaster($castType);

    if ($caster !== null) {
        return $caster->get($this, $key, $value, $this->attributes);
    }

    // NEW: enum fast path. Keyed by the *resolved* cast type (a class name), exactly
    // like $greaseCasters — so it is divergence-safe for free: a runtime-added cast
    // changes getCastType($key), which selects a different map entry. Delegates the
    // actual conversion to the framework so backed/pure/instanceof/null handling
    // stays byte-identical; we only skip the re-walk ceremony.
    if (static::$greaseEnumTypes[$castType] ??= enum_exists($castType)) {
        return $this->getEnumCastableAttributeValue($key, $value);
    }

    // custom-class (CastsAttributes) + encrypted still defer — correct, unaccelerated.
    return parent::castAttribute($key, $value);
}
```

with one new static beside `$greaseCasters`:

```php
/** Per-process map: resolved cast type -> is it an enum. Keyed by type, not class. */
protected static array $greaseEnumTypes = [];
```

Why this shape:

- **Probe-free, because the conversion is *delegated*, not reimplemented.** Unlike the
  serialization tier (which had to probe-certify because it rewrites the output
  string), here the fast path calls the framework's own `getEnumCastableAttributeValue`
  — so the *output is the framework's output*. There is nothing to certify; parity is
  structural. We only remove dispatch overhead, never compute the value ourselves.
- **Keyed by `$castType`, not `[$class][$key]`.** This is the same discipline as
  `$greaseCasters` and is the reason it's divergence-safe with zero extra machinery:
  `getCastType($key)` already returns the live type, so a `withCasts(['x' => SomeEnum])`
  on one instance simply makes that call return `SomeEnum::class`, hits the enum branch
  correctly, and a `withCasts(['x' => 'int'])` makes it return `'int'` and hit the
  flyweight. No per-instance flag needed; `$greaseCastsDiverged` is irrelevant to this
  branch.
- **`enum_exists($castType)` is the exact predicate the framework uses** to decide enum
  vs not (`isEnumCastable`, `HasAttributes.php:1829`), minus the primitive/Castable
  pre-checks — but those can't be true here: a primitive type would have matched a
  flyweister or returned a null-primitive earlier, and a `Castable` class returns
  `false` from `enum_exists`. So the predicate is equivalent on the reachable set.
- **Zero new override surface.** No new public methods, no new trait. `getEnumCastable
  AttributeValue` is `protected` and the trait is composed into the model, so `$this->`
  reaches it.

### Why NOT class-castable, and why NOT encrypted (the two parked halves)

- **Class-castable (`CastsAttributes`) read** is already object-cached:
  `getClassCastableAttributeValue` (`HasAttributes.php:920`) stores the resolved object
  in `$this->classCastCache[$key]` and returns it on every subsequent read (unless
  `withoutObjectCaching`). So the only un-cached cost is the *first* read, and even that
  routes through `resolveCasterClass` (itself cached). A flyweight would save the
  re-walk on the first read of each key only — and it would have to faithfully reproduce
  the caching protocol (`CastsInboundAttributes`, `withoutObjectCaching`, the
  cache-evict-on-non-object branch). High parity surface, ~0 recurring gain. **Park.**
- **Encrypted read** is `fromEncryptedString($value)` — a real decryption — then a
  re-dispatch on the inner type (`Str::after($castType, 'encrypted:')`,
  `HasAttributes.php:862`). The crypto dominates by 1–2 orders of magnitude; shaving the
  dispatch ceremony is noise against it, and reproducing the decrypt-then-recast is the
  most error-prone path in the file. **Park.** (Grease already documents the
  `isEncryptedCastable`-override narrowing; leave encrypted fully on `parent::`.)

---

## The comparator / dirty-path investigation (the explicit ask)

**Finding: a read flyweight cannot help the dirty path, and the dirty path is a
version trap — so don't touch it.**

Enum dirty-tracking does **not** call `castAttribute`. `getDirty()` →
`originalIsEquivalent($key)` (`HasAttributes.php:2350`) compares the **raw stored
scalars** in `$this->attributes` / `$this->original`. For an enum, the stored value is
the backing scalar (`getStorableEnumValue`, written on set — `HasAttributes.php:1299`),
so an unchanged enum is caught by the `$attribute === $original` early-return
(`:2359`) or the final `is_numeric`/`strcmp` fallback (`:2394`). No enum branch, no
cast. Micro-bench confirmed: `originalIsEquivalent(enum, unchanged)` = ~1.1 µs and never
enters `castAttribute`. **Flyweighting the cast READ buys the dirty path nothing** —
state this plainly in any future README note.

**`isClassComparable` / `compareClassCastableAttribute` are version-fragile.** They were
introduced in **PR #55945**, first tagged **v12.18.0**:

```
cf9cede289 [12.x] feat: Make custom eloquent castings comparable for more granular isDirty check (#55945)
  -> first tag: v12.18.0
```

They are **absent in all of Laravel 11** (verified against `v11.44.2`: its
`originalIsEquivalent` has the `AsArrayObject`/`AsEnumArrayObject`/`AsEncrypted*`
branches and then the `is_numeric` fallback — **no** `isClassComparable` branch) and in
**v12.0–12.17**. Grease's matrix is `^11 || ^12 || ^13`. So **any** tier that called
`isClassComparable`/`compareClassCastableAttribute` directly — as the rejected 14.x
cast-objects design's comparators do — would **fatal on L11 and early-L12**. This is
exactly NOTES #3's warning ("may not exist on vanilla Eloquent — port carefully and
self-contained").

The clean consequence: **the dirty/comparator axis is out of scope for this tier.** We
accelerate reads only and never override `originalIsEquivalent`, so the comparator
helpers' absence on L11 is irrelevant — we never reference them. (If a future tier
*did* want to optimize class-castable dirty checks, it would have to `parent::
originalIsEquivalent` and let the framework decide whether `isClassComparable` exists —
never call it directly.)

### Paths a flyweight accelerates vs doesn't (be explicit)

| Path | Helped by enum flyweight? | Why |
|---|---|---|
| `getAttribute` / `transformModelValue` → `castAttribute` (read) | **Yes** | direct route, ~1.3 µs/read saved |
| `attributesToArray` → `addCastAttributesToArray` → `castAttribute` then `getStorableEnumValue` (`HasAttributes.php:322,348`) | **Yes** (the cast half) | the cast step rides the fast path; the storable-enum step is unchanged |
| `getDirty` / `isDirty` → `originalIsEquivalent` | **No** | enum dirty is a raw-scalar compare; never calls `castAttribute` |
| `setAttribute` → `setEnumCastableAttribute` (`HasAttributes.php:1292`) | **No** | write path; not `castAttribute`; out of scope |

---

## Parity risks + defer cases (every one, and how it stays correct)

The bar here is **byte-/value-identical output** (same as the cast tier), and it's
upheld *structurally* by delegating the conversion to the framework. Enumerated:

1. **Backed vs pure (unit) enums.** Handled by `getEnumCastableAttributeValue` →
   `getEnumCaseFromValue` (`:1314`): `is_subclass_of($class, BackedEnum::class) ?
   $class::from($v) : constant("$class::$v")`. We call it verbatim — both kinds correct.
2. **Value already an enum instance** (e.g. re-cast of a hydrated-then-set value).
   `getEnumCastableAttributeValue` short-circuits `if ($value instanceof $castType)
   return $value` (`:960`). Preserved.
3. **Null value.** `getEnumCastableAttributeValue` returns `null` for null (`:954`).
   Matches `parent::castAttribute`'s flow (enum isn't a `$primitiveCastTypes` member, so
   the top-of-method null short-circuit doesn't fire for it in either path). Preserved.
4. **Invalid scalar** (`Status::from('bogus')` → `ValueError`). We delegate, so the same
   `ValueError` is thrown from the same place. Identical failure behaviour.
5. **Runtime divergence** (`withCasts`/`mergeCasts` adding/removing/overriding an enum
   cast). The branch keys off live `getCastType($key)`, which reflects Tier 2's
   divergence-aware `getCasts()`. A diverged instance therefore selects the right branch
   per its live casts; the per-class `$greaseEnumTypes` map is keyed by *resolved type*,
   not by `[class][key]`, so it can never serve a stale answer for a key. (Same safety
   argument that already makes `$greaseCasters` divergence-safe.)
6. **STI subclasses** with different enum casts. `getCastType` returns each subclass's
   live type; `$greaseEnumTypes` is keyed by the type string (shared, correct — an enum
   class either exists or doesn't, regardless of which model uses it). No `static::class`
   leakage concern because the map's key domain is the type, not the class.
7. **Encrypted-then-enum** (`encrypted:` wrapping). Not reachable: encrypted cast types
   are the literal `encrypted*` strings, `enum_exists` on which is `false`, so it defers
   to `parent::` which decrypts. Untouched.
8. **`AsEnumCollection` / `AsEnumArrayObject`** (enum *collections* via `Castable`).
   These are `class`-castable, not enum-castable: `enum_exists(AsEnumCollection::class)`
   is `false`, so they defer to `parent::` (the class-castable branch). Correct,
   unaccelerated.
9. **`#[preventAccessingMissingAttributes]`** interaction. The missing-attribute throw
   lives in `transformModelValue` *before* `castAttribute` (`HasAttributes.php:2420`),
   not inside it, so this branch never changes that behaviour.

Defer-to-vanilla cases (correct, unaccelerated): class-castable reads, encrypted reads,
enum-collection casts, and anything the flyweisters/enum branch don't claim.

---

## Parity test plan (extend the existing suite)

The suite already covers enums (`CastParityTest`: enum read + type-identity + `toArray`
+ all-null per NOTES; `CastEquivalenceParityTest`: enums-from-differently-typed scalars).
Re-running those green is most of the proof, since the fast path delegates to the same
method. Add, to lock the new branch:

1. **Backed + pure enum fixtures.** `Status` (backed string) exists; add a backed `int`
   enum and a **pure/unit** enum (no backing) to `casts()` — the pure case is the one a
   reimplementation would get wrong, and it proves delegation. Assert greased read,
   `getAttribute`, `attributesToArray`, and `toJson` byte-identical to vanilla.
2. **Already-an-instance read** and **null enum value** vs vanilla.
3. **Invalid value** throws the identical `ValueError` (same message) on both arms.
4. **White-box: the fast path is engaged.** Mirror the Tier 4 tests' approach — assert
   `enum_exists`-keyed entry is populated and that a spy/counter on `parent::castAttribute`
   is *not* hit for the enum key (but IS for a class-castable key on the same model),
   proving acceleration without a behaviour change.
5. **Divergence:** `withCasts(['x' => SomeEnum::class])` then read == vanilla; a diverged
   instance doesn't poison a fresh default instance (port the cast tier's poisoning test).
6. **Dirty unchanged:** an enum set to its current value is *not* dirty, and a changed
   enum *is* — vanilla vs greased — to prove the read flyweight didn't perturb dirty.
7. **CI matrix:** the existing L11 / L12 / L13 legs are the guard that we never reference
   `isClassComparable` etc. (we don't) — keep them, and add the new enum fixtures so all
   legs exercise the branch. A `prefer-lowest` leg (NOTES shipping checklist) would also
   pin v12.0 where the comparators are absent, confirming nothing in this tier needs them.

## Bench plan

Add a paired `enum` subject to `CastBench` over a fixture with 1–2 enum columns (the
shared `SampleData` already has `status_val`), reading the enum on a fresh hydrate. Gate
on the **marginal** number (greased-with-tier vs greased-without), not vanilla vs
greased, so the report isn't inflated by Tier 2's already-banked `getCasts()` memo. The
honest expected delta is **~1.3 µs/enum-read**; if a future change pushes it into rstdev
noise, that's a legitimate park signal — record the number either way. (The existing
`CastBench` `read`/`toArray` subjects will also move slightly once the tier lands, since
`status_val` is in the mixed fixture.)

---

## Honest verdict

- **Expected magnitude:** small per-op (**~1.3 µs/enum-read, −57% of that cast op**),
  read-path only. An order of magnitude under the date tier (~27 µs/column); the same
  ballpark as the events tier (~1.5 µs/row). Its leverage is *frequency* — enum columns
  are ubiquitous and re-cast on every access — so across a collection it accrues like
  the events tier does, not like a headline.
- **Risk:** **low.** The conversion is *delegated* to the framework, so output is
  identical by construction (no probe needed, unlike serialization). The one trap — the
  comparator helpers missing on L11/early-L12 — is **side-stepped entirely** by not
  touching the dirty path. Surface is ~4 lines + one static, mirroring the existing
  flyweight discipline (keyed by resolved type → divergence-safe for free).
- **What it stacks with:** rides on top of Tier 2 (`getCasts` memo) and Tier 1
  (hydration); compounds with the events tier as another "shave the dispatch ceremony"
  per-row win. Pure portfolio-thesis material ("marginal in isolation, compounds
  bundled").
- **Worth it?** **Yes for enums** — low risk, closes the most common deferral, real if
  modest, and it lets the README stop saying "enum casts defer to vanilla, unaccelerated."
  **No for class-castable and encrypted** — class-castable reads are already object-cached
  (gain ≈ first-read only, against a high caching-protocol parity surface) and encrypted
  reads are decryption-bound (dispatch shave is noise). Ship the enum branch; leave the
  other two deferred and documented as such.
```
