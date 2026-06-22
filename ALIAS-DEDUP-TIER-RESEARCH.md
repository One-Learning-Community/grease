# Flyweight alias dedup — research (NOTES.md open item #6)

Verdict up front: **trivially cheap, behaviour-identical, and almost entirely
pointless.** Collapsing synonymous cast types to one canonical flyweight key is a
~5-line change with zero behavioural risk (the flyweights are stateless and the
synonym closures are byte-identical). But it's a **memory** optimization, and the
memory it saves is **~1.3 KB per eliminated duplicate, capped at 8 duplicates
process-wide (~10 KB absolute max)** — and only in the pathological case where a
single process touches all 21 distinct cast-type strings. A realistic app saves
**0–3 instances (a few KB, once, ever)**. This is a *tidiness / completeness* item,
not an optimization. Build it only if you want the flyweight table to read cleanly;
**park it** if you're prioritising by impact.

All line refs are against the framework fork at `../../framework` as read on 2026-06-21.

---

## What actually happens today

`HasGreasedCasts::castAttribute` (`src/Concerns/HasGreasedCasts.php:34`) keys the
shared flyweight table by the **converted** cast type returned from
`getCastType($key)`:

```php
// src/Concerns/HasGreasedCasts.php:42
$caster = static::$greaseCasters[$castType] ??= $this->greaseBuildCaster($castType);
```

`$greaseCasters` (`:32`) is a **process-wide static** shared across every greased
model. `greaseBuildCaster` (`:57`) is a `match` whose arms deliberately group
synonyms — `'real', 'float', 'double'` all build the same `fn ($m,$k,$v) => $m->fromFloat($v)`:

```php
'int', 'integer'            => (int) $v
'real', 'float', 'double'   => $m->fromFloat($v)
'string'                    => (string) $v
'bool', 'boolean'           => (bool) $v
'array', 'json', 'json:unicode' => $m->fromJson($v)
'datetime', 'custom_datetime'   => $m->asDateTime($v)
'immutable_custom_datetime', 'immutable_datetime' => $m->asDateTime($v)->toImmutable()
```

The `match` collapses synonyms to one *closure shape*, but the **cache key does
not**. Because `$greaseCasters` is keyed by the raw `$castType` string, a process
that reads a `real` column, a `float` column, and a `double` column builds **three
identical `ClosureCast` instances** under keys `real`/`float`/`double`. Same for the
other groups. That's the duplication NOTES #6 points at.

## What `getCastType` has *already* normalized (this narrows the candidate set)

`getCastType()` (`HasAttributes.php:973`) is not an identity function — it runs each
raw cast string through `static::$castTypeCache` and converts before returning
(`:981-993`):

- `datetime:Y-m-d` / `date:...` → **`custom_datetime`** (`isCustomDateTimeCast`, `:1046`)
- `immutable_datetime:...` → **`immutable_custom_datetime`** (`:1058`)
- `decimal:2` → **`decimal`** (`isDecimalCast`, `:1070`)
- a `class_exists` cast → the class name unchanged (`:987`)
- everything else → `trim(strtolower($castType))` (`:990`)

Two consequences for this tier:

1. **`decimal` is already a single key and is NOT a synonym candidate.** Every
   `decimal:N` column converts to the one key `decimal`; the precision is read
   per-call from `$m->getCasts()[$k]` (`HasGreasedCasts.php:62`), so one stateless
   flyweight already backs all decimal columns at every precision. Nothing to dedup,
   and it must *not* be folded into another group — it carries a parameter.
2. The keys that actually reach `$greaseCasters` are the **converted** types. So the
   live synonym groups (post-conversion strings that map to identical closures) are:

| canonical | synonym keys that reach `$greaseCasters` | closure |
|---|---|---|
| `int` | `int`, `integer` | `(int) $v` |
| `float` | `real`, `float`, `double` | `$m->fromFloat($v)` |
| `bool` | `bool`, `boolean` | `(bool) $v` |
| `json` | `array`, `json`, `json:unicode` | `$m->fromJson($v)` |
| `datetime` | `datetime`, `custom_datetime` | `$m->asDateTime($v)` |
| `immutable_datetime` | `immutable_datetime`, `immutable_custom_datetime` | `$m->asDateTime($v)->toImmutable()` |

Singletons (no dedup possible): `decimal`, `string`, `object`, `collection`, `date`,
`immutable_date`, `timestamp`.

**Distinct raw keys today: 21. Distinct canonical keys: 13. Max duplicates
eliminable: 8** (measured — see below). Note `custom_datetime` /
`immutable_custom_datetime` only ever arise from a *custom-format* cast
(`datetime:Y-m-d`), and on the **read** path Grease serves those the plain
`asDateTime` closure — identical to `datetime`. (The format only matters on
serialization, which is Tier 4's job, not this flyweight's.)

## Parity bar: byte-output, and it's free here

This tier sits inside the **byte-identical** cast read path, so the bar is the
strict one. But the change can't move output, because:

- **The flyweights are stateless.** `ClosureCast` (`src/ClosureCast.php:20`) stores
  only the three closures passed at construction; `get()` (`:56`) is
  `if (is_null($value) && $this->nullable) return null;` then defers to the closure,
  which is a `static fn` that reads nothing but its `$m,$k,$v` arguments. No instance
  field is mutated after construction. Sharing one instance across `real`/`float`/
  `double` is therefore observationally identical to three instances — same closure,
  same `nullable=true`, same result.
- **The synonym closures are textually identical** (table above). Collapsing the key
  cannot select a different transform; the `match` already proved synonyms share an
  arm.
- **`decimal` is left alone** — its precision is a per-call read, not flyweight state,
  and it is not folded into any group, so parameterized casts are untouched.
