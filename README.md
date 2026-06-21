# Grease

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

## The one caveat

The cast tier narrows one contract: the cast-*type* decision is resolved per cast
type, not per key. So overriding the **undocumented** internals `getCastType()` or
`isEncryptedCastable()` *per attribute* is not honored on greased models.

In practice this affects ~nobody: if you want a custom cast you write a
[`CastsAttributes`](https://laravel.com/docs/eloquent-mutators#custom-casts) class —
the documented, supported extension point — and **that works unchanged.** Nobody
overrides those Model internals on purpose. Removing the machinery that preserves
that unused flexibility is precisely where a chunk of the speedup comes from.

Want the speed without even that caveat? Use the tiers à la carte and skip the cast
one:

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
