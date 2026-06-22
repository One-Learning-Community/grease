---
layout: home

hero:
  name: Grease
  text: Get greased.
  tagline: Opt-in performance for Laravel's hot paths. One trait, byte-identical output, real requests measurably faster — end to end. Built from optimizations declined upstream, packaged where you can opt in.
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
  - icon: 🧈
    title: One trait. That's the install.
    details: "Add `use HasGrease;` to a model and its hot paths get faster. No config, no migration, no framework patch. Leave every other model untouched."
  - icon: 🪞
    title: Byte-identical output
    details: Asserted across every cast type, edge value, and dirty-check in the parity suite. Same bytes as vanilla Eloquent — or it's a failing test. That promise is the whole product.
  - icon: ⚡
    title: Per-op wins that stack
    details: "hydrate −34% · toArray −47% · set+dirty −39% · read −27% · enum cast −48% · date serialization −87%. Marginal in isolation; compounded on a real request, they aren't."
  - icon: 📦
    title: Real requests, end to end
    details: "Big end-to-end deltas on in-memory workloads (−18% to −78% across list, eager-load, mutate-save, show) — SQL included, not a micro-benchmark. Read them as Grease's share of the ORM work; the portable figure is the absolute time removed."
  - icon: 🔌
    title: Beyond Eloquent — a faster dispatcher
    details: "A drop-in event dispatcher that speeds up every dispatch app-wide — model events, views, cache, your own. −53% on no-listener dispatch; roughly halves the event overhead of a render-dense request. A second reason to install Grease, even if Eloquent isn't your bottleneck."
  - icon: 🧩
    title: Zero framework changes
    details: Non-greased models run pure vanilla Eloquent — Grease adds zero cost to anything that doesn't opt in. PHP 8.2+, Laravel 12 / 13.
---

<div style="max-width: 960px; margin: 4rem auto 0; padding: 0 24px;">

## The whole install

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

That's it. Eloquent re-derives the same class-pure facts on *every* attribute access
and *every* hydrated row — rebuilding the casts array, re-walking a cast `switch`,
re-probing `method_exists` for mutators, re-resolving the date format, running a
fresh `ReflectionClass` per `new Model`. None of it changes for the life of the
class. **Grease computes each fact once per class and reuses it.**

Each of these was proposed to Laravel core and declined on reasonable grounds — every
one "marginal in isolation." Individually, maybe. Bundled, on a request that hydrates
a hundred rows and serializes them to JSON, they move the number you actually pay for.
Grease is where that declined work lives — [see the history](/guide/why#where-these-came-from).

<div style="text-align:center; margin: 3rem 0 1rem; font-size: 1.05rem;">

**Byte-identical to vanilla, or it's a failing test.**

**[Get greased →](/guide/getting-started)**

</div>

</div>
