# The Container

A *different axis* from the model traits — and a more invasive opt-in. The traits make
individual models faster; this makes Laravel's **dependency container** faster, on every
transient resolution. It's a drop-in subclass of the framework's own container.

## What it does

Vanilla `Container::build()` rebuilds, on **every** resolve of a non-singleton, work that
is a pure function of the class name:

- a fresh `ReflectionClass`,
- `getConstructor()` and `getParameters()`,
- per parameter, `Util::getParameterClassName()` and the contextual-attribute walk.

None of it changes for the life of the process — a class's constructor signature is
immutable. Grease freezes that into a per-class **constructor blueprint** and replays it.
The runtime-varying parts — contextual bindings, `$with` overrides, default-vs-bound
checks, the resolving callbacks — still execute exactly as vanilla, so resolution stays
behaviour-identical. It caches *reflection, not resolution*: a binding added or changed
after the blueprint is warmed is still honored.

Closures, abstract/non-instantiable types, self-building types, and missing classes fall
straight through to `parent::build()` untouched.

## Behaviour-identical, by test

The parity bar is the resolved object graph: the same instances, defaults, contextual
bindings, `$with` overrides, attributes, and variadics — and the same exceptions on the
unresolvable paths. That contract is asserted against the vanilla container across every
build-path shape, plus a full-boot test that serves a real request through a greased
application and checks the response is byte-identical to vanilla.

## What it's worth

Measured on Linux (`benchmarks/docker`, opcache + JIT):

| Scenario | Δ |
| --- | :---: |
| single transient resolve (4-dep controller) | **−38.8%** |
| app boot (provider/service resolution) | **~−5%** |
| request dispatch — light controller (2 deps) | **−5.4%** |
| request dispatch — DI-heavy action (~25 builds) | **−7.9%** |

Be clear-eyed: the **−38.8% is per resolve**, and resolution is a *thin slice* of a real
request (middleware, routing, the response, and SQL dominate). On a normal endpoint the
container tier moves the request a few percent — it's a *compounding* tier, on par with
the smaller Eloquent tiers, not a standalone headline. Its value rises with how many
transients a request resolves (form requests, policies, jobs, listeners), and it is the
**whole per-request story under Octane**, where boot is amortized and dispatch is all
that's left. Singleton-heavy resolution sees little — singletons hit the instance cache
and never reach `build()`.

## Memory

The blueprint is one plan per resolved class — kilobyte-scale, and negligible against a
request's working set. In the cumulative pipeline benchmark, adding the container tier
moves retained memory by a fraction of a percent.

## Opt in

Unlike the events and Blade tiers (which rebind a container singleton from a provider),
the container **builds itself before any provider runs** — so it can't be swapped from
inside the app. It's a one-line change at the application's construction site.

**Foundation apps** — in `bootstrap/app.php`, build on the greased application:

```php
// instead of: return Illuminate\Foundation\Application::configure(...)
return Grease\Container\Application::configure(basePath: dirname(__DIR__))
    ->withRouting(...)
    ->withMiddleware(...)
    ->withExceptions(...)
    ->create();
```

`configure()` constructs via `new static`, so the greased application propagates through
the entire boot and request lifecycle.

**Packages / custom kernels** — use the drop-in container directly:

```php
$container = new Grease\Container\Container;
```

This is the most invasive Grease opt-in — an application-file edit, not a trait or a
provider. It carries no caveat beyond that, and the parity suite holds. If you're not
confident, you don't have to take it: every other tier works without it.
