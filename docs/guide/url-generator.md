# The URL Generator

A second tier on the routing axis — and unlike the [middleware router](/guide/routing), this one
**is** a per-request multiplier. A single API-Resource response calls `route()` once per link per
row: 500 rows with two or three links each is 1,000–1,500 URL builds in one payload. Each is pure
repeated string assembly for a URI shape that never changes. This tier replays that shape from a
cache instead.

## It is not the Symfony route compiler

The first thing to clear up, because the obvious assumption is wrong: `route()` does **not** re-run
Symfony's `RouteCompiler`. A route compiles once — `Route::$compiled` is memoized and is persisted
through `route:cache` (`prepareForSerialization()` forces the compile at cache-build time), so at
request time the compile never runs. An Excimer profile of a tight `route()` loop confirms it:
`RouteCompiler::compile` is **0%** of the cost.

What actually costs the microseconds is the per-call **assembly** in `RouteUrlGenerator::to()`:
`formatParameters` → `replaceRouteParameters` → `replaceNamedParameters` → `addQueryString`, plus
the optional-parameter walk and a thick `Arr`/`Collection` layer underneath. That pipeline runs in
full on every `route()` call.

## What it does

The shape of a simple route URL is a class-pure fact. `api/posts/{post}/comments` is, forever:

```
segments = ['api/posts/', '/comments']
params   = ['post']
```

So every URL for that route is `segments[0] . value . segments[1]`, encoded — a concat. `Grease\Routing\UrlGenerator`
caches that `[segments, params]` entry per route name (compiled lazily on first `route()`, or
pre-seeded by `grease:route-cache`) and assembles the URL directly, skipping the whole pipeline.
Both **absolute** (the `route()` default) and **relative** URLs are accelerated; the absolute
root/scheme is read from vanilla's own already-memoized `formatRoot()`/`formatScheme()`, so
`forceScheme`, `forceRootUrl`, and a per-route `secure()` are all honored — only the parameter
assembly is skipped. Encoding reuses the route generator's `$dontEncode` map verbatim.

## Byte-identical, or it defers

This is the cardinal rule, and the fast path is conservative about it: it fires **only** when the
result is provably the exact string vanilla would build. Anything else falls straight through to
`parent::toRoute()`. Two layers of guard:

**Not indexable** (decided once, when the route's shape is compiled):

- a route with a **domain** (it assembles a host),
- an **optional** `{param?}` or **scoped** `{param:field}` binding (different replacement
  semantics),
- a route carrying its own **`$defaults`**,
- a **duplicate parameter name** (`{a}/{a}`) — malformed; vanilla fills the first and throws on
  the second.

**Not fast-pathable** (decided per call):

- **extra parameters** — they become a query string,
- an **arity mismatch** — too few is vanilla's `UrlGenerationException`, which must still throw,
- a **non-scalar** value (after `UrlRoutable::getRouteKey()`) — `null`/`bool`/`float`/array have
  distinct vanilla semantics,
- an **empty-string** value — vanilla treats it as a *missing* parameter (leaves `{name}` literal)
  and throws,
- a value that would inject a literal `{…}` — vanilla treats that as a missing parameter and throws,
- a **subdirectory app** for a *relative* URL (non-empty `Request::getBaseUrl()`),
- any **`URL::defaults()`**, **`formatHostUsing()`**, or **`formatPathUsing()`** customization in
  effect.

Each of those is a defer, never a divergence. Signed URLs are absolute with a `signature` query
parameter, so they take the extra-params defer and keep working unchanged.

## Behaviour-identical, by test

`UrlGeneratorParityTest` asserts the generated URL **byte-for-byte** against vanilla
`Illuminate\Routing\UrlGenerator` over the same route collection — on the lazy-compile miss, on the
cache hit, and from a pre-seeded index — across: absolute and relative, model / scalar / positional /
named parameters, param-less and root routes, every defer case above, special-character encoding
(spaces, slashes, `@`, `+`, unicode, reserved), the missing-parameter exception, a `secure()` route's
scheme, and a subdirectory app. The provider test additionally proves signed URLs still validate
end-to-end after the singleton swap.

## What it's worth

The micro number is large; the end-to-end number depends entirely on how much your response leans on
`route()`. Both, measured on Linux (`benchmarks/docker`, opcache + JIT):

- **Per call: −93%** — `route()` drops from **2.0µs to 0.14µs** (`benchmarks/url_route_ab.php`).
- **On a vanilla response: −5.6%** of a 500-row API-Resource payload (~2,500 absolute links) —
  a thin slice, because the assembly is small next to model hydration and serialization
  (`benchmarks/url_realworld.php`).
- **On an already-greased response: −26%.** This is the real story. The assembly cost is *fixed*,
  so once the [model tiers](/guide/how-it-works) shrink everything else from ~85ms to ~17ms, the
  same ~6ms of URL building is a quarter of what's left. It is a **compounding tier** — small alone,
  large in the stack.

The flagship [cumulative-stack benchmark](/guide/benchmarks#the-whole-stack-compounding) shows it
landing exactly where you'd expect and nowhere else:

| route shape | stack without URL tier | + URL tier |
| --- | --- | --- |
| `api_resource.json` (route() per row) | −74.4% | **−81.5%** |
| `api_resource.blade` (route() per row, in a render loop) | −28.2% | **−53.7%** |
| any response with no `route()` calls | — | within noise (inert) |

A page whose every row emits a link is where this pays; a response that calls `route()` zero times
sees nothing change. That honesty cuts both ways — measure your own payloads.

::: tip Measure your own
These ratios are confirmed on Linux (`benchmarks/url_route_ab.php`, `url_realworld.php`,
`stack_pipeline.php`, via `benchmarks/docker`, production-shaped opcache + JIT). The µs absolutes are
for that box; macOS CLI inflates them (opcache off), but the ratios hold.
:::

## The prewarm: honest about what it buys

`grease:route-cache` writes the URL shape index (`name => [segments, params]`) alongside its
[middleware index](/guide/routing), via the same compiler the lazy path uses — so a pre-seeded entry
is byte-identical to one compiled on first `route()`. The provider loads it under the same freshness
guard (fresh only while at least as new as the route and config caches).

Be clear-eyed about the payoff, though: **prewarming buys nothing measurable within a single
response.** The lazy index self-warms on the first `route()` call, and the whole-collection build is
sub-millisecond even at hundreds of routes — below the noise floor of a real request. What it buys is
the same shape as the middleware index: under **FPM** it eliminates that per-request cold build (real
cycles, scales with route count, but small); under **Octane** the persistent generator builds the
index once for the worker's whole life. The **−26% / −81.5% itself comes from the assembly collapse**,
which lands identically whether the index was prewarmed or self-warmed. Prewarm for FPM-cold-start
hygiene and Octane, not for a per-response number.

## Memory

The shape index is a small array bounded by the number of named routes — `[segments, params]` per
route, no per-request growth. Pre-seeded, it is one constant array that lives once in opcache shared
memory, shared across workers and requests. In the cumulative stack it adds ~0.6 percentage points of
retained heap.

## Opt in

The URL generator is resolved **lazily** (nothing holds it before request time), so — unlike the
[router](/guide/routing), which the kernel takes by constructor injection — it needs **no
`bootstrap/app.php` edit**. Registering the routing provider swaps it in:

```php
// bootstrap/providers.php
Grease\Routing\GreaseRoutingServiceProvider::class,
```

That alone gives you the tier, compiling each route's shape lazily on first use. The framework's own
session/key resolvers and the `routes` rebinding survive the swap, so signed URLs and route-cache
rebinding are unchanged. To pre-seed the shape index too, deploy with `grease:route-cache` (the same
command that builds the middleware index):

```bash
php artisan grease:route-cache   # route:cache + the middleware index + the URL shape index
```

Or just run `php artisan optimize` — with the provider registered it runs `grease:route-cache` in its
`routes` slot. The provider is the single opt-in for **both** routing tiers; see
[The Router](/guide/routing) for the middleware half and the deploy/`optimize` details.

Without a fresh index the tier is still fully active — it just compiles shapes on demand instead of
loading them prebuilt.
