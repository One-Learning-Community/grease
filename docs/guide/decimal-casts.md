# Decimal Casts

A single, **separate** opt-in trait for one thing: the `decimal:N` cast. It's deliberately
**not** part of `HasGrease` (or `GreasedModel`) — because `decimal` almost always means money,
and a money path is the last place you want a clever optimization you didn't opt into on purpose.
Add it yourself, on the models where it pays, and audit or remove it in isolation:

```php
use Grease\Concerns\HasGreasedDecimalCasts;

class Transaction extends Model
{
    use HasGreasedDecimalCasts;          // alone, on a plain model
}

class LedgerEntry extends Model
{
    use HasGrease, HasGreasedDecimalCasts;   // or stacked on the full bundle
}
```

## What it does

Vanilla casts a `decimal:N` value through Brick\Math, **per cast, per row**, on every read:

```php
asDecimal($value, $N) = (string) BigDecimal::of((string) $value)->toScale($N, RoundingMode::HalfUp);
```

When the stored value is already a string in the exact canonical scaled form — `"10.50"` for
`decimal:2` — that whole round-trip produces the input unchanged. So the trait returns it verbatim
and skips Brick entirely. Everything else defers to vanilla untouched.

## Byte-identical, and it never rounds

This is the cardinal rule, and for a money feature it's the only thing that matters. The fast path
fires **only** on a value that is already at the exact target scale, with no leading zero, no
sign-on-zero, and no non-decimal notation — precisely the inputs where `toScale()` is provably a
no-op. **It never rounds and never reformats.** Anything that would need either —

- a different scale (`"10.5"`, `"10.567"` for `decimal:2`),
- a leading zero (`"010.50"`), negative zero (`"-0.00"`), a sign or plus,
- scientific notation (`"1e2"`), a non-string value, or anything malformed —

fails the guard and falls straight through to Brick, exactly as vanilla would handle it. The risk
is asymmetric, and that asymmetry *is* the safety argument: a too-conservative guard only forfeits
the speedup; it can never emit a value that differs from Brick. The worst case on any input, on any
database, is "no speedup" — never a wrong number.

That invariant is proven byte-for-byte against the real framework `asDecimal()` as the oracle over
**1,097,907 fuzzed cases** (scales 0–4 plus hand-picked money adversarials), plus full
`toArray()`/`getAttribute()` parity and standalone-vs-bundled parity, in
`HasGreasedDecimalCastsParityTest`. It's also **stateless** — it reads only the value and the scale,
never cached cast metadata — so there's no blueprint, no divergence flag, and nothing to invalidate;
a runtime `mergeCasts()` just changes the scale it's handed.

## The win is driver-dependent (the correctness is not)

The fast path only fires when your database driver returns a **canonical decimal string**:

- **MySQL** (`DECIMAL`) and **PostgreSQL** (`NUMERIC`) both do — `"10.50"`, fixed scale. This is
  where money lives, and where the trait pays.
- **SQLite** returns `decimal` columns as a float, so the trait **defers** — byte-identical, just
  no speedup. (This is why it's correct but inert under a SQLite test suite.)

Correctness is identical on every driver; only whether the fast path *fires* depends on the return
shape.

## What it's worth

Measured on a realistic transactions row — four decimal columns (amount, fee, tax, balance), no
JSON, the usual foreign keys, strings, and timestamps — on Linux with opcache + JIT
(`benchmarks/decimal_ab.php`):

| | vanilla | + decimal trait | delta |
| --- | --- | --- | --- |
| **Plain model** | 50,430 ns | 49,216 ns | **−2.4%** |
| **Greased model** (`HasGrease` + the trait) | 28,307 ns | 27,262 ns | **−3.7%** |

The greased row is the real number — it's how you'd deploy this — and it's *larger* because it
compounds: once `HasGrease` removes the datetime serialization cost (−43.9% on this row by itself),
decimal is a bigger share of what's left. The absolute saving is **~260 ns per decimal column**, so
it scales with how many your row carries: roughly −2% at two columns, −3.7% at four.

Be clear-eyed about scope: this is **not** a headline tier. It does nothing for a model with no
`decimal` casts, and little for one with a single decimal among heavier casts (a `json` column's
`json_decode`, for instance, dwarfs it). It earns its place on **decimal-dense financial models on
MySQL/PostgreSQL** — ledgers, invoices, line items — where it's a real, byte-identical, compounding
couple of percent on every serialization.

## Opt in

It's a single trait, opt-in per model — no provider, no config, no app-entry edit:

```php
use Grease\Concerns\HasGreasedDecimalCasts;

class Transaction extends Model
{
    use HasGreasedDecimalCasts;
}
```

Stack it on `HasGrease` for the compounding win, or use it alone. Either way the output is
byte-identical to vanilla — on every driver, for every value.
