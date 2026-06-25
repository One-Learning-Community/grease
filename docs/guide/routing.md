# The Router

Another foundation axis — resolving a route's middleware. Unlike `input()` or `config()`,
this is **not** a per-request multiplier: one request matches one route, and its middleware
is resolved twice (dispatch + terminate). So the per-request saving is small in absolute
terms — microseconds. What makes it worth a tier is that it's *pure repeated waste* removed
on every request and every worker, and it compounds: with request volume, with worker count,
and especially under Octane. There are two tiers, exactly as with config: a lazy per-router
memo, and an eager, opcache-interned index that goes further.

## What it does

For the matched route, `Router::gatherRouteMiddleware()` calls `resolveMiddleware()`, which:
expands middleware **groups and aliases** through `MiddlewareNameResolver`, runs a
`map`/`flatten`/`values` Collection chain, and then sorts via `SortedMiddleware` — which calls
**`class_implements()` + `class_parents()` on every middleware string** to place it in the
priority map. None of that is cached. The *raw* name list is memoized on the route
(`computedMiddleware`), but the expand-and-sort that turns it into the final ordered class
list runs every time — twice per request.

**Tier 1 — the lazy memo.** `Grease\Routing\Router` caches the exact resolved+sorted array,
keyed by the literal `(gathered, excluded)` middleware-name signature. The output is a pure
function of those names plus the process-constant alias/group/priority maps, so a repeat
signature is a single hash hit. The order is load-bearing, so the array is cached **verbatim**
— no reordering logic is touched.

**Tier 2 — the eager index (`grease:route-cache`).** Under FPM the router is rebuilt per
request, so the lazy cache starts empty every request: the dispatch pass is always a cold
miss, and only the terminate pass hits. The eager index removes that cold miss too.
`grease:route-cache` is a drop-in twin of `route:cache`: it precomputes every route's resolved
middleware and emits a sibling file holding a `signature => [classes]` map. Because that file
is a constant `return [...]`, **opcache interns it into shared memory** — `require` hands it
back by reference, so every worker and request loads it for ~free, and the greased router's
cache starts **pre-seeded**: both the dispatch and terminate passes are hits from request one.

### route:cache is orthogonal, not the competition

`route:cache` caches **URL matching** (the compiled Symfony matcher) and the **raw middleware
names** in each route's serialized action. It does **not** resolve or sort middleware — under
`route:cache`, `resolveMiddleware()` still runs the full expand-and-sort on every matched
request (verified against the framework source: the resolved/sorted list is never serialized,
and there is no resolved-middleware cache anywhere in routing). The two stack: `route:cache`
speeds up *name → route*, this speeds up *names → resolved+sorted classes*.

## Does it stay correct when middleware changes?

Yes. Because the index keys by the **same signature** the lazy path uses, the eager tier is
just a *pre-seeded lazy cache* — and both share one invalidation story:

- **Every map mutator flushes the whole cache**: `aliasMiddleware`, `middlewareGroup`,
  `prependMiddlewareToGroup`, `pushMiddlewareToGroup`, `removeMiddlewareFromGroup`,
  `flushMiddlewareGroups`. Laravel's own `Kernel::syncMiddlewareToRouter()` funnels *all*
  runtime middleware changes — priority included — back through `aliasMiddleware`/
  `middlewareGroup`, so those flush too, seeded entries included.
- **A seeded entry is served only on an exact signature match.** A route whose middleware is
  assigned dynamically (or whose controller middleware varies) produces a different gathered
  list → a different key → a **miss → live resolve**. The eager index can never mask a
  route-content change; it can only ever return a build-time resolution for an *identical*
  signature.
- **Live truth wins.** Seeding is a union (`+=`), so an entry already resolved live this
  request is never overwritten by the index.

### The carve-outs

- **Lazy — direct map mutation.** Writing `$router->middlewarePriority = …` (or `->middleware`
  / `->middlewareGroups`) *directly* instead of via the registration methods isn't observable.
  In practice this never bites: the only direct write Laravel does is in `syncMiddlewareToRouter()`,
  which immediately follows it with `aliasMiddleware`/`middlewareGroup` calls that flush — and it
  runs before dispatch, never mid-request.
- **Eager — build must equal runtime.** A hit returns the resolution computed at
  `grease:route-cache` time, so it's only valid if the alias/group/priority maps are the same when
  serving. The thing to avoid is **gating middleware registration** on the environment — a provider
  that registers middleware only for `runningInConsole()` (so the console build and HTTP serving
  disagree), or an env/flag-conditional alias such as `throttle` → Redis-vs-sync. Rebuild on every
  deploy, and the freshness guard handles the cached-artifact cases: the index loads only while it's
  at least as fresh as the route cache **and** the config cache, so a later plain `route:cache`,
  `route:clear`, or `config:cache` automatically disables a now-stale index (you fall back to the
  lazy memo — never served a wrong list); `php artisan optimize` instead *rebuilds* it, since it runs
  `grease:route-cache`. It is also **inert in development**:
  with no route cache present, the index never loads.

