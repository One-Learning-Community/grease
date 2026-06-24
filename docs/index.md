---
layout: home

hero:
  name: Grease
  text: Get greased.
  tagline: Opt-in performance across Laravel's hot paths — a menu of independent, byte-identical tiers spanning the whole request lifecycle, from Eloquent to the router, the view finder, and the query grammar. Take the ones whose hot paths you run. Built from optimizations declined upstream.
  image:
    src: /logo.svg
    alt: Grease
  actions:
    - theme: brand
      text: Get Started
      link: /guide/getting-started
    - theme: alt
      text: Why Grease?
      link: /guide/why
    - theme: alt
      text: View on GitHub
      link: https://github.com/One-Learning-Community/grease

features:
  - icon: 🍱
    title: A menu, not a monolith
    details: "Independent, opt-in tiers across Laravel's hot paths — model, events, Blade, config, validation, query grammar, container, request, router. Take the ones whose hot paths you run; skip the rest. The model trait is the zero-config on-ramp, not the whole package."
  - icon: 🪞
    title: Byte-identical output
    details: "Same bytes as vanilla — or it's a failing test. Asserted across every tier in the parity suite: every cast, edge value, dirty-check, resolved SQL string, served response. That promise is the whole product."
  - icon: 🧱
    title: Compute once, reuse
    details: "Every tier removes the same waste — work that's a pure function of stable inputs, recomputed per row, per attribute, per component, per render, per request, per query. Grease computes each fact once and reuses it."
  - icon: 🚀
    title: Across the whole request lifecycle
    details: "Container resolution → request input → route-middleware resolve → config reads → query compilation → hydration & casting → Blade render → serialization. A faster path at each stage, all stacking on one real request."
  - icon: ⚡
    title: The model trait is the easiest win
    details: "hydrate −34% · toArray −47% · set+dirty −39% · read −27% · enum cast −48% · date serialization −87%. `use HasGrease;` and done — no config, no provider. Marginal in isolation; compounded on a real request, they aren't."
  - icon: 🧊
    title: Eager caches make once-per-request work ~free
    details: "`grease:config-cache`, `grease:route-cache`, `grease:view-cache` — drop-in twins of Laravel's `*:cache` that precompute resolution into opcache-interned files. Config reads, middleware, and view lookups become hash hits, server-wide."
  - icon: 🧩
    title: Zero cost to what doesn't opt in
    details: "Nothing is auto-discovered beyond the trait you add. Every tier is a deliberate opt-in; code that doesn't take one runs pure vanilla. PHP 8.2+, Laravel 12 / 13."
---

<div style="max-width: 960px; margin: 4rem auto 0; padding: 0 24px;">

## Start with one trait

```php
use Grease\Concerns\HasGrease;

class User extends Model
{
    use HasGrease; // [!code ++]
}
```

**An endpoint that lists 100 users and serializes them to JSON drops from 3.12 ms to
0.69 ms — real SQL included, not a micro-benchmark.** That's `:memory:` SQLite, where
Eloquent is most of the request; against a networked database the same ~2.4 ms come off
a larger total, so the *percentage* is smaller while the *time removed* holds
([how to read these honestly](/guide/benchmarks#how-to-read-these-honestly)).

That's **one tier.** The model trait is the easiest win and a fine place to stop — but
Grease is a menu, and the rest of it lives across the request, not just in Eloquent:

- **Model** — `use HasGrease;` (or extend `Grease\GreasedModel`): hydration, casting, serialization, dirty-checking. Per-row × per-attribute. Zero config.
- **Providers** you register — a faster [event dispatcher](/guide/events), [Blade compiler](/guide/blade), [config repository](/guide/config), validator, and query grammar: each one line in `bootstrap/providers.php`.
- **App-entry swaps** — a greased [container](/guide/container), [request](/guide/request), and [router](/guide/routing): a one-line edit where each is constructed, before any provider runs.
- **Deploy caches** — `grease:config-cache`, `grease:route-cache`, `grease:view-cache`: drop-in twins of Laravel's own `*:cache` that take once-per-request resolution to ~free.

The same idea runs under all of them. Laravel re-derives the same *stable* facts over and
over — the casts array and a fresh `ReflectionClass` per hydrated row, the merged input map
on every `$request->input()`, a config key's dot-walk on every `config()`, a route's
middleware resolve-and-sort and a view's name→path stat-walk on every request, an
identifier's quoting on every query. None of it changes for the life of the process.
**Grease computes each fact once and reuses it** — byte-for-byte the same result, a fraction
of the work.

Each of these was proposed to Laravel core and declined on reasonable grounds — every
one "marginal in isolation." Individually, maybe. Layered on a real request — booted,
routed, hydrated, rendered, serialized — they move the number you actually pay for.
Grease is where that declined work lives — [see the history](/guide/why#where-these-came-from).

<div style="text-align:center; margin: 3rem 0 1rem; font-size: 1.05rem;">

**Byte-identical to vanilla, or it's a failing test.**

**[Get greased →](/guide/getting-started)**

</div>

</div>
