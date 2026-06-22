# Getting Started

## Requirements

- **PHP** 8.2+
- **Laravel** 11, 12, or 13

Grease is a pure userland package. It makes **zero** changes to the framework and
adds **zero** cost to models that don't opt in.

## Install

```bash
composer require onelearningcommunity/grease
```

## Use it

Add the trait to any model whose hot paths you want faster:

```php
use Grease\Concerns\HasGrease;

class User extends Model
{
    use HasGrease;
}
```

Prefer inheritance? Extend the greased base model instead — it's identical in
behaviour to `use HasGrease`:

```php
class User extends \Grease\GreasedModel
{
    // ...
}
```

That's the entire setup. The model's hydration, casting, and serialization now run
the greased fast paths, and its output stays byte-identical to vanilla Eloquent.

::: tip There is nothing to configure
No service provider to register (for the model tiers), no config file to publish, no
cache to warm. The per-class "blueprint" builds itself lazily on first use and is
Octane-safe. Add the trait, deploy, move on.
:::

## Pick your tiers (optional)

`HasGrease` bundles four composable tiers. You can also use them à la carte — they
share one per-class blueprint and compose freely:

```php
use Grease\Concerns\HasGreasedHydration;     // construct / hydration
use Grease\Concerns\HasGreasedAttributes;    // cast/date/mutator metadata memoization
use Grease\Concerns\HasGreasedCasts;         // flyweight cast dispatch + enum fast path
use Grease\Concerns\HasGreasedSerialization; // date-serialization round-trip elimination

class User extends Model
{
    use HasGreasedHydration;
    use HasGreasedAttributes;
}
```

The only tier with a (tiny, obscure) behavioural narrowing is `HasGreasedCasts` —
see [Caveats](/guide/caveats). Want the hydration and metadata wins with **zero**
cast caveats? Use the two tiers above and skip the cast one.

## The faster event dispatcher (optional, app-wide)

Grease also ships a drop-in faster event dispatcher — a different axis from the
per-model tiers. It speeds up *every* dispatch in the app, not just model events.
It's opt-in and **not** auto-discovered:

```php
// bootstrap/providers.php (Laravel 11+), or the providers array in config/app.php
Grease\Events\GreaseEventServiceProvider::class,
```

See [The Event Dispatcher](/guide/events) for what it does and what it's worth.

## Verify nothing changed

The promise is byte-identical output, so the best "did it work?" check is that your
responses are *unchanged* while your profiler shows less time in Eloquent. If you
want proof in your own app, diff a JSON response before and after — it'll be
identical to the byte.

Next: [How It Works →](/guide/how-it-works)
