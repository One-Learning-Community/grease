# Caveats & Narrowing

Grease buys speed by *removing the machinery that preserves flexibility nobody
uses.* This page is the complete, honest accounting of what that costs. The short
version: two obscure things change on a greased model's cast path, and both have a
trivial idiomatic workaround.

The full cast contract is asserted **byte-identical to vanilla** in the test suite —
every cast type, every edge value, every null, every dirty comparison — across PHP
8.2–8.5 and Laravel 12/13.

## What stays exactly the same

- **Custom casts** ([`CastsAttributes`](https://laravel.com/docs/eloquent-mutators#custom-casts)),
  the documented extension point — unchanged.
- **`getCastType()` overrides** — a subclass that defines `getCastType()` shadows the
  trait and stays fully live. The resolved type is otherwise memoized per class (it's
  a pure function of `getCasts()`), exactly like `getCasts()` itself.
- **Enum casts** — accelerated, with conversion delegated to the framework so output
  is byte-identical.
- **`mergeCasts()` / `withCasts()`** at runtime — fully honored; the per-class cache
  steps aside for a mutated instance.

## The two narrowings

### 1. Per-instance `$casts` set in a constructor isn't supported

The cast map is cached per class. If you assign a *different* `$casts` per instance
inside a model's constructor, a greased model would serve the first instance's map.

**Workaround:** use `mergeCasts()` / `withCasts()` at runtime instead — these are
honored, because the divergence guard detects the change and steps the cache aside.

```php
// instead of mutating $this->casts in a constructor:
$model->mergeCasts(['detail' => 'array']);
```

This pattern is vanishingly rare in real apps.

### 2. A per-key `isEncryptedCastable()` override isn't honored

Overriding that undocumented internal — to encrypt an attribute whose cast type
isn't itself an `encrypted:*` type — won't decrypt on a greased model.

**Workaround:** use the idiomatic encrypted cast, which works perfectly:

```php
protected $casts = ['ssn' => 'encrypted:string'];
```

Nobody overrides `isEncryptedCastable()` on purpose.

## What defers to vanilla (correct, just unaccelerated)

Acceleration is never bought with correctness. Where Grease can't certify
byte-identity, it hands the work back to the framework:

- **Class-castable reads** (`CastsAttributes`) — already object-cached by Eloquent
  after first access, so there's little left to win; deferred.
- **Encrypted reads** — dominated by decryption; the dispatch shave would be noise,
  and reproducing decrypt-then-recast is the most error-prone path in the file.
  Deferred.
- **Exotic date serialization** — non-UTC default serializers, custom date formats,
  `date`/`immutable_date` casts, sub-second or non-string values. The serialization
  tier's per-value shape guard defers these to vanilla automatically.

All of the above produce identical output; they simply don't get the fast path.

## Want zero cast caveats at all?

Use the tiers à la carte and skip the cast tier. You keep the hydration and metadata
wins — which carry **no** behavioural narrowing — and the cast path stays 100%
vanilla:

```php
use Grease\Concerns\HasGreasedHydration;
use Grease\Concerns\HasGreasedAttributes;

class User extends Model
{
    use HasGreasedHydration;   // construct / hydration
    use HasGreasedAttributes;  // cast/date/mutator metadata memoization
}
```

That's the design philosophy in one snippet: opt in to exactly the speed you want,
keep exactly the flexibility you use.
