# Grease — request/response & container research leads

**Date:** 2026-06-22 (overnight research)
**Scope:** new axes beyond the (largely exhausted) Eloquent-model and Blade tiers —
the per-request *foundation* hot paths: application container, HTTP request/response,
routing, middleware pipeline/kernel, foundation bootstrap/facades/config, and Support
primitives.
**Method:** six parallel agents, each grounded in the live framework source at
`/Users/serpentblade/work/framework` (`13.x`, target Laravel 12/13, PHP 8.2+),
following the Grease spine — measure-first, byte-/behaviour-identical, opt-in,
honest-verdict (a credible negative counts). All µs figures are macOS `php -r`
microbenches (JIT default) — **ratios needing Linux/docker confirmation**, not final
numbers (per NOTES: macOS distorts magnitudes).

---

## The one structural insight that frames everything

**Eloquent and Blade gave Grease leverage because their hot work is a *multiplier*:**
hydration/casting run **per row × per attribute**, Blade runs **per component** —
hundreds-to-thousands of hits per request, so folding a per-hit constant compounds.

**The request *spine* is mostly O(1) per request.** One request matches one route,
builds one middleware onion, dispatches one controller, boots once. So the classic
Grease lever — "fold work that repeats within a request" — *mostly does not apply* to
routing, the pipeline, or bootstrap. That makes most of this territory **thin in
classic PHP-FPM**: once-per-request, low-µs wins, dwarfed by the existing −X0% tiers.

**Exactly two findings escape this and are genuine multipliers** — they're the
headlines. Everything else is honestly ranked below them, including several credible
nulls worth recording so the morning doesn't re-chase them.

A second cross-cutting theme: **opt-in feasibility is now as decisive as the win.**
The clean Grease seam (rebind a container singleton — the `events`/`blade.compiler`
precedent) does **not** reach the container or the request, which are built by the
bootstrap *before any provider runs*. The two headline wins need a heavier delivery
model (an `Application`/`Kernel`/`Request` swap in `bootstrap/app.php` or
`public/index.php`). That's a strategic decision for the morning — see "Delivery".

A third theme: **several leads only pay under Octane** (persistent workers), where
per-request structures survive across requests and a 1–2 ms request makes 20 µs ≈ 1–2%.
Grease today targets per-request FPM wins. Whether to open an Octane track is a call.

---

## Master ranked list

### 🟢 HEADLINE 1 — Container Constructor Blueprint (transient resolve)
*Axis: Container · Confidence: high (per-build win) / medium (request impact, opt-in)*

`Container::build()` rebuilds, on **every** resolve of a non-singleton, work that is a
pure function of the class name: `new ReflectionClass($concrete)`
(`Container.php:1125`), `isInstantiable()`/`getConstructor()`/`getParameters()`, and
per-parameter `Util::getParameterClassName()` (`Util.php:54-75`) + attribute walks
(`Container.php:1231,1242,1153`). Nothing in the container caches any of this today
(the only existing reflection caches are for binding *discovery*, not the build path).

Key = `$concrete`. Constructor signatures, parameter type hints, builtin-ness, and
attributes are immutable for the process — textbook Grease shape, the container twin of
`HasGreasedHydration` killing the per-row `ReflectionClass`.

- **Measured (macOS):** `make(Ctrl)` on a 4-node tree 23.3 µs → 15.4 µs, **−34%,
  parity OK**. Decomposed: caching reflection objects alone −10%; the remaining ~24 pts
  come from caching the per-parameter *resolution plan* (classnames + primitive
  classification + attribute-presence flags). Prototypes left at `/tmp/cab.php` (full)
  and `/tmp/cab2.php` (reflection-only).
- **Why it's a multiplier:** a single request resolves *many* transients — controller,
  form requests, jobs, listeners, events, view composers, some middleware. Singletons
  hit `$instances` (`Container.php:922`) and get **zero** benefit; the request win
  scales with the transient/singleton mix. Needs a realworld macro to size end-to-end.
