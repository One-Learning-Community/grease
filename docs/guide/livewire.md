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

## What it's worth

The same `benchmarks/livewire_ab.php` that gates the parity above then times a
`Livewire::mount()` — the path that hydrates the model, renders the component, and
dehydrates its `toArray()` payload into a checksummed snapshot — on the full greased stack
(greased [container](/guide/container), [view](/guide/blade), and
[event](/guide/events) tiers plus a greased model) versus vanilla.

On a Mac that lands around **−48%** on the mount path. Trust the direction, not the digits:
as everywhere in these docs, [macOS distorts the magnitude](/guide/benchmarks) — reproduce
it on your own stack:

```bash
php benchmarks/livewire_ab.php
```

And, in the spirit of the [Octane page](/guide/octane#why-there-s-no-benchmark-table-on-this-page):
the honest number is the one you measure on your own components, against your own database,
with your own worker model. A Livewire update re-runs this whole path on every interaction,
so whatever delta you measure on mount recurs for the life of the page — not just on first
paint.

## Getting started

There's nothing Livewire-specific to install or configure. Add `HasGrease` to the
[Eloquent models](/guide/getting-started) your components use, and — if you want the view
and foundation tiers too — opt into the [Blade](/guide/blade),
[container](/guide/container), and [event](/guide/events) tiers exactly as you would for a
classic app. Livewire renders Blade and resolves through the container like everything else,
so those tiers apply unchanged. The model trait alone is enough to justify the experiment on
a component-heavy page; the rest is compounding upside.
