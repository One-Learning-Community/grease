# Getting Started

## Requirements

- **PHP** 8.2+
- **Laravel** 12 or 13

Grease is a pure userland package. It makes **zero** changes to the framework and
adds **zero** cost to anything that doesn't opt in.

## The tiers, at a glance

Grease is a menu, not a monolith. Each tier is an independent opt-in that removes the same
kind of waste — a stable fact Laravel recomputes per row, per render, per request, or per
query. Take the ones whose hot paths you actually run; the model trait is just the simplest.

| Tier | How you opt in | What it speeds up |
|---|---|---|
| **Model** | `use HasGrease;` | hydration, casting, serialization, dirty-check |
| [**Events**](/guide/events) | `GreaseEventServiceProvider` | every dispatch, app-wide |
| [**Blade**](/guide/blade) | `GreaseViewServiceProvider` | `@props` + attribute-merge per component |
| [**View cache**](/guide/view-cache) | same provider + `grease:view-cache` | view name→path resolution |
| [**Config**](/guide/config) | `GreaseConfigServiceProvider` (+ `grease:config-cache`) | `config()` reads |
| [**Validation**](/guide/validation) | `GreaseValidationServiceProvider` | rule parsing per validation |
| [**Container**](/guide/container) | `Grease\Container\Application` in `bootstrap/app.php` | constructor reflection per resolve |
| [**Request**](/guide/request) | `Grease\Http\Request::capture()` in `public/index.php` | `input()` / `all()` per access |
| [**Router**](/guide/routing) | `Grease\Routing\Router::swap($app)` (+ `grease:route-cache`) | middleware resolve + sort |

Everything below walks each one. None are auto-discovered — opting in is always deliberate.

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
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Events\GreaseEventServiceProvider::class,
```

Register it **first** in the array (or as early as practical), so listeners other
providers add land directly on the greased dispatcher and nothing captures the
original.

See [The Event Dispatcher](/guide/events) for what it does and what it's worth.

## The faster Blade compiler (optional)

Grease also greases the Blade component render path — the `@props` resolution and the
`$attributes->merge()` every component pays on every render. A third axis again, opt-in
and **not** auto-discovered:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\View\GreaseViewServiceProvider::class,
```

It swaps the `blade.compiler`; views recompile to the tighter form on their next change,
or immediately after `php artisan view:clear`. Output stays byte-identical.

See [Blade Components](/guide/blade) for what it does and what it's worth.

It pairs with an eager **view-resolution cache**: deploy with `php artisan grease:view-cache`
(a drop-in `view:cache` twin) and a view's name→path lookup becomes an opcache-interned array
hit instead of a per-render filesystem stat-walk. See [The View Cache](/guide/view-cache).

## Faster config, validation, and queries (optional, app-wide)

Two more provider-based tiers, each one line in `bootstrap/providers.php` and **not**
auto-discovered:

```php
Grease\Config\GreaseConfigServiceProvider::class,         // memoized config() reads
Grease\Validation\GreaseValidationServiceProvider::class, // memoized validation rule parsing
```

- **Config** memoizes resolved `config()` values; pair it with `php artisan grease:config-cache`
  (a `config:cache` twin) for an opcache-interned flat index where every leaf read is a hash hit.
  See [The Config Repository](/guide/config).
- **Validation** memoizes rule parsing across a validator's run — same pass/fail, same messages.
  See [Validation](/guide/validation).

## The foundation tiers (optional, app-entry)

The container, request, and router are constructed *before* any provider runs, so they can't
be a provider — each is a one-line swap at the application's own entry point. The heaviest
opt-in, taken only if you want it:

```php
// bootstrap/app.php — greased container (constructor-reflection blueprint)
return Grease\Container\Application::configure(basePath: dirname(__DIR__))/* …->create() */;

// public/index.php — greased request (memoized input() / all())
$request = Grease\Http\Request::capture();

// bootstrap/app.php — greased router (cached middleware resolve+sort), before returning $app
Grease\Routing\Router::swap($app);
```

The router pairs with `php artisan grease:route-cache` (a `route:cache` twin) for an eager,
opcache-interned middleware index. See [The Container](/guide/container),
[The Request](/guide/request), and [The Router](/guide/routing).

## Verify nothing changed

The promise is byte-identical output, so the best "did it work?" check is that your
responses are *unchanged* while your profiler shows less time in the greased paths
(hydration, rendering, resolution, whichever tiers you took). If you want proof in your
own app, diff a response before and after — it'll be identical to the byte.

Next: [How It Works →](/guide/how-it-works)
