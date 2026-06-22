# Why Grease

## The wins upstream won't merge

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

Each of these has been proposed to Laravel core, more than once, and declined on the
same reasonable grounds: the win is *marginal in isolation*, and core carries a
stability and maintenance cost for every branch it adds. Fair. A 2% saving on one
method is not worth a permanent `if` in everyone's hot path.

But that framing measures the wrong thing. You don't ship one method — you ship a
*request*. And a request that lists 100 models and serializes them to JSON pays
every one of those taxes, hundreds of times, on the same handful of class-pure
facts. Bundle the optimizations and skip the "is the cache on?" branch entirely
(every greased model takes the fast path; every other model is pure vanilla), and
the marginal wins stop being marginal.

> Individually, maybe. **Together, on a real request, they aren't.**

## The one rule: byte-identical output

The whole product rests on a single promise: **a greased model produces output
byte-identical to vanilla Eloquent.** Same values, same types, same JSON, same
dirty-tracking — down to the byte.

That isn't a hope; it's a test suite. Every cast type, every edge value, every
null, every dirty comparison is asserted equal to vanilla across PHP 8.2–8.5 and
Laravel 11/12/13. The benchmarks run the *same fixtures the parity tests prove
identical*, so a number you read is a number you can trust.

Where Grease can't guarantee byte-identity for an exotic case, it **defers to
vanilla** — correct, just unaccelerated. Acceleration is never bought with
correctness. See [Caveats & Narrowing](/guide/caveats) for the two small things
that change, and why they don't matter in practice.

## Who it's for

Grease is for the app that has outgrown "Eloquent is fast enough" — the API serving
wide JSON, the dashboard hydrating big collections, the queue worker chewing
through rows. If your profiler points at hydration, casting, and serialization,
this is the package every deploy at scale should be reaching for.

If you're not there yet, you don't need it — and that's fine. Add it to the models
that hurt, when they hurt.

[Get started →](/guide/getting-started)
