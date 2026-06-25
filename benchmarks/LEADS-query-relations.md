# Grease — Query Builder & Relations research leads

**Date:** 2026-06-24 (Linux-confirmed 2026-06-25) · **Method:** three parallel agents grounded in
the live framework source (`/Users/serpentblade/work/framework`, Laravel 12/13, PHP 8.2+), Grease
spine — measure-first, byte-/behaviour-identical, opt-in, honest-verdict. Magnitudes below are
**Linux-confirmed on the NAS** (`benchmarks/docker/Dockerfile.nas`, php:8.4-cli + opcache + tracing
JIT), not macOS ratios.

**Prompt:** "Query builder already pored over? Relations?" Swept three surfaces that the prior
Eloquent and foundation digs had not: the base Query Builder + SQL grammar, the Eloquent query
builder dispatch, and Relations.

## The structural lens (frames all three)

Grease wins where hot work is a **within-request multiplier** (per-row, per-attribute, per-identifier).
The SQL-compilation and builder-dispatch layers are mostly **O(1)-per-query**, and a query is
~O(1)-per-request — structurally thin, like the request spine in [LEADS.md](LEADS.md). The one place a
true per-row multiplier hid was **Relations → the BelongsToMany pivot**, never profiled before because
the prior eager exercise only covered HasMany/BelongsTo.

---

## ✅ SHIPPED

### HEADLINE — Greased pivot model (`feat(pivot)`, commit 1deca29)
The pivot of a many-to-many is a "dynamic model" the framework hydrates per related row and it
**never carries Grease's tiers** — so a pivot-heavy `belongsToMany()->get()` pays, per pivot row, the
exact per-row taxes the model tiers remove (the `initialize*` booters, `resolveClassAttribute` — the
#1 eager frame — and the timestamp Carbon round-trip on `withTimestamps()`). `Grease\Eloquent\Pivot`
(a `Pivot` + `HasGrease`) + `HasGreasedPivots` (overrides the related model's `newPivot()`), folded
into `HasGrease`. Carve-outs (byte-identical defers): `using(CustomPivot)` and `MorphToMany` (builds
MorphPivot on the relation, bypassing the model seam). **A/B `pivot_ab.php`: −75.7% on 1,000 pivot
rows (Linux/NAS; −69% macOS).** The one model class Grease structurally couldn't reach until now — the real
winner of this sweep. The ICP shape (roles/permissions/tags m2m) on an API.

### RIDER — `wrapTable()` memo (`perf(grammar)`, commit 185e502)
The shipped `MemoizesWrappedIdentifiers` only covered `wrap()`; `wrapTable()` is a distinct pure
transform (own alias/schema/prefix walk) run for `from`/joins/insert/update/delete, same key domain
(table string + connection prefix) and same prefix-flush invalidation. Memoized for the default-prefix
string case; Expression + explicit-prefix defer. Sub-µs/query in FPM, compounds under Octane.

### RIDER — Eloquent builder `__call` verdict memo (`perf(builder)`, commit 2339f36)
`Eloquent\Builder` lets most verbs fall through `__call`, re-resolving the scope/passthru/forward
verdict every call (a `hasNamedScope` probe + a 32-element `in_array(strtolower(...))` scan). The
greased builder memoizes the verdict per (model class, method) — immutable, class-pure, never
invalidated. Macros re-probed live (can't be shadowed). Seam: `HasGreasedQueries` →
`Grease\Database\Eloquent\Builder`, only the default builder greased (custom builder attribute /
`static::$builder` honoured). **Standalone per-model opt-in — deliberately NOT bundled into
`HasGrease`** (its app-wide builder swap is disproportionate to a sub-0.1%-of-a-request gain; see
the verdict below). **Honest scope correction:** `where`/`orWhere`/`latest`/`oldest`/`whereNot`
are DEFINED on Eloquent\Builder and bypass `__call`, so the memo helps the *other* forwarded verbs
(orderBy/whereIn/select/limit/groupBy/having/…), not `where()`. A/B `builder_call_ab.php`: −7.4%
Linux/NAS (−9.8% macOS) on a 7-forwarded-verb chain (pure dispatch); low single digits once SQL +
hydration dominate.

---

## 🔴 Negatives ledger — investigated, ruled out, do not re-chase

- **SQL compilation (compileSelect/compileWheres/components/binding flatten):** thin-after-wrap. Once
  identifier wrapping is memoized, every remaining cost is O(1)-per-query (Collection allocs, dynamic
  dispatch, the binding loop) and dwarfed by the PDO round-trip. `Processor::processSelect` is a literal
  `return $results;`. No tier.
- **Cross-query compiled-SQL cache (fixed-shape hot endpoints):** the one structurally-interesting
  lever, but the structural fingerprint key costs ~the compile it would save (key-as-expensive-as-the-miss),
  and the failure mode is **silent data corruption**, not a perf regression. Octane-only experiment at best;
  prototype key-cost-vs-miss-cost before writing a line. Default: don't chase.
- **Eloquent builder once-per-query work** (`newQuery`/`newEloquentBuilder` construction, `applyScopes`,
  `paginate`'s count-clone): O(1)-per-query or round-trip-bound. The `resolveCustomBuilderClass`
  reflection that looked Grease-shaped is already PHP-internally cached (~0.04µs). No tier beyond the
  `__call` memo.
- **Eager-load matching** (`Relation::match`/`buildDictionary`/`getDictionaryKey`): re-confirmed THIN
  across both the prior HasMany profile and a fresh BelongsToMany profile — `buildDictionary` is not in
  the top ~30 self-time frames. Verdict from NOTES upheld; the dominant eager cost was always model/pivot
  construction. Do not reopen.
- **Relation-object construction per access** (`$model->relation()` builds a fresh Relation + related
  instance + `addConstraints`): ~2.5µs/call but NOT a within-request multiplier — the lazy read path
  caches into `$this->relations` after first access, so repeated `$model->posts` is O(1). Freezing the
  class-pure parts is hard (the constructor wires a stateful query builder). Octane-leaning, low
  confidence; parked.
- **`migratePivotAttributes` full-attribute scan** (per pivot row): real but ~1.4% standalone; rides the
  greased pivot, not worth a separate slice/unset reimplementation.
- **Pivot write path / MorphTo bucketing / HasManyThrough joins / withDefault / aliasedPivotColumns:**
  once-per-call or once-per-query, IO/SQL-bound, or O(1) — thin machinery, no per-row multiplier.

---

## Bottom line

The query/builder layers are **thin-after-wrap** (the structural prediction held); the sweep's real
prize was the **pivot**, a clean reuse of the proven model tiers on a class Grease couldn't previously
touch. Two cheap byte-identical riders (`wrapTable`, `__call` memo) shipped alongside. Everything else is
in the negatives ledger.

**Linux-confirmed (NAS, 2026-06-25):** pivot −75.7% (1k rows), `__call` −7.4% (pure dispatch), full
parity suite 583/583 green on Linux. `wrapTable` has no standalone A/B (sub-µs free rider on the
shipped grammar memo). Numbers trustworthy; remaining follow-up is release hygiene (CHANGELOG/README/
phpbench) + merge.
