# Serialization tier — research (NOTES.md open item #5)

Verdict up front: **real but small, and narrowly scoped.** It only touches models
with `$hidden`/`$visible` set — but those (User, anything with `password`) dominate
API responses, so the real-world reach is wide even though the per-op delta is thin.
Worth building *only* after adding hidden/visible fixtures and confirming the bench
shows a delta above noise. Low risk if done with the value-compare design below.

All line refs are against the framework fork at `../../framework` as read on 2026-06-21.

---

## What actually happens on `toArray()`

`Model::toArray()` (`Model.php:2008`) = `attributesToArray()` + `relationsToArray()`,
wrapped in `withoutRecursion`. The serialization-only redundancy lives in **one**
method:

```php
// HasAttributes.php:447
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

`getVisible()`/`getHidden()` (`HidesAttributes.php:42,82`) just return `$this->visible`/
`$this->hidden`. The `array_flip` is the per-call recomputation of a class-pure fact.

It's called from three sites per `toArray()`:

| caller | site | runs when |
|---|---|---|
| `getArrayableAttributes()` | `HasAttributes.php:364` | always |
| `getArrayableAppends()` | `HasAttributes.php:374` | only if `$appends` non-empty (guarded at :378) |
| `getArrayableRelations()` | `HasAttributes.php:436` | always (even when `$relations` is empty) |

So **per `toArray()`, with hidden-only and no appends (the typical User model):
2× `array_flip($hidden)`** — once for attributes, once for relations (the relations
call still flips even when no relations are loaded, because the guard is on
`count($hidden)`, not on `$values`). With both `$visible` and `$hidden` set and
appends present, it climbs to the "×3 / up to 6 flips" the blueprint cites. Across a
100-row collection `->get()->toArray()` that's ~200 throwaway flips of tiny arrays.

## What is NOT redundant here (important — narrows the win)

- **`getMutatedAttributes()` is already class-cached upstream** (`$mutatorCache[static::class]`,
  `HasAttributes.php:2522`). The blueprint's "Tier 3a = flipped hidden/visible **+ mutated
  list**" overstates it — core already memoizes the mutated list. No grease win there.
- **`getCasts()` / `getDates()`** in `addCastAttributesToArray`/`addDateAttributesToArray`
  are already memoized by **Tier 2** (`HasGreasedAttributes`). Already counted in the
  existing `toArray −20%`.
- **`getAppends()`** (`:2461`) just returns `$this->appends` — no flip, nothing to cache.

**Net: the entire serialization tier reduces to caching `array_flip($hidden)` and
`array_flip($visible)` per class.** That's the whole prize. Don't let the blueprint's
phrasing inflate the scope.

## Why the fixtures currently show nothing

`VanillaSample`/`GreasedSample` set no `$hidden`/`$visible`. With both empty,
`getArrayableItems` hits neither `if` and returns `$values` untouched — zero flips.
So today this tier would benchmark at exactly 0%. **Prerequisite: add hidden/visible
to the shared fixtures** (e.g. `$hidden = ['hashed_val']`, or a `$visible` whitelist)
so the bench exercises a path that exists. Keep the vanilla/greased pair identical
except the trait, per the fixture convention in CLAUDE.md.

---

## The correctness trap: runtime divergence

`$hidden`/`$visible` are mutable per-instance after construction:

- `setHidden` / `setVisible` (`HidesAttributes.php:53,93`)
- `makeHidden` / `makeVisible` (`:154,123`) — and `makeHiddenIf`/`makeVisibleIf`,
  which route through them (`:170,143`)
- `mergeHidden` / `mergeVisible` (`:66,106`)

Any cache keyed by class must not serve a stale flip to an instance that mutated its
visibility. This is the same shape as the Tier-2 cast divergence guard — **but with a
sharp extra wrinkle that rules out the obvious flag approach:**

**`initializeHidesAttributes()` (`HidesAttributes.php:30`) calls `mergeHidden(...)` /
`mergeVisible(...)` during *every* construction** to apply the `#[Hidden]`/`#[Visible]`
PHP attributes (Laravel 11+). A classic `protected $hidden = [...]` model merges `[]`
(early-returns, no change), but a `#[Hidden]`-attribute model performs a *genuine*
`[] → ['x']` change on every instance. A `mergeCasts`-style before/after flag would
therefore mark **every** `#[Hidden]` instance "diverged" at birth and the cache would
never populate. So the casts tier's exact pattern does not transplant cleanly.

## Recommended design — value-compare, not a divergence flag

