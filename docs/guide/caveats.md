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

This is an undocumented internal — there's no idiomatic reason to override it.

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

## The foundation tiers (container, request, config, router & view index)

These are a *different axis* from the model traits, with their own opt-in — see
[The Container](/guide/container), [The Request](/guide/request),
[The Config Repository](/guide/config), [The Router](/guide/routing), and
[The View Cache](/guide/view-cache). Their narrowing is minimal:

- **Container** — none beyond the opt-in itself. The blueprint caches reflection, not
  resolution, so contextual bindings, `$with` overrides, and late rebinds all stay live;
  the resolved object graph is asserted byte-identical to vanilla.
- **Request** — exactly one carve-out: **direct mutation of an input-source bag** after the
  first input read — `$request->query->add(...)`, `$request->request->set(...)`,
  `$request->json()->set(...)`. Use the Laravel-level mutators (`merge()`,
  `$request['key'] = …`) instead; the memo tracks those, and the lifecycle paths
  (`clone`/`duplicate`, `initialize`, `setMethod`) too. Mutating bags *outside* the input
  surface is fully safe — including `$request->attributes->set(...)`, the common middleware
  pattern, and `cookies`.
- **Config** — exactly one carve-out: **out-of-band mutation of `$items`**, a macro or
  reflection writing the protected array directly instead of going through `set()` (which the
  caches track, along with `prepend`/`push`/`offsetSet`/`offsetUnset`). Vanishingly rare;
  `flushConfigMemo()` is the explicit hook if you ever do it. The `grease:config-cache` flat
  index additionally only engages while it's fresh relative to the config cache — a later plain
  `config:cache` or `config:clear` disables it automatically, so a stale index is never served.
- **Router** — the lazy middleware cache has **no real carve-out** (the only one, a *direct*
  `$router->middlewarePriority = …` write bypassing the registration methods, can't bite in
  practice — Laravel's own `syncMiddlewareToRouter()` immediately re-syncs aliases/groups, which
  flush, and runs before dispatch). The eager `grease:route-cache` index adds exactly one contract:
  **the alias/group/priority maps must be the same at build time and run time.** Concretely, don't
  *gate middleware registration* on the environment — a provider that registers middleware only
  under `runningInConsole()`, or an env/flag-conditional alias like `throttle` → Redis-vs-sync, would
  make the cached resolution disagree with serving. Rebuild on every deploy; the freshness guard
  disables a stale index after any `route:cache` / `route:clear` / `config:cache` / `optimize`, and
  it's inert in development (no route cache) — so a wrong list is never served, you only lose the
  eager win until you re-run `grease:route-cache`. A route whose middleware is assigned dynamically
  simply misses the index and resolves live.
- **View index** — the lazy Blade greasing (the `view`/`blade.compiler` swaps) has **no
  carve-out**. The eager `grease:view-cache` index adds the same one contract as the others:
  **build == runtime** — it's a deploy artifact, so rebuild it on deploy. A structural view change
  (add / move / delete) needs a rebuild, exactly like `view:cache` itself (in-place edits don't —
  the name→path mapping is unchanged and recompilation stays with the framework). The freshness
  guard disables a stale index after any `view:cache` / `config:cache` / `optimize`; it's inert in
  development (no artifact); and a name the index doesn't contain — added, dynamic, or non-Blade —
  resolves live, byte-identical. So a wrong path is never served.

All five are opt-in independently of everything else. Not confident? Don't take them — the
model, event, and Blade tiers all work without them.

## Want zero cast caveats at all?

Use the tiers à la carte and skip the cast-metadata tiers. You keep the hydration win —
which carries **no** behavioural narrowing — and the cast path stays 100% vanilla:

```php
use Grease\Concerns\HasGreasedHydration;

class User extends Model
{
    use HasGreasedHydration;   // construct / hydration
}
```

That's the design philosophy in one snippet: opt in to exactly the speed you want,
keep exactly the flexibility you use.
