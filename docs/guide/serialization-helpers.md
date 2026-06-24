# Serialization helpers

`HasGreasedSerialization` makes `toArray()` / `toJson()` faster by eliminating the
Carbon parse-and-reformat round-trip on date columns. But that tier only fires
**inside `attributesToArray()`** — and plenty of code never goes through it. Scout's
`toSearchableArray`, a `JsonResource`, a CSV export, a webhook payload: they build an
array by hand, reading attributes one at a time, and so leave the biggest tier
holstered.

Two helpers hand you that tier directly. Both are **byte-identical** to a known
vanilla expression — the same promise as the rest of the package — so they are always
safe to drop in. Any model with `HasGrease` (or the `HasGreasedSerialization` trait,
or extending `GreasedModel`) has them.

## `greaseSerializeDate($key)`

Serialize one stored datetime exactly the way `attributesToArray()` would, without
routing the whole model through it.

```php
public function toSearchableArray(): array
{
    return [
        'title'      => $this->title,
        'created_at' => $this->greaseSerializeDate('created_at'), // was: $this->created_at?->toJSON()
    ];
}
```

The return is **byte-identical** to

```php
$this->serializeDate($this->asDateTime($this->attributes[$key]))
```

which for the default serializer is the `toJSON()` form, e.g.
`2026-01-01T00:00:00.000000Z`. When the per-class probe certifies a fast path (UTC
default ISO, or a storage-format `serializeDate`) the Carbon round-trip is skipped;
otherwise it falls back to that exact vanilla composition — always correct, only
sometimes faster.

::: tip It returns the `toJSON` shape — match the field you're replacing
Not `toIso8601String()` (`+00:00`, no microseconds), not `toDateTimeString()` (the
storage form). It's a drop-in for a field already emitting the array/JSON
serialization. Eligible for **timestamps** and plain **`datetime` / `immutable_datetime`
casts**; a `date` cast, a custom-format datetime, or a custom `CastsAttributes::serialize`
falls back to vanilla. Reading a `null` column returns `null`.
:::

## `greaseSerializeOnly($keys)`

Serialize a curated subset of a model to array form — the line you'd otherwise write
as `Arr::only($model->attributesToArray(), $keys)`, but **without serializing the
columns the filter would immediately discard**.

```php
public function toSearchableArray(): array
{
    return $this->greaseSerializeOnly(['title', 'status', 'created_at']);
}
```

The whole greased array path runs over the narrowed set, so the date tier still fires
on any dates you pick. The output is byte-identical to
`Arr::only($model->attributesToArray(), $keys)`: the model's own `visible` / `hidden`
config is honored (a hidden key you ask for stays hidden), and the key order follows
`attributesToArray()`, not your request.

::: tip Non-mutating
Unlike `setVisible($keys)->attributesToArray()`, it restores the model's visible list
before returning — and skips a `clone`. An empty `$keys` serializes nothing (it does
*not* fall through to "no restriction" the way `setVisible([])` would).
:::

## Which helper, and when

The two have **opposite** win profiles, so between them they cover the spectrum:

| Helper | Win is proportional to… | Best on |
| --- | --- | --- |
| `greaseSerializeDate` | number of **date columns** you serialize | thin, date-heavy rows |
| `greaseSerializeOnly` | columns you **skip** (`1 − kept/total`) | wide rows where you pick a few of many |

`greaseSerializeDate` saves roughly one Carbon parse per date column (~5–8 µs), so its
percentage shrinks as a row's non-date work grows. `greaseSerializeOnly` does the
reverse — it pays for exactly the columns you skip, so a model where you serialize *all*
its columns gains nothing from it. The fewer of a wide row's columns you keep, the more it
helps.

## The numbers

Stand-alone bench, fresh-hydrated per op (a request serializes each row once), UTC:

| Swap (byte-identical) | before | after | Δ |
| --- | ---: | ---: | :---: |
| `?->toJSON()` → `greaseSerializeDate()` (2 timestamps, thin model) | 17.2 µs | 2.4 µs | **−86%** |
| `Arr::only(toArray, keys)` → `greaseSerializeOnly()` (3 of 23 cols) | 43.1 µs | 3.7 µs | **−91%** |

`greaseSerializeOnly` lands within noise of mutating
`setVisible()->attributesToArray()` (3.66 µs) — the win is the skipped serialization
plus non-mutation, so no extra per-key-set machinery was added.

::: warning Micro-benchmarks flatter, and understate, in different directions
Read these as "what swapping one call buys on a freshly-hydrated row." On a warm,
already-read model the Carbon parse is cached, so a re-read would understate the date
win to near zero — which is why the bench hydrates fresh. And the standalone numbers
are isolated ops; the figure that matters is your pipeline's, measured end to end.
:::

## Validate it yourself

A stand-alone script (no phpbench) runs both helpers against the patterns they
replace, over the same fixtures the parity suite proves byte-identical, and refuses to
report a delta if parity ever fails on your build:

```bash
php benchmarks/serialize_helpers.php          # default 9 rounds
php benchmarks/serialize_helpers.php 25        # more rounds, tighter median
```

```
1. greaseSerializeDate()  —  pick 2 timestamps off a thin model
   output: ["2026-03-04T09:10:11.000000Z","2024-12-31T23:59:59.000000Z"]

  idiomatic  ?->toJSON()                17.21 µs
  greaseSerializeDate()                  2.40 µs   -86.0%

2. greaseSerializeOnly()  —  pick 3 of 23 columns off a wide model
   output: {"str_val":"100","status_val":"active","created_at":"2026-01-01T00:00:00.000000Z"}

  naive  Arr::only(toArray, keys)       43.06 µs
  setVisible()->attributesToArray()      3.66 µs   -91.5%
  greaseSerializeOnly()                  3.71 µs   -91.4%
```

The same pair is also timed under phpbench, with a parity guard in `setUp`:

```bash
vendor/bin/phpbench run benchmarks/Bench/DateSerializationBench.php --report=aggregate
vendor/bin/phpbench run benchmarks/Bench/SerializeOnlyBench.php --report=aggregate
```

And the byte-identical contract itself is the test suite — every strategy, edge value,
timezone, and visibility config:

```bash
composer test    # DateSerializationParityTest + SerializeOnlyParityTest
```

To bench your *own* models, copy a `*Greased` fixture's shape into the script's two
sections and keep the parity assertion — if it fails, the bench stops, because a delta
between two different outputs means nothing.