Override the single chokepoint and compare the live array against a captured baseline
instead of tracking mutation. Cheaper than `array_flip` (an `===` on a 2–5 element
array is allocation-free), robust against *every* mutation path including `#[Hidden]`
and any future one, and adds **zero** new override surface beyond this one method:

```php
// HasGreasedSerialization (new Tier 4 concern), uses InteractsWithGreaseBlueprint
protected function getArrayableItems(array $values)
{
    if (($visible = $this->getVisible()) !== []) {
        $values = array_intersect_key($values, $this->greaseFlip('visibleFlip', $visible));
    }
    if (($hidden = $this->getHidden()) !== []) {
        $values = array_diff_key($values, $this->greaseFlip('hiddenFlip', $hidden));
    }
    return $values;
}

private function greaseFlip(string $slot, array $list): array
{
    $bp = &static::$greaseBlueprint[static::class];
    // First non-diverged instance seeds the baseline; matches reuse it.
    if (! isset($bp[$slot])) {
        $bp[$slot] = ['src' => $list, 'flip' => array_flip($list)];
    } elseif ($bp[$slot]['src'] === $list) {
        return $bp[$slot]['flip'];
    } else {
        return array_flip($list); // diverged instance — compute locally, don't pollute
    }
    return $bp[$slot]['flip'];
}
```

Properties:
- **No regression for the no-hidden majority** — two `!== []` checks, same as vanilla's
  two `count() > 0` checks, then early return. (Most models hit this; the tier is a
  no-op for them, which is correct.)
- **Always correct.** A diverged instance computes its own flip and never reads/writes
  the class slot. Worst case (first-ever serialized instance is a mutated one) seeds a
  wrong baseline → default instances then mismatch and fall to the local-flip path =
  still correct, just uncached. Self-corrects toward correctness, never toward a wrong
  result.
- **STI-safe / Octane-safe** for free: it lives in `$greaseBlueprint[static::class]`,
  cleared atomically by the existing `flushGreaseBlueprint()` / `clearBootedModels()`.

### Alternative considered — divergence flag (rejected)
Mirror `mergeCasts`: a `$greaseHidesDiverged` flag set by overriding the six mutators,
`getArrayableItems` `??=` the flip when not diverged else `parent::`. Rejected because
the `initializeHidesAttributes` merge flags `#[Hidden]`/`#[Visible]` models at birth
(see trap above), silently disabling the tier for a documented feature, and it costs
six method overrides vs one. Value-compare is strictly simpler and more robust.

---

## Parity test plan (the spine — must stay byte-identical)

Add to the suite before trusting any number:

1. **Fixtures:** a `$hidden`-only pair, a `$visible`-only pair, and a both-set pair —
   greased vs vanilla, identical but for the trait.
2. **Equivalence:** `toArray()`, `attributesToArray()`, `relationsToArray()`, and
   `toJson()` byte-identical to vanilla for each fixture (with and without an
   eager-loaded relation, since relations route through `getArrayableItems` too).
3. **Divergence:** after `makeHidden`, `makeVisible`, `setHidden`, `setVisible`,
   `mergeHidden`, `mergeVisible`, and `makeHiddenIf`/`makeVisibleIf` — output matches
   vanilla, **and** a mutated instance does not poison the class baseline for a fresh
   default instance serialized afterward (the cast tier's "doesn't poison the cache"
   test, ported).
4. **`#[Hidden]`/`#[Visible]` attribute model** (L11+) — confirm the tier still
   accelerates it (the whole reason for value-compare over the flag).
5. **Appends interaction:** a model with `$appends` + `$hidden` — appends flow through
   `getArrayableItems` via `array_combine($appends,$appends)`; confirm hidden still
   filters appended keys identically.

## Bench plan

Add a `toArray` subject over a hidden-bearing fixture to `CastBench` (paired
vanilla/greased), and extend `realworld.php`'s `index_users` to a model with realistic
`$hidden`. **Gate on measured delta:** if it's inside `rstdev` noise, this tier is not
worth shipping and that's a legitimate outcome — record the number either way.

---

## Bottom line / recommendation

1. The optimization is sound and the design above is low-risk and self-contained.
2. The honest expected magnitude is **small** — two avoided small-array flips per model
   — sitting on top of the already-banked `toArray −20%`. Its value is *completeness*
   (closing the last class-pure recompute on the serialization path) and *reach* (every
   hidden-bearing User model in an API), not a headline number.
3. **Do not ship blind.** Add hidden/visible fixtures, wire the bench, and only commit
   the tier if the measured delta clears noise. If it doesn't, this belongs next to
   item #8 ("NOT worth it") with the number that proves it.
