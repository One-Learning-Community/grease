# Grease

[![tests](https://github.com/onelearningcommunity/grease/actions/workflows/tests.yml/badge.svg)](https://github.com/onelearningcommunity/grease/actions/workflows/tests.yml)

**Opt-in performance for Eloquent's hot path — the wins upstream won't merge.**

Grease is a single trait you add to a model. It makes attribute hydration, casting,
and serialization measurably faster, with **byte-identical output** to vanilla
Eloquent. Zero changes to the framework. Add it to the models you care about; leave
the rest untouched.

```php
use Grease\Concerns\HasGrease;

class User extends Model
{
    use HasGrease;
}
```

or extend the base model:

```php
class User extends \Grease\GreasedModel { /* ... */ }
```

## Why this exists

Eloquent re-derives class-pure facts on *every* attribute access and *every*
hydrated row: it rebuilds the casts array, re-walks a cast `switch`, re-probes
`method_exists` for mutators, re-resolves the connection's date format, and runs a
fresh `ReflectionClass` per `new Model`. None of it changes for the life of the
class. Grease computes each fact once per class and reuses it.

These optimizations have been proposed to Laravel core repeatedly and declined on
stability grounds — each one is "marginal in isolation." Individually, maybe.
**Together, on a real request, they aren't.**

## Benchmarks

Real SQLite, real queries, real controller-shaped workloads — **end-to-end per
request, including SQL.** Vanilla Eloquent vs. the same models with `HasGrease`.
Output is byte-identical (asserted across every cast type and workload).

| Endpoint (one request, incl. SQL) | vanilla | + Grease |   Δ    |
| --------------------------------- | ------: | -------: | :----: |
| list 100 models → JSON            | 11.4 ms |   9.5 ms | −16.9% |
| eager list (with relation) → JSON | 22.3 ms |  18.6 ms | −16.4% |
| load 150, mutate, save            | 30.6 ms |  26.2 ms | −14.4% |
| nested list (author + comments)   | 14.0 ms |  12.1 ms | −13.3% |
| show one (with relations)         | 0.92 ms |  0.82 ms | −10.0% |

Reproduce: `php benchmarks/realworld.php` (see [`benchmarks/`](benchmarks)). The
gain scales with how much your request hydrates — wide selects, eager loads, and
serialization-heavy API responses benefit most.

## Caveats

Two narrow, obscure things change on a greased model's cast path. Custom casts
([`CastsAttributes`](https://laravel.com/docs/eloquent-mutators#custom-casts)), the
documented extension point, **work unchanged** — and so does overriding
`getCastType()`. The full cast contract is asserted byte-identical to vanilla in
the test suite (every cast type × edge values × dirty-checking).

1. **Per-instance `$casts` set in a constructor isn't supported.** The cast map is
   cached per class, so assigning a *different* `$casts` per instance inside a
   model's constructor would serve the first instance's map. Use `mergeCasts()` /
   `withCasts()` at runtime instead (these are honored — the cache steps aside).
   This pattern is vanishingly rare in real apps.

2. **A per-key `isEncryptedCastable()` override is not honored.** Overriding that
   undocumented internal to encrypt an attribute whose cast type isn't itself an
   `encrypted:*` type won't decrypt on a greased model. The idiomatic way —
   `'ssn' => 'encrypted:string'` — works perfectly. Nobody overrides this on purpose.

Removing the machinery that preserves that unused flexibility is precisely where a
chunk of the speedup comes from.

Want zero cast caveats at all? Use the tiers à la carte and skip the cast one —
you keep the hydration and metadata wins:

```php
use Grease\Concerns\HasGreasedHydration;
use Grease\Concerns\HasGreasedAttributes;

class User extends Model
{
    use HasGreasedHydration;   // construct / hydration
    use HasGreasedAttributes;  // cast/date/mutator metadata memoization
}
```

## How it works

Every tier is a method override that reads a single per-class "blueprint"
(`static::$greaseBlueprint[$class]`) and falls back to `parent::` for anything it
doesn't accelerate. The blueprint is built lazily on first use and invalidated as a
unit. Non-greased models run pure vanilla Eloquent — Grease adds **zero** cost to
models that don't use it.

| Tier                    | What it memoizes / removes                                              |
| ----------------------- | ----------------------------------------------------------------------- |
| `HasGreasedHydration`   | per-row `ReflectionClass`, casts-array rebuild, redundant `newInstance` |
| `HasGreasedAttributes`  | `getCasts` / `getDates` / mutator probes / `getDateFormat`              |
| `HasGreasedCasts`       | per-access cast `switch` → resolved flyweights (see caveat)             |

## Requirements

PHP 8.2+, Laravel 11/12.

## License

MIT.
