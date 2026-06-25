# How It Works

## One pattern, eight tiers

Every Grease tier is the same shape: a **method override that reads a single
per-class "blueprint" and falls back to `parent::`** for anything it doesn't
accelerate.

```php
// the shape of every tier, sketched
public function getCasts()
{
    if ($this->greaseCastsDiverged) {
        return parent::getCasts();           // runtime mutation → defer to vanilla
    }

    return static::$greaseBlueprint[static::class]['casts']
        ??= parent::getCasts();              // compute once per class, reuse forever
}
```

The blueprint (`static::$greaseBlueprint[$class]`) is one static, keyed by class,
holding every class-pure fact the tiers memoize. It builds lazily on first use and
invalidates as a unit. Two things make this fast *and* safe:

- **No "is the cache on?" branch.** A greased model always takes the fast path; a
  non-greased model never sees Grease at all. That missing per-access branch is the
  difference between this and the rejected core-patch version — the branch *was* the
  tax.
- **Keyed by `static::class`.** Single-table-inheritance subclasses with different
  casts never share a blueprint entry. Each class memoizes its own facts.

Non-greased models run pure vanilla Eloquent. Grease adds **zero** cost to anything
that doesn't use it.

## The eight model tiers

| Tier | What it computes once instead of every time |
| --- | --- |
| **`HasGreasedHydration`** | Kills the per-row `ReflectionClass`, the per-instance casts rebuild, and `newInstance`'s redundant work during hydration — plus an empty-fill short-circuit: `fill([])` (run by `__construct` on every hydrated row) is a pure no-op, but vanilla still computes `totallyGuarded()` up front. Returning early on `[]` deletes that frame byte-identically. **−5.2% on the eager `get()`.** |
| **`HasGreasedAttributes`** | Memoizes `getCasts` / `getCastType` / `getDates` / mutator probes / `getDateFormat` — the class-pure metadata Eloquent re-derives constantly. |
| **`HasGreasedClassAttributes`** | Caches `resolveClassAttribute` (the `#[Table]` / `#[Fillable]` / `#[Hidden]` / `#[Appends]` / … lookups the per-instance `initialize*` booters run ~13× per `new` model) by a concat-free `[class][attribute]` key instead of vanilla's freshly-built `"$class@$attr"` string — **−13% on an eager-load `get()`**. |
| **`HasGreasedInitializers`** | Freezes the four surviving `initialize*` trait booters (guards / hides / timestamps / touches) per class: cold path runs `parent::` once and snapshots the resulting properties; warm instances apply the snapshot by copy, skipping the `resolveClassAttribute` calls entirely. After the cache above made each call cheap, this kills the call *frequency* — `resolveClassAttribute` drops out of the eager profile's top frames. **−8.4% on the eager `get()`, on top of the tier above.** |
| **`HasGreasedCasts`** | Replaces the per-access cast `switch` with a flyweight resolved once per cast type, plus a fast path that delegates enum conversion to the framework. |
| **`HasGreasedCastProbes`** | Memoizes the per-key cast-classification probes (`isEnumCastable` / `isClassCastable` / `isClassSerializable`) that `addCastAttributesToArray` reruns on every row during `toArray()`. The verdict is a pure function of the cast type, like `getCastType` — yet recomputed per row (and `isClassSerializable` re-calls the other two). Vanilla calls them through `$this->`, so the memo lands even inside the delegated `parent::` loop. **−10.2% on a rich-cast `get()->toArray()`** (the `index_users`/`posts_with_author` shape). |
| **`HasGreasedSerialization`** | Eliminates the Carbon parse-and-reformat round-trip for date serialization — when a per-class probe certifies the output is a byte-identical rewrite — on both the read path (`addDateAttributesToArray` / `addCastAttributesToArray`, inside `toArray()`) and the write path (`fromDateTime`, the identity round-trip `getDirty()`/`originalIsEquivalent()` pays on every `save()`: **−38% on a `save()`-heavy dirty-check**). Also short-circuits the `toArray()` circular-recursion guard (a `debug_backtrace` + `Onceable` hash vanilla runs on *every* call) when no relations are loaded — there's nothing to recurse into, so `toArray()` is exactly `attributesToArray()`. **−27% on a relation-less `get()->toArray()`.** |
| **`HasGreasedPivots`** | Greases the many-to-many **pivot** — a "dynamic model" Eloquent hydrates per related row that otherwise carries none of the tiers above, so every pivot row pays the full per-row booter / `resolveClassAttribute` / timestamp round-trip in vanilla. The related model's `newPivot()` returns a greased pivot (a `Pivot` with `HasGrease`) for the default case; a `using()` custom pivot or a `morphToMany` pivot defers to vanilla, byte-identical. **−75% on a pivot-heavy `belongsToMany()->get()`.** |

