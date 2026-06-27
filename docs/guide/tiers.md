# Tiers at a Glance

Grease is a menu, not a monolith — a set of independent opt-ins, each removing a stable fact
Laravel recomputes per row, render, request, or query. This page ranks them by **what it costs
you to turn on**, **what you're promising** (almost always nothing), and **what it's worth**.

::: tip If you read nothing else
Add **`HasGrease`** to your models. Then add **`HasGreasedAcyclicSerialization`** too — unless
your models are self-referential trees. Those two are the no-brainers: trivial to enable,
byte-identical, and they carry the biggest, broadest wins.
:::

**Legend** — Gain is the expected impact *on the workload the tier targets* (not a universal
request-wide number; see each page for the measured figure). 🔥 small or conditional ·
🔥🔥 solid · 🔥🔥🔥 broad and large. Risk **None** means byte-identical to vanilla with nothing
to promise; the parity suite proves it.

## Start here — the no-brainers

Add a trait to your models. Nothing to configure, nothing app-wide.

| Tier | Speeds up | Enable | Risk | Gain |
| --- | --- | --- | --- | --- |
| **Model** ([how](/guide/how-it-works)) | the whole model read/write path — hydration, casting, dates, serialization, dirty-check, m2m pivots | `use HasGrease;` | None | 🔥🔥🔥 |
| **[Acyclic serialization](/guide/acyclic-serialization)** | `toArray` / queue / touch — drops the `debug_backtrace` recursion guard | `use HasGreasedAcyclicSerialization;` | You promise no self-referential graphs | 🔥🔥🔥 |

## Add a trait — per model, à la carte

Independent model opt-ins, deliberately *not* in `HasGrease` because they're narrow or carry a
promise.

| Tier | Speeds up | Enable | Risk | Gain |
| --- | --- | --- | --- | --- |
| **[Decimal casts](/guide/decimal-casts)** | `decimal:N` reads — skips the Brick\Math round-trip | `use HasGreasedDecimalCasts;` | None (fires on MySQL/PostgreSQL) | 🔥 — decimal-dense financial models |
| **Builder dispatch** | Eloquent builder `__call` verb resolution | `use HasGreasedQueries;` | None | 🔥 — query-construction-heavy paths |

## Register a provider — app-wide

One line in a service-provider list. None are auto-discovered.

| Tier | Speeds up | Enable | Risk | Gain |
| --- | --- | --- | --- | --- |
| **[Events](/guide/events)** | every dispatch | `GreaseEventServiceProvider` | None | 🔥🔥 |
| **[Blade](/guide/blade)** | `@props` + attribute-merge per component | `GreaseViewServiceProvider` | None | 🔥🔥 |
| **[Config](/guide/config)** | `config()` reads (scales with call volume) | `GreaseConfigServiceProvider` (+ `grease:config-cache`) | None | 🔥🔥 |
| **[Validation](/guide/validation)** | rule parsing per validation | `GreaseValidationServiceProvider` | None | 🔥 — validating endpoints |
| **[Router + URL](/guide/routing)** | middleware resolve+sort, `route()` assembly | `GreaseRoutingServiceProvider` (+ `grease:route-cache`) | None | 🔥 — compounds with request volume |
| **[View cache](/guide/view-cache)** | view name→path resolution | same provider + `grease:view-cache` | None | 🔥 |

## One line at the app entry — heavier opt-in

These tiers live below the provider layer (the container and request are built before any
provider runs), so they need a one-line edit in your app's bootstrap. Worth it for DI- or
input-heavy apps, and the whole story under Octane.

| Tier | Speeds up | Enable | Risk | Gain |
| --- | --- | --- | --- | --- |
| **[Container](/guide/container)** | constructor reflection per service resolve | `Grease\Container\Application` in `bootstrap/app.php` | None | 🔥🔥 — compounds under Octane |
| **[Request](/guide/request)** | `input()` / `all()` per access | `Grease\Http\Request::capture()` in `public/index.php` | None | 🔥🔥 |

---

Every tier is a method override that falls back to `parent::`, so a non-greased model — or a
tier you didn't opt into — is untouched. See [Benchmarks](/guide/benchmarks) for the
cumulative-stack numbers when several are layered, and [Caveats](/guide/caveats) for the precise
narrowings.
