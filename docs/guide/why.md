# Why Grease

## The same facts, recomputed forever

Eloquent is fast enough for most apps. But at scale — endpoints that hydrate
hundreds of rows, eager-load relations, and serialize wide JSON on every request —
it spends a surprising amount of time re-deriving facts that never change.

For a single model class, the cast map is fixed. The date format is fixed. Which
attributes have mutators is fixed. The cast *type* of each key is fixed. Yet
Eloquent recomputes all of it **per attribute access** and **per hydrated row**:

- a fresh `ReflectionClass` for every `new Model`,
- the casts array rebuilt (`array_merge`) on every `getCasts()`,
- a `switch` re-walked on every `castAttribute()`,
- `method_exists` re-probed for every mutator check,
- the connection's date format re-resolved for every date cast,
- a Carbon parse-and-reformat round-trip for every serialized timestamp.

None of it changes for the life of the class. **Grease computes each fact once per
class and reuses it** — and nothing else.

## "Marginal in isolation"

Optimizations like these are declined upstream on consistent, reasonable grounds:
each is *marginal in isolation*, and core carries a stability and maintenance cost for
every branch it adds to everyone's hot path. That's a fair call — a 2% saving on one
method isn't worth a permanent `if` in every app that will never notice it.

But that framing measures the wrong thing. You don't ship one method — you ship a
*request*. And a request that lists 100 models and serializes them to JSON pays
every one of those taxes, hundreds of times, on the same handful of class-pure
facts. Bundle the optimizations and skip the "is the cache on?" branch entirely
(every greased model takes the fast path; every other model is pure vanilla), and
the marginal wins stop being marginal.

> Individually, maybe. **Together, on a real request, they aren't.**

## Where these came from

Grease isn't theoretical. Several tiers began as pull requests to Laravel core, closed
unmerged — most of them on the reasoning above. The attempts span Laravel 9 through
13, because the optimizations kept measuring as real:

- **Attribute casting** — [#43554](https://github.com/laravel/framework/pull/43554)
  (9.x), [#60550](https://github.com/laravel/framework/pull/60550) (13.x)
- **`getDateFormat()` caching** — [#55129](https://github.com/laravel/framework/pull/55129) (12.x)
- **Event dispatcher** — [#51184](https://github.com/laravel/framework/pull/51184) (11.x)

An opt-in package is the right home for a change that's a clear win for some apps and
unnecessary weight for the framework. Grease is where this work lives now — and where
new optimizations land, instead of a closed tab.

## The one rule: byte-identical output

The whole product rests on a single promise: **a greased model produces output
byte-identical to vanilla Eloquent.** Same values, same types, same JSON, same
dirty-tracking — down to the byte.

That isn't a hope; it's a test suite. Every cast type, every edge value, every
null, every dirty comparison is asserted equal to vanilla across PHP 8.2–8.5 and
Laravel 12/13. The benchmarks run the *same fixtures the parity tests prove
identical*, so a number you read is a number you can trust.

Where Grease can't guarantee byte-identity for an exotic case, it **defers to
vanilla** — correct, just unaccelerated. Acceleration is never bought with
correctness. See [Caveats & Narrowing](/guide/caveats) for the two small things
that change, and why they don't matter in practice.

## Who it's for

Grease is for the app that has outgrown "Eloquent is fast enough" — the API serving
wide JSON, the dashboard hydrating big collections, the queue worker chewing
through rows. If your profiler points at hydration, casting, serialization, container resolution, request input, config or route resolution, query compilation, or Blade rendering,
this is the package every deploy at scale should be reaching for.

If you're not there yet, you don't need it — and that's fine. Add it to the models
that hurt, when they hurt.

[Get started →](/guide/getting-started)