`HasGrease` is the umbrella that pulls in all eight; `GreasedModel` is the same as an
`extends`-able base class. (`HasGreasedClassAttributes` keeps its cache in a carve-out
static rather than the blueprint — class-level PHP attributes are immutable for a process's
lifetime, so it never needs invalidation, the same reasoning as the `getDateFormat`
connection cache.)

One model-axis tier is **deliberately not bundled**: **`HasGreasedQueries`** memoizes the
Eloquent builder's `__call` dispatch verdict — the scope/passthru/forward decision re-resolved
on every forwarded query verb (`orderBy` / `whereIn` / `select` / …). It's byte-identical and
audited, but it swaps a custom builder in for *every* query on the model app-wide for a gain
that's sub-0.1% of a real request (the dominant `where` / `orWhere` verbs are defined on the
builder and bypass `__call` entirely). That reach isn't worth a default — add
`use HasGreasedQueries;` explicitly on a query-construction-heavy model if you're chasing every
last cycle.

## Two techniques worth understanding

The honesty of "byte-identical output" comes from two recurring patterns.

### Probe-certify (serialization)

Date serialization is the trap: Laravel's default `serializeDate` does **not**
reproduce the stored string. `2026-01-01 00:00:00` becomes
`2026-01-01T00:00:00.000000Z`, with real timezone math. So you can't blind-skip the
round-trip.

Grease runs the model's *real* serialize path once per class and adopts a
Carbon-free rewrite **only when the output is byte-equal** to vanilla — keyed by
timezone and connection so it can never go stale, with a per-value shape guard so
anything unusual (sub-second precision, date-only values, custom formats) quietly
defers to vanilla. Certified or deferred; never guessed.

### Divergence-flag (runtime mutation)

The blueprint freezes class-pure facts — but `withCasts()` / `mergeCasts()` change
casts at *runtime*, per instance. Grease watches for a genuine change and flips a
per-instance flag; a diverged instance simply falls back to the live vanilla path
for casts. The frozen blueprint stays correct for every other instance, and the
mutated one stays correct for itself.

This is why `getCastType()` could be memoized safely: it's a pure function of
`getCasts()`, which the divergence flag already guards. Caching it stopped a
string→type re-walk on the hottest path there is — every cast access — with no new
branch and no new caveat.

## Beyond Eloquent

The model trait is one axis of several. Grease also greases [Blade rendering](/guide/blade)
and [view resolution](/guide/view-cache), the [container](/guide/container), the
[request](/guide/request), [config reads](/guide/config), the [router](/guide/routing), and
[validation](/guide/validation) — each an independent opt-in.

The [event dispatcher](/guide/events) is a *different axis* — not a per-model trait
but a drop-in subclass of Laravel's dispatcher you bind as the `events` singleton.
Its parity bar is behavioural (same listeners, order, and return values) rather than
byte-output, and it speeds up dispatch across the whole app.

Next: [Benchmarks →](/guide/benchmarks)