- **Parity (the subtle part — the prototype cheated, a real impl can't):** cache only
  the *reflection-derived plan*, never resolved instances or contextual concretes.
  Contextual bindings (`getContextualConcrete`, keyed on `end($buildStack)`) and `$with`
  overrides (keyed by param name) must still evaluate at runtime each build — they do,
  because the cached classname just feeds `make()` which re-enters `resolve()`. Keep
  live: the `isDefaultValueAvailable() && !bound() && contextual===null` default branch
  (`resolveClass`, `1330`); variadics (`1244`), `SelfBuilding` (`1137`), union types
  (cache the *classification*, route to existing branches). Empty attribute set →
  `fireAfterResolvingAttributeCallbacks([])` is a provable no-op (part of the −24%);
  non-empty → call exactly as vanilla. Closures bypass (`1114`). Invalidate on `flush()`.
- **Measure:** `benchmarks/container_build_ab.php` — vanilla vs greased container, resolve
  a `Ctrl(Dep1,Dep2,Dep3,int $n=5)` tree (with `Dep3(Dep1)`) N×, assert structural
  parity (instanceof graph, default `n===5`, nested deps) before timing. Cases: default
  param, `when()->needs()->give()` contextual, a `#[Config]`-style contextual attribute,
  variadic, `makeWith` `$with` override. Oracle = vanilla object graph.
- **Delivery:** **heavy.** The container builds itself before any provider runs — no
  rebind seam. Ship `Grease\Container\Application extends Foundation\Application`
  (override `build()`); user changes one line in `bootstrap/app.php`. Drop-in
  `Grease\Container\Container` works cleanly for non-Foundation/package users.
- **Morning:** **(a)** opt-in feasibility spike first — prove a full Laravel boots green
  through `Grease\Container\Application` (the win is worthless if unshippable);
  **(b)** then the byte-identical A/B with full contextual/`$with`/attribute fidelity;
  **(c)** confirm on Linux/docker.

> **✅ BUILT & MEASURED (2026-06-23).** Tier shipped: `src/Container/{ResolvesWithGrease
> Blueprint,Container,Application}`. Parity green — `BlueprintParityTest` (12 build-path
> shapes, oracle = vanilla) + `BootParity` (fully-configured Testbench app, vanilla vs
> greased, byte-identical served response, blueprint exercised during real boot). Opt-in
> confirmed: `configure()` uses `new static`, so it's a one-line `bootstrap/app.php` swap;
> full Laravel boots + serves 200 through it.
>
> **Measured (macOS, needs Linux confirm):**
> - **−31% per transient resolve** (`container_build_ab.php`, 4-dep `Ctrl`) — confirms the
>   hypothesis.
> - **Request-level (`container_realworld.php`, real Testbench skeleton):** boot ~0%
>   (+1.4%, noise — boot is IO/provider-bound, the ~20 transient builds save ~0.15 ms of
>   21 ms); **dispatch −5.2%** (2-dep controller) to **−6.6%** (~25-build DI-heavy action).
>
> **Verdict:** byte-identical, real, but a **modest compounding tier (−5 to −7% dispatch,
> ~0% boot)** — NOT the −31% the micro-bench implied, because resolution is a thin slice of
> a request. Scales with DI volume; the whole per-request story under **Octane** (boot
> amortized). On par with shipped Eloquent micro-tiers; earns its place in the bundle, not
> a standalone headline. Exactly the "singleton-heavy apps see less" caveat above, sized.

### 🟢 HEADLINE 2 — Request `input()`/`all()` per-instance memoization
*Axis: HTTP · Confidence: high (waste) / medium (magnitude)*

`InteractsWithInput::input()` (`Concerns/InteractsWithInput.php:110`) runs
`data_get($this->getInputSource()->all() + $this->query->all(), …)` — allocates both
bag arrays and a fresh `+`-union **on every call**. `all()` (`:86`) then wraps that in
`array_replace_recursive($this->input(), $this->allFiles())` — another full
allocation + recursive merge per call. And **nearly everything funnels here**:
`__get` (`Request.php:851`), `offsetGet`/`offsetExists`, `toArray`, and most of
`InteractsWithData` (`has`/`only`/`except`/`filled`/`whenHas`…) each re-call
`all()`/`input()`. A controller + middleware + route-binding + authorization easily
rebuild the same merged array **10–30×/request**. For a GET request `getInputSource()`
*is* `query`, so the union is literally `query->all() + query->all()` — pure redundancy.

This is the one true multiplier on the HTTP axis (the per-call repetition Eloquent has).

- **Measured (macOS):** the merge step alone **6.3× cheaper** memoized (72→11 ms / 1M);
  with `data_get` + `array_replace_recursive` on top the absolute per-call saving is
  larger. Scope = **per-instance** (content differs per request), `$greaseInput` +
  `$greaseAll` cached arrays.
- **Bundle with the cheap rider:** `getInputSource()` (`Request.php:480`) + `isJson()`
  (`InteractsWithContentTypes.php:14`) re-derive on every input read (`isJson` does a
  `Str::contains` over the content-type header each call); both are per-request stable.
  Cache the resolved source-bag + `isJson` bool on the instance — removes a
  `Str::contains` + header lookup from the hottest path. Ship together with #2.
- **Parity (why core doesn't cache — bags are mutable):** invalidate the memo in every
  Laravel-level mutator: `merge` (`389`), `replace` (`420`), `offsetSet`/`offsetUnset`,
  `setJson` (`717`). **Direct bag mutation** (`$request->request->set(...)`) is not
  cheaply observable — carve it out and document "direct-bag mutation after first read
  is unsupported on a greased request" (exact analogue of the existing "per-instance
  `$casts` in a constructor isn't supported" caveat). Preserve precedence exactly: `+`
  keeps source over query; `array_replace_recursive` lets files win. `data_get`
  semantics (null vs missing, dot-keys, `*`) stay identical — only the *base array* is
  cached; it still passes through `data_get`.
- **Measure:** `benchmarks/input_ab.php` — one Request with realistic query+body, loop
  N× over a mix of `input('a')`, `__get`, `has`, `only([...])`, `all()`; assert
  identical returns before timing. Macro: add an API-ish endpoint to `realworld.php`
  reading ~15 inputs.
- **Delivery:** trait on a `Request` subclass — but the Request is created by the
  *bootstrap* (`Request::capture()` in `public/index.php`), before providers run. Best
  seam: `Request::setFactory(...)` called extremely early (prepend-autoload) so even the
  skeleton's `capture()` yields greased instances; or ship a `Grease\Http\Kernel` /
  document a `public/index.php` edit. **Heavier opt-in than a model trait** — decide
  whether the HTTP axis ships as "trait on a Request subclass you bind" (covers #2's
  repeated-work win, which is the whole point) and treats `capture()` cleanup (below) as
  optional.
- **Morning:** build `input_ab.php` + parity test, then resolve the delivery seam (it
  decides how the whole HTTP axis ships).

> **✅ BUILT & MEASURED (2026-06-23).** Tier shipped: `src/Http/{MemoizesRequestInput,
> Request}`. Memoizes the `input()`/`all()` merged base arrays + the `isJson()` bool
> per-instance; flushes on `merge`/`mergeIfMissing`/`replace`/`offsetSet`/`offsetUnset`/
> `setJson`. Direct-bag-mutation + `setMethod`-after-read are the documented carve-out.
> Parity green — `RequestInputParityTest` (57 tests, oracle = vanilla) across GET/POST/JSON
> shapes × the full accessor matrix (`input`/dot/default/`all`/`has`/`only`/`except`/
> `filled`/`__get`/`offsetGet`/`keys`/`isJson`) **and** every read→mutate→read invalidation
> sequence.
>
> **Measured (macOS, needs Linux confirm):** **−48.2%** on a fresh request + ~17-accessor
> mix (`input_ab.php`) — and that *includes* identical construction cost in both arms, so
> the isolated access win is larger. Far bigger than the container tier: `input()`/`all()`
> are a true per-request multiplier (and `only`/`except`/`has`/`filled` each re-call them
> internally, so vanilla rebuilds the merged array well over 17× per request).
>
> **Verdict:** the strongest foundation-axis lever found — a real, large, byte-identical
> per-request win on every input-touching endpoint (controllers, validation, middleware).
> **Delivery:** `Grease\Http\Request::capture()` in `public/index.php` (`capture()` builds
> via `static`, so the greased class propagates). Heavier opt-in than a model trait, but
> one line, and it's where the win is.

---

### 🟡 Secondary — real work, but once-per-request / low-µs / Octane-leaning

**S1. Middleware resolution + sort cache** *(Routing/Pipeline/Support all surfaced this
— same target). Confidence: medium.*
`Router::gatherRouteMiddleware()`/`resolveMiddleware()` (`Router.php:832-882`) +
`SortedMiddleware` (`SortedMiddleware.php:33`) run, per request for the matched route:
~3 Collection allocs + a `map/flatten/values` chain, `MiddlewareNameResolver::resolve`
per name, and a sort that calls **`class_implements()` + `class_parents()` on every
middleware string** (`SortedMiddleware.php:90-111`). The *raw* list is already cached on
the route (`Route::$computedMiddleware`); the **resolve+sort is not**. Output is a pure
function of (gathered+excluded names, the process-constant group/alias/priority maps).
Survives `route:cache` (independent of it — and *more* valuable there, since the Route
is rebuilt per request so `computedMiddleware` is cold each time → a collection-level
cache keyed by middleware-signature wins). Runs **2×/request** (dispatch + `terminate`).
Measured ~7.5 µs for the sort alone on an 8-middleware `web` stack. **But: once-per-
request, low-µs in FPM, compounds only under Octane.** Order is load-bearing — cache the
*exact* returned array keyed by the literal input arrays, no reordering logic touched.
Delivery: `Grease\Routing\Router` subclass rebound on `'router'` early (a real provider,
inherits a large stateful surface — more invasive than a trait). The
`ComponentAttributeBag` foreach-replace template applies to the Collection chain, but
the absolute saving is microseconds — **memoize, don't just de-Collection.**

**S2. Controller signature-parameter memoization** *(Routing. Confidence: high
correctness / low impact — most Grease-shaped, smallest payoff).*
The matched controller method is reflected **3× in one request**:
`ImplicitRouteBinding::resolveForRoute` calls `signatureParameters()` twice
(`ImplicitRouteBinding.php:29,79`) and `ResolvesRouteDependencies::resolveClassMethod
Dependencies` once (`:31`) — each `new ReflectionMethod(...)->getParameters()`, all
uncached (`RouteSignatureParameters::fromAction`, `Route::signatureParameters`
`Route.php:545`). Param list is immutable per process; key `"$class@$method"`. **Must be
a class-keyed static** (the blueprint pattern), *not* an instance field — under
`route:cache` the Route is rebuilt per request so an instance memo is cold. **Measured
~0.5 µs/request saved** (0.20→0.03 µs × 3 sites) — Octane-leaning, an honest near-null.
Exclude closures (not class-pure) and serialized-closure actions. Cleanest injection in
the whole axis: a `Grease\Routing\ControllerDispatcher` bound via the
`ControllerDispatcherContract` (`Route.php:1389`) — actually swappable, unlike the rest.

**S3. Config `Repository` hot-key memoization** *(Foundation. Confidence: medium-high
safe / Octane-amplified.)*
`Repository::get()` (`Config/Repository.php:51`) → `Arr::get()` (`Arr.php:481`) re-runs
the `explode('.', $key)` dot-walk on every `config('x.y')`. Config is immutable-in-
practice; writes funnel through 4 methods (`set/prepend/push/offsetSet`). A per-instance
flat memo keyed by the full key string (no dot-walk on hit) is safe with **flush-all on
any write** (`Arr::set` can shadow parents — per-key invalidation is unsafe). **Small in
FPM** (`Arr::get` already short-circuits no-dot keys; 2–3 segment walks are cheap),
**amplified under Octane** (the `config` singleton persists → requests 2..N are pure
array hits). Null-memo trap: only memo the found-key / no-default case, `array_key_exists`
not `??=` (the `CastProbes` discipline). **Cleanest opt-in on any new axis** — `config`
is a single container singleton; rebind to `Grease\Config\Repository` via a provider
registered first (rehydrate from `->all()`). Build `config_ab.php` to decide if it
clears the FPM bar; ship for the Octane story regardless.

**S4. `capture()` double-construct cleanup** *(HTTP. Confidence: medium — real but
one-time.)* `Request::capture()` (`Request.php:78`) builds a full Symfony request from
globals (walks `$_SERVER` via `ServerBag::getHeaders()`), then `createFromBase`
re-instantiates all six bags **again** (+ a second `getHeaders()` walk), plus a
**throwaway `new static`** purely to call the state-less `filterFiles()` (`:541`).
Collapse via `Request::setFactory(...)` (Symfony honors it in `createRequestFromGlobals`)
so `createFromGlobals()` yields the greased class directly, and make `filterFiles` a
static helper. **Once-per-request, low-% of a real request.** Parity: `createFromBase`
also does JSON-body promotion (`isJson()` → `request->replace(json()->all())` +
`setJson`) — a factory path must replicate that exactly (POST-JSON / multipart / `_method`
parity test). Ride the same delivery decision as Headline 2.

---

### 🔴 Negatives — investigated, ruled out, **do not re-chase**

- **Pipeline / Kernel axis as a whole (FPM):** structurally wrong for Grease. The
  middleware onion is built **once per request**, resolve+sort runs ≤2× — *no within-
  request repetition to compound*. Total axis cost ~20–30 µs (<0.5% of an FPM request).
  And **no injection seam**: `new Pipeline(...)` is hardcoded at `Kernel.php:172` and
  `Router.php:818` (the `'pipeline'` binding isn't on the request path), so greasing
  needs custom `Kernel` *and* `Router`. The global onion is request-independent and
  *could* be built once (`Kernel.php:172`, parity-safe — middleware are `make()`'d at
  execution time), but it's marginal and the route onion can't be reused (destination =
  `$route->run()`, varies). **Verdict: don't open for FPM; at most an Octane experiment,
  S1 first.** Double pipe-string parse (`MiddlewareNameResolver` → `parsePipeString`,
  `Pipeline.php:203`) is sub-µs noise.
- **Support primitives axis as a whole:** mostly **not expressible** in an opt-in
  package. The high-frequency primitives are *static helpers* (`Arr::get`/`data_get`/
  `Str::is`) and `new static` `Collection`s that the framework constructs *directly* —
  Grease has no per-instance seam to substitute them (the `ComponentAttributeBag` win
  only worked because the *Blade compiler*, a singleton Grease controls, emitted the
  code that built the first greased bag). `Str::is` compiling a regex per call
  (`Str.php`, no cache — unlike `snake`/`camel`/`studly`) is real and is exactly the
  shipped events `WildcardPattern` idea, but its request-path callers (`Request::is`,
  `Route::is`, CORS) are static and low-frequency → **upstream PR at best**, not a Grease
  tier. `operatorForWhere`/`valueRetriever` and `Macroable::__call` — inside directly-
  constructed Collections / not hot on the spine. Thin axis; credible negative.
- **Container singleton path:** already optimal — `isset($instances[$abstract]) &&
  !$needsContextualBuild` (`Container.php:922`) returns with no reflection. The only
  pre-922 waste (double `getAlias`, empty before-resolving fire, `getContextualConcrete`
  probe) is marginal *and* parity-delicate (before-resolving callbacks are observable;
  resolving callbacks must still fire on the cached-instance return at `:953`). Gate on a
  measured A/B; likely sub-2% — ship only as a free rider on Headline 1's subclass.
- **Foundation boot / merge:** `mergeConfigFrom` (`ServiceProvider.php:163`) is gated by
  `configurationIsCached()` — `php artisan config:cache` makes it a no-op in production
  (dev-only). `PackageManifest` already file-cached + in-instance memoized. The eager-
  provider registration loop (`ProviderRepository::load`, `Application:863`) is the
  largest genuine per-request boot cost but **uncacheable in shared-nothing FPM** (runs
  `register()` side-effects) and **already amortized under Octane**. Facade roots already
  cached in `static::$resolvedInstance` (`Facade.php:234`); a parallel per-class cache
  would desync `swap`/`fake`/`clearResolvedInstances`. All dead ends for FPM.
- **Routing match scan:** the no-`route:cache` linear `matches()` loop
  (`AbstractRouteCollection.php:79`) — the fix *is* `route:cache` (Symfony compiled
  matcher); Grease can't beat it byte-identically. The cached-path double-match (Symfony
  returns params, Laravel discards them, rebuilds+recompiles the Route, re-`preg_match`es
  in `RouteParameterBinder`) is theoretically the biggest cached-path waste but
  architecturally deep + high parity risk (host params, defaults, encoding) — **park
  unless the axis earns more investment.** `Route::$validators` already statically
  memoized; `compiled`/`parameterNames` already instance-memoized.
- **HTTP already-cached (don't touch):** `getMethod` (memoized `$method`), `getContent`/
  `json` (memoized), `getAcceptableContentTypes` + `expectsJson`/`wantsJson`/`prefers`/
  `format` (Laravel `cachedAcceptHeader` guard), path/host getters (Symfony `??=`).
  `JsonResponse` is a one-shot encode — the response path is genuinely thin.

---

## Delivery strategy — the decision the morning hinges on

The new axes split cleanly by opt-in cost:

| Seam | Targets | Cost |
|---|---|---|
| **Rebind a container singleton** (events/blade precedent) | Config `Repository` (S3), `Router` (S1/S2), `ControllerDispatcher` (S2) | Light — a provider |
| **Subclass `Application`, edit `bootstrap/app.php`** | Container Blueprint (Headline 1), container singleton rider | Heavy — app-file edit |
| **Bootstrap-level (`Request::setFactory` / `public/index.php` / `Grease\Http\Kernel`)** | Request `input()` memo (Headline 2), `capture()` cleanup (S4) | Heaviest — pre-provider |

Both headline wins live in the heavy tiers. So opening this territory likely means
introducing a **"Grease application/kernel" delivery model** alongside the existing
per-model trait — a documented `bootstrap/app.php` swap (one or two lines) that installs
a greased `Application` (container blueprint) and a greased request factory (input memo).
That's a bigger ask of the user than `use HasGrease;`, but it's a one-time edit and it's
where the two real wins are. **Decide this before building** — it shapes packaging.

Octane is the other strategic fork: S1/S2/S3 and the container singleton rider all move
from "marginal" to "worth it" under persistent workers. If an Octane track is on the
table, the secondaries become a coherent bundle; if not, only the two headlines clear
the FPM bar.

---

## Recommended morning plan

1. **Container Blueprint feasibility spike** (Headline 1, step a) — does a full Laravel
   boot green through `Grease\Container\Application`? This de-risks the single biggest
   win and the delivery model at once. If it boots, build `container_build_ab.php` with
   full contextual/`$with`/attribute parity and confirm the −34% on Linux/docker.
2. **In parallel, `input_ab.php`** (Headline 2) — the win is independent of the boot
   spike; build the A/B + parity test, then settle the `Request` delivery seam.
3. **Size both end-to-end** via a request-shaped macro (extend `realworld.php` with an
   input-heavy API endpoint + transient-heavy controller resolution) — the per-op deltas
   are proven; what's unknown is how much of a *real request* they move.
4. **Only then** decide whether S1–S3 (and the Octane track) earn tiers. Default stance:
   the two headlines are worth shipping for FPM; the secondaries are an Octane bundle or
   a pass.
5. **Skip entirely:** the Pipeline/Kernel axis (FPM), the Support-primitives axis, and
   every item in the negatives ledger.
