# Grease & Livewire

## Livewire stacks every tier Grease accelerates — and fires them on every click

Grease's headline numbers come from API-shaped endpoints — query, hydrate, serialize to
JSON, respond. [Livewire](https://livewire.laravel.com) works differently, and that
difference is exactly why it gets *more* out of Grease, not less. Every interaction posts a
component snapshot back to a single update route, re-hydrates the component's models,
re-renders its Blade template, and re-serializes the result into a new snapshot. A single
round-trip stacks the tiers Grease accelerates and fires them on **every interaction**
rather than once:

- **Model hydration / casting** — every update re-queries the component's models.
- **The Blade compiler** — a Livewire component *is* a Blade view, re-rendered in full on
  every `wire:click`, `wire:model` update, or polled refresh.
- **`toArray()` + date serialization** — the snapshot Livewire ships to the browser is a
  serialized view of component state.

A REST endpoint exercises the model and serialization tiers once per request. A Livewire
component exercises model + Blade + serialization on every interaction for the life of the
page. The work Grease removes is the same; Livewire just asks for it more often.

## Byte-identical through the snapshot — and the checksum

Livewire serializes component state into a **snapshot** between requests and seals it with
an HMAC **checksum**. The model attributes in that snapshot come from the exact
serialization path Grease optimizes — `toArray()`, the
[`datetime`/timestamp date tiers](/guide/serialization-helpers). Grease's one rule is that
this output stays [byte-identical to vanilla](/guide/why#the-one-rule-byte-identical-output)
— the same ISO date, the same `decimal:2` string, down to the byte — so the snapshot is
identical, the checksum is identical, and the payload round-trips exactly as Livewire
expects. A mixed greased/vanilla fleet is safe for the same reason: the snapshots match, so
there's no boundary for a checksum to straddle.

That's proved, not asserted. `tests/Livewire/LivewireParityTest` mounts a greased component
and its vanilla twin (differing only in whether the model has `HasGrease`) and checks,
across mount → action → update:

- the dehydrated snapshot **data** is byte-identical — ISO dates, the `decimal:2` string,
  the loaded relation and all;
- Livewire's own checksum generator produces the **identical HMAC** once the per-request
  random component id is held constant;
- the rendered HTML is byte-identical.

## Where the win is

The same `benchmarks/livewire_ab.php` that gates that parity then times both Livewire paths —
the initial `mount()` and the `update()` round-trip every interaction fires — across four
corners, so you can see *which* tier the saving comes from rather than just a headline. On
**mount** (hydrate the model, render, dehydrate the `toArray()` payload into a checksummed
snapshot):

| | vs vanilla |
|---|---|
| `+ HasGrease` on the model **only** | **≈ −45 to −50%** |
| `+` container / view / event tiers only | ≈ −0 to −5% |
| full greased stack | ≈ −48 to −50% |

The model trait alone carries nearly the whole delta, and the reason is mechanical: Grease's
model tier kills *per-instance* overhead — reflection, the
[class-attribute resolution](/guide/how-it-works), the `initialize*` booters — which is a
**fixed cost per `new Model()`** that doesn't shrink when the row is small. A Livewire mount
hydrates the component's model **and its loaded relations** (the bench's fixture is one user
+ eight posts — nine models), so that fixed overhead is paid nine times and greasing it lands
hard. It's the same mechanism behind the [`index_users` macro](/guide/benchmarks).

This reproduces at the same magnitude on both macOS and Linux+JIT (`benchmarks/docker`). As
everywhere in Grease — and in the spirit of the
[Octane page](/guide/octane#why-there-s-no-benchmark-table-on-this-page) — the number that
counts is the one you measure on your own components and database:

```bash
php benchmarks/livewire_ab.php
```

**What every later interaction costs depends on what the update does** — and the bench times
that too, across two component shapes. An update that **re-queries** — a data table that sorts,
paginates or filters, or any view backed by a fresh query — re-hydrates its models every time,
so the model-tier win **recurs at the scale of that query** (the bench's query-active shape lands
within a point or two of its own mount delta, on every interaction). An update that re-renders a
**cached `toArray()` array** without touching the database lets the model tier sit out — it's
cheap with or without Grease, and that's fine. The win tracks the work: an interaction that does
model work gets greased every time; one that does none costs nothing either way. So the more your
components actually *do* per click, the more this is doing for you.

## Getting started

There's nothing Livewire-specific to install or configure. Add `HasGrease` to the
[Eloquent models](/guide/getting-started) your components use — that's it. The
[Blade](/guide/blade), [container](/guide/container), and [event](/guide/events) tiers all
apply unchanged (Livewire renders Blade and resolves through the container like everything
else) and compound on top. The four-corner number is unambiguous about the priority: **if you
take nothing else from this package, put `HasGrease` on the models your components touch.** On
a component-heavy page that single trait is where the request goes; the rest is compounding
upside.
