# The Request

A *different axis* again — the HTTP request. Where the container tier shaves a thin slice
of a request, this one targets work a request repeats **many times**: reading input.

## What it does

Vanilla `Request::input()` rebuilds the merged input map —
`getInputSource()->all() + query->all()` — on **every** call, and `all()` wraps that in a
fresh `array_replace_recursive(..., allFiles())` every call. Almost every accessor funnels
through them: `__get`, `offsetGet`, `offsetExists`, `toArray`, and most of the
`has`/`only`/`except`/`filled`/`whenHas` family (each of which re-calls `all()`/`input()`
internally). A single controller + middleware + form-request validation easily rebuilds
the same array a dozen-plus times. `isJson()` re-scans the content-type on every input
read on top.

Grease memoizes the merged base arrays and the `isJson()` verdict **per request
instance**, and invalidates them on every mutation. The merged map is a pure function of
the input bags, stable once middleware has run; `data_get` semantics, query/body
precedence, null-vs-missing — all preserved exactly, because only the *base array* is
cached and it still passes through `data_get`.

## Does it clear the cache when the request changes?

Yes — every observable mutation path is tracked:

- **Value mutators** flush the input maps: `merge`, `mergeIfMissing`, `replace`,
  `offsetSet`, `offsetUnset`, `setJson`.
- **Lifecycle paths** flush as well: `__clone()` (and therefore `duplicate()` — which
  Laravel uses internally, e.g. under route caching), `initialize()`, and `setMethod()`
  (which rewrites `REQUEST_METHOD`, flipping the input source between the query and request
  bags). `__clone`/`initialize` also drop the `isJson` memo, since they can change the
  content-type.

This was audited path by path against vanilla, and each is pinned by a parity test that
fails if the memo isn't invalidated.

### The one carve-out

**Direct mutation of an input-source bag** — `$request->query->add(...)`,
`$request->request->set(...)`, `$request->json()->set(...)` — bypasses every method and
isn't cheaply observable, so it's unsupported after the first input read on a greased
request. Use the Laravel-level mutators (`merge()`, `$request['key'] = …`) instead, which
the memo tracks. (This can't be locked down with property visibility: PHP forbids
narrowing an inherited property, these are PHP 8.4 hooked properties, and the framework
itself reads the bags publicly — and visibility wouldn't even catch a method call on the
bag object anyway.)

Bags **outside** the input surface are entirely safe to mutate directly — notably
`$request->attributes->set(...)`, the common middleware pattern for attaching request
context: `attributes` feeds none of `input()`/`all()`/`isJson()`. So does `cookies`.

## Behaviour-identical, by test

Parity is the returned values, asserted against vanilla across GET/POST/JSON request
shapes × the full accessor matrix (`input`/dot-keys/defaults/`all`/`has`/`only`/`except`/
`filled`/`__get`/`offsetGet`/`keys`/`isJson`), plus every read→mutate→read invalidation
sequence and the lifecycle paths above.

## What it's worth

Measured on Linux (`benchmarks/docker`, opcache + JIT): **−41%** on a fresh request that
runs a representative ~17-accessor mix — and that *includes* identical request-construction
cost in both arms, so the isolated input-access win is larger. This is the strongest
foundation-axis lever, because input reading is a genuine per-request *multiplier* (every
input-touching endpoint benefits — controllers, validation, middleware).

## Memory

Two small arrays plus a bool, per request instance, discarded with the request. In the
cumulative pipeline benchmark it's a fraction of a percent of retained memory.

## Opt in

Like the container, the request is created by the bootstrap before any provider runs, so
opt in at the capture site — in `public/index.php`:

```php
// instead of: $request = Illuminate\Http\Request::capture();
$request = Grease\Http\Request::capture();
```

`capture()` builds via `static`, so the greased class propagates through the request
lifecycle (the container binds this instance as `request`). One line, one narrow caveat —
check your app doesn't poke the raw input bags directly and you're done.
