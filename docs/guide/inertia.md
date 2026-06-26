# Grease & Inertia

## On the server, an Inertia visit *is* an API request — Grease greases it the same way

[Inertia](https://inertiajs.com) moves rendering to Vue/React on the client, but the server
half never changes shape. A controller queries its models, serializes them into props, and
returns JSON:

```php
return Inertia::render('Users/Index', [
    'users' => User::with('posts')->get(),
]);
```

On the PHP side that's query → hydrate → serialize → respond — the exact path the
[benchmarks](/guide/benchmarks) and `realworld.php` already measure on a plain JSON endpoint.
Every visit is a fresh request; there's no persistent server-side component state. So the tiers
land precisely as they do on an API route:

- **Model hydration / casting** — the controller queries the models behind the props.
- **`toArray()` + date serialization** — Inertia serializes props to JSON through the same path,
  so a model or resource prop runs the [date/cast tiers](/guide/serialization-helpers).
- **Container / request / router / events** — the request envelope, greased like any route.

There's nothing Inertia-specific to install or configure: the model + serialization tiers are
your props, the foundation tiers are the request. Add `HasGrease` to the models your controllers
return and the existing [API numbers](/guide/benchmarks) are your numbers.

## The easy case — no snapshot, no checksum

Where [Livewire](/guide/livewire) ships a checksummed snapshot of model state between requests —
a real place a one-byte serialization drift could break rehydration — Inertia carries no such
thing. Props are plain JSON in the response, regenerated from scratch every visit; nothing is
HMAC-sealed, no state round-trips through the browser. So there's no byte-identity subtlety to
reason about beyond Grease's standing promise that serialized output is
[byte-identical to vanilla](/guide/why#the-one-rule-byte-identical-output) — which every tier
already keeps. Partial reloads (`only`/`except`) just send a subset of those same props: still a
fresh request, fewer props serialized.

(Inertia's SSR renders Vue/React in a Node process — Grease is a PHP package and doesn't touch
it. The PHP side still only produces the props JSON, and that's what gets greased.)

## Getting started

Add `HasGrease` to the [Eloquent models](/guide/getting-started) your controllers pass as props —
that's the whole story. The [Blade component tier](/guide/blade) barely applies (Inertia's only
Blade is the root `app.blade.php` rendered once on the first load; every visit after is JSON), but
the [container](/guide/container), [request](/guide/request), [router](/guide/routing), and
[event](/guide/events) tiers all apply to the request like any other route and compound on top. An
Inertia app is an API app wearing a SPA — greasing it is the same work, in the same place.