## Under Octane vs FPM

The two tiers split cleanly, and it's the inverse of the config axis. Under Octane the router is
a persistent singleton (Octane only re-points its container per request — verified: no listener
mutates the middleware maps or flushes the cache), so the **lazy memo already reaches its full
value**: every signature is resolved once for the worker's whole life. The **eager index** is the
tier that wins **FPM**, where the router is per-request and the lazy cache can't survive between
requests — the pre-seed makes both passes hits from a cold start. Together they bring FPM
middleware resolution to ~Octane steady-state.

## Behaviour-identical, by test

Parity is the resolved list **and its order**, asserted against vanilla `Illuminate\Routing\Router`
across realistic stacks — the `web`/`api` groups, aliases, the priority map, exclusions, and
duplicate names — on the cache miss, the cache hit, and after every alias/group mutation. The eager
path adds: a seeded entry serves a hit equal to vanilla, a seed never overwrites a live entry, a map
mutation flushes seeded entries, closures defer (no stable key), the command's `var_export` file
round-trips, and the freshness-guard matrix (missing / newer / stale / route-clear / config-newer).

## What it's worth

Don't expect a headline number on a single request — it's once-per-request work, so the delta is
microseconds, dwarfed by the model/Blade/SQL tiers. The point is what it removes and where it
compounds. Measured on Linux (`benchmarks/docker`, opcache + JIT): a single resolve is **6.8µs**
vanilla, and the lazy cache takes a repeat to **0.10µs (−98.5%)**. On the FPM-cold model (per
request = dispatch + terminate resolve), vanilla is **13.1µs/request**; the lazy tier roughly halves
it to 6.8µs (it banks the terminate hit), and the eager index takes it to **0.25µs — −96% vs lazy,
−98% vs vanilla**, essentially eliminating middleware resolution as a request cost. That's ~13µs
saved per request — invisible on one request, but pure waste removed on every one: multiply by your
request rate and worker count. Under Octane the lazy tier alone gets there, as the cache persists.

::: tip Measure your own
These ratios are confirmed on Linux (`benchmarks/route_cache_ab.php`, `middleware_ab.php`, via
`benchmarks/docker`, production-shaped opcache + JIT); the µs absolutes are for that box — reproduce
on your target. macOS CLI inflates the absolutes (opcache off), but the ratios hold.
:::

## Memory

The lazy cache is a small array bounded by the number of distinct route signatures (a few dozen,
typically) — no per-request growth, no leak. The eager index is one constant array that lives
**once in opcache shared memory**, shared across every worker and request, so its per-request heap
cost is ~nil.

## Opt in

The greased router is wired into the HTTP kernel by constructor injection *before any provider
runs*, so — unlike the event/view/config rebinds — a provider can't install it (the kernel keeps
its own reference). Swap it where the binding is first defined, in `bootstrap/app.php`, before the
kernel resolves:

```php
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(/* … */)
    ->withMiddleware(/* … */)
    ->create();

Grease\Routing\Router::swap($app);   // the lazy tier

return $app;
```

That's the whole lazy tier, and it carries no real caveat — take it anywhere. For the eager index,
register the provider and swap `route:cache` for `grease:route-cache` in your deploy:

```php
// bootstrap/providers.php
Grease\Routing\GreaseRoutingServiceProvider::class,
```

```bash
php artisan grease:route-cache   # route:cache + the opcache-interned middleware index
```

Run it **last** in your deploy (it runs `route:cache` itself), so a later plain `route:cache` doesn't
shadow it — or skip the manual step and run `php artisan optimize`, which now runs `grease:route-cache`
in its `routes` slot for you.

::: tip `optimize` / `optimize:clear` just work
With the provider registered, `optimize` runs `grease:route-cache` in its `routes` slot
automatically (in place of `route:cache`), and `optimize:clear` runs the clear twin,
`grease:route-clear` — a **superset** of `route:clear` that also drops the route cache, mirroring
`grease:route-cache`'s superset of `route:cache`. The same holds for the
[config](/guide/config) and [view](/guide/view-cache) tiers — a standard `optimize` deploy picks up
every grease cache you've opted into, no grease-specific step.
:::

Without the swap, or without a fresh index, the provider is inert — the lazy memo still applies.