- Grease only ever calls `$caster->get()` here; the `set`/`compare` closures are the
  default passthroughs and are never exercised by this tier, so the `json` vs
  `json:unicode` encode-path difference (which lives on write/serialize, not read) is
  irrelevant to the shared read flyweight.

**Enumerated behavioural changes: none.** Verified that every alias resolves to an
identical transform on the read path.

---

## Measurement (the point of this doc)

Throwaway micro-bench, package autoloader, PHP CLI (`/tmp/grease_mem.php`):

```
One ClosureCast instance:            ~1306 bytes  (steady-state, after warmup)
Distinct raw keys (today, max):      21
Distinct canonical keys (deduped):   13
Max duplicate instances eliminated:  8  process-wide
```

So the **absolute ceiling** on memory recovered by this tier is `8 × ~1306 B ≈
10 KB`, **once per process**, and only if the process actually touches all of
`real`, `float`, `double`, `integer`, `boolean`, `array`/`json:unicode`,
`custom_datetime`, and `immutable_custom_datetime` (i.e. a model deliberately using
every spelling). The flyweights are built **lazily**, only when a cast of that type
is first read — so a typical app that casts, say, `int`, `float`, `bool`,
`datetime`, `array` saves **zero** instances (no synonyms in play) or, if it mixes
`integer` and `int` across models, **one**.

For scale: 10 KB is ~0.0005% of a 2 GB-`memory_limit` worker, and it does not grow
with model count, row count, or request count — it's a fixed, one-time, per-process
table that is already tiny (13–21 entries). There is no per-row, per-instance, or
per-request component to recover. **The honest magnitude is "negligible, bordering on
unmeasurable."**

(The first-instance reading of 9736 B is warmup — class autoload + opcode/closure
bootstrapping — not the marginal cost; the 100-instance run gives the true ~1306 B
marginal figure.)

---

## If built anyway — the implementation (it is genuinely trivial)

One small alias table + one lookup before the cache index. No new override surface,
no blueprint entry (the table is a process-wide static like `$greaseCasters` itself):

```php
// HasGreasedCasts — canonical key folds synonyms onto one shared flyweight.
private const GREASE_CAST_ALIASES = [
    'integer'                   => 'int',
    'real'                      => 'float',
    'double'                    => 'float',
    'boolean'                   => 'bool',
    'array'                     => 'json',
    'json:unicode'              => 'json',
    'custom_datetime'           => 'datetime',
    'immutable_custom_datetime' => 'immutable_datetime',
];

protected function castAttribute($key, $value)
{
    $castType = $this->getCastType($key);

    if (is_null($value) && in_array($castType, static::$primitiveCastTypes, true)) {
        return $value;
    }

    $canonical = self::GREASE_CAST_ALIASES[$castType] ?? $castType;
    $caster = static::$greaseCasters[$canonical]
        ??= $this->greaseBuildCaster($canonical);

    if ($caster !== null) {
        return $caster->get($this, $key, $value, $this->attributes);
    }

    return parent::castAttribute($key, $value);
}
```

Notes:
- Build by the *canonical* type. The `match` in `greaseBuildCaster` already has an
  arm for every canonical (`'float'`, `'int'`, `'bool'`, `'json'`, `'datetime'`,
  `'immutable_datetime'`), so it resolves unchanged — the redundant synonym labels in
  the arms (`'real','double'`, `'integer'`, etc.) become dead-but-harmless and could
  optionally be trimmed.
- **Do not** add `decimal` to the alias map — it is its own canonical and carries a
  parameter resolved at call time.
- The `is_null` short-circuit still tests the *raw* `$castType` against
  `$primitiveCastTypes` (which lists every synonym), so null handling is unchanged.

## Parity test plan

The existing suite already proves this is safe, but to pin the dedup explicitly:

1. **Reuse `CastParityTest` / `CastEquivalenceParityTest` unchanged** — they read
   every cast type (incl. `float`/`double`/`real` if fixtures cover them) and assert
   byte-identity + type-identity vs vanilla. Green after the change = no behavioural
   drift.
2. **Add a one-model synonym fixture** casting three columns as `real`, `float`,
   `double` (and another as `int`/`integer`, `bool`/`boolean`, `array`/`json:unicode`),
   assert read output identical to vanilla for each.
3. **White-box (optional):** after reading a `real` then a `float` column, assert
   `static::$greaseCasters['float']` is set and `['real']`/`['double']` are *not* —
   proving the fold actually happened (this is the only thing the existing tests
   don't already cover, since they don't inspect the cache keys).

## Bench plan

There is **no speed delta to measure** — the lookup adds one array read on a cold
cache key and changes nothing on the hot path. If you want the memory number for the
record, snapshot `memory_get_usage()` before/after touching all synonym types with
and without the alias map (the `/tmp/grease_mem.php` shape above); expect a delta of
`(8 - n) × ~1.3 KB` where `n` is duplicates the workload didn't trigger. Record it
and move on.

---

## Bottom line / recommendation

1. **Correct and free.** Stateless flyweights + textually identical synonym closures
   mean zero behavioural change; `decimal` (the one parameterized cast) is correctly
   excluded. The implementation is ~5 lines and adds no override surface.
2. **Magnitude is negligible.** Measured ceiling is **8 duplicate `ClosureCast`
   objects (~10 KB) eliminated once per process**, realistically 0–3 in a normal app,
   with **no** per-row/instance/request component. It does not move any benchmark.
3. **Recommendation: PARK** (alongside NOTES #8). It's a tidiness win, not a
   performance one — fine to fold in opportunistically if the flyweight table is ever
   touched for another reason, but not worth a standalone tier, a fixture, or a line
   in the perf headline. If built, document it honestly as "removes a handful of
   duplicate flyweight objects per process," never as a memory optimization users
   would notice.
