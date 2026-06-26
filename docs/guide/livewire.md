# Grease & Livewire

## Livewire is a better fit than the benchmarks suggest, not a worse one

Grease's headline numbers come from API-shaped endpoints — query, hydrate, serialize to
JSON, respond. [Livewire](https://livewire.laravel.com) doesn't work that way: it has no
traditional REST endpoint. Every interaction posts a component snapshot back to a single
update route, re-hydrates the component's models, re-renders its Blade template, and
re-serializes the result into a new snapshot. A fair question is whether a package tuned
on JSON endpoints does anything at all for that.

It does — and arguably *more* than for a plain API, because a Livewire round-trip stacks
the tiers Grease accelerates and fires them on **every interaction** rather than once:

- **Model hydration / casting** — every update re-queries the component's models.
- **The Blade compiler** — a Livewire component *is* a Blade view, re-rendered in full on
  every `wire:click`, `wire:model` update, or polled refresh. An API never touches Blade.
- **`toArray()` + date serialization** — the snapshot Livewire ships to the browser is a
  serialized view of component state.

A REST endpoint exercises the model and serialization tiers once per request. A Livewire
component exercises model + Blade + serialization on every interaction for the life of the
page. The work Grease removes is the same; Livewire just asks for it more often.

## The one tier that buys less

[The Request input memoization](/guide/request) is tuned for the form-field read pattern —
`$request->input('email')` funnelled through repeatedly. Livewire's update requests don't
read input that way: they post a single `components` payload that Livewire's own machinery
parses, not via `$request->input()`. So on the *update* path that tier has less to amortize
(the initial full-page load is a normal request and benefits normally). It doesn't hurt —
invalidation stays correct — it just isn't the lever it is on a classic form post. Every
other tier lands.

## The part that could have broken — and didn't

Livewire serializes component state into a **snapshot** between requests and seals it with
an HMAC **checksum**. The model attributes in that snapshot are produced by the exact
serialization path Grease optimizes — `toArray()`, the
[`datetime`/timestamp date tiers](/guide/serialization-helpers). If a greased model
serialized even one byte differently from vanilla — an ISO date formatted differently, a
decimal string rendered another way — the snapshot would differ, the checksum would differ,
and the next request would reject the payload with a corruption exception. In a rolling
deploy with some workers greased and some not, *every* request would fail.

That's the same promise Grease makes everywhere —
[byte-identical output](/guide/why#the-one-rule-byte-identical-output) — and Livewire is no
exception. It's proved, not asserted: `tests/Livewire/LivewireParityTest` mounts a greased
component and its vanilla twin (differing only in whether the model has `HasGrease`) and
checks, across mount → action → update:

- the dehydrated snapshot **data** is byte-identical — ISO dates, the `decimal:2` string,
  the loaded relation and all;
- Livewire's own checksum generator produces the **identical HMAC** once the per-request
  random component id is held constant;
- the rendered HTML is byte-identical.

Because the snapshots are identical, a mixed greased/vanilla fleet is safe: there's no
boundary for a checksum to straddle.

## What it's worth — and where the win actually is

The same `benchmarks/livewire_ab.php` that gates the parity above then times a
`Livewire::mount()` — hydrate the model, render the component, dehydrate its `toArray()`
payload into a checksummed snapshot — across four corners, so you can see *which* tier the
saving comes from rather than just a headline:

| | vs vanilla |
|---|---|
| `+ HasGrease` on the model **only** | **≈ −45 to −50%** |
| `+` container / view / event tiers only | ≈ −0 to −5% |
| full greased stack | ≈ −48 to −50% |

The surprise is that **the model trait alone carries nearly the whole delta** — the
foundation tiers are a thin slice on top, because a single component resolve is a small
fraction of the work (the [container tier](/guide/container) shaves a request elsewhere,
not here). Why is one trait worth half a mount? Because Grease's model tier kills
*per-instance* overhead — reflection, the [class-attribute resolution](/guide/how-it-works),
the `initialize*` booters — which is a **fixed cost per `new Model()`** that doesn't shrink
when the row is small. A Livewire mount hydrates the component's model **and its loaded
relations** (the bench's fixture is one user + eight posts — nine models), so that fixed
overhead is paid nine times and greasing it lands hard. It's the same mechanism behind the
[`index_users` macro](/guide/benchmarks).

Trust the direction, not the digits. This reproduces at the same magnitude on both macOS and
Linux+JIT (`benchmarks/docker`), but — in the spirit of the
[Octane page](/guide/octane#why-there-s-no-benchmark-table-on-this-page) — the honest number
is the one you measure on your own components and database:

```bash
php benchmarks/livewire_ab.php
```

A Livewire update re-runs this whole path on every interaction, so whatever delta you measure
on mount recurs for the life of the page — not just on first paint.

## Getting started

There's nothing Livewire-specific to install or configure. Add `HasGrease` to the
[Eloquent models](/guide/getting-started) your components use — that's it. The
[Blade](/guide/blade), [container](/guide/container), and [event](/guide/events) tiers all
apply unchanged (Livewire renders Blade and resolves through the container like everything
else), and they're worth turning on — but the four-corner number above is unambiguous about
the priority: **if you take nothing else from this package, put `HasGrease` on the models your
components touch.** On a component-heavy page that single trait is where the request goes; the
rest is compounding upside.
