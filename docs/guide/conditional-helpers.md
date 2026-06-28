# Conditional helpers

The tiers in the menu are blanket wins: add a trait or a provider and every request
benefits, byte-identical, no judgement call. These three are a different kind of thing.
They help **only under specific conditions** — a request shape, a deployment model, a
usage pattern — and are neutral (or, in one bounded case, a hair slower) otherwise. They
are documented here, off the main menu, so you can reach for them when your shape matches
rather than enabling them by reflex.

All three are **byte-identical** to the vanilla expression they replace — the same promise
as the rest of Grease. The "condition" is never about correctness; it's about whether the
speedup actually materialises for *your* traffic.

## `CleanRequestInput` — fuse the two input-scrubbing middleware

Laravel's default global stack runs `TrimStrings` then `ConvertEmptyStringsToNull`. Each
extends `TransformsRequest`, whose `clean()` **recursively walks the whole input tree and
rebuilds the bag** — so your input is traversed and rebuilt *twice* per request, and
`TrimStrings` re-derives its except list and runs `Str::is()` on every string leaf.

`Grease\Http\Middleware\CleanRequestInput` does both transforms in **one** pass: it hoists
the except-merge out of the leaf loop, replaces the per-leaf `Str::is` with a compiled
matcher (see [`CompiledPatternSet`](#compiledpatternset-the-array-aware-pattern-matcher)),
and rebuilds the bag once. The output is identical — a value is trimmed (unless its key is
trim-excepted), then nulled if it is the empty string.

```php
// bootstrap/app.php
use Grease\Http\Middleware\CleanRequestInput;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;

->withMiddleware(function (Middleware $middleware) {
    $middleware->replace(TrimStrings::class, CleanRequestInput::class);
    $middleware->remove(ConvertEmptyStringsToNull::class);
})
```

Roughly **−59% of the input-scrub cost on a small (~10-field) request and −68% on a large
nested body** (Linux, via `benchmarks/docker`), and the saving scales with input size — the
ratio holds on a small request, the absolute grows on a large one.

**Reach for it when** your endpoints take **large or deeply-nested request bodies** — bulk
APIs, big forms, import payloads. That's where the doubled traversal and the per-leaf
`Str::is` actually add up.

**It's still free on small requests**, just a smaller absolute saving — the scrub is a
per-request constant, so like the [Request](/guide/request) and [Container](/guide/container)
tiers it shows most under a persistent worker.

::: warning One behaviour it does not reproduce
If you register a skip callback on **only one** of the stock middleware — so trimming is
skipped for a request but empty-to-null still runs, or vice versa — keep the stock pair.
`CleanRequestInput` fuses the two, so its `skipWhen()` is all-or-nothing. This is rare,
which is exactly why fusing them is a clean win. Configure `except()` / `skipWhen()` on
`CleanRequestInput` itself, at the spot you swap it in.
:::

## `Grease\Http\Request::is()` — a cached path matcher

Vanilla `Request::is(...$patterns)` allocates a `Collection` and, **for every pattern**,
recompiles `Str::is`'s regex *and* recomputes `decodedPath()`. A nav partial that calls
`request()->is('admin/*')` on a dozen links pays that recompile and re-decode for every
link, on every render.

The greased [Request](/guide/request) memoizes the decoded path, flattens the patterns once,
and matches through a `CompiledPatternSet` cached per pattern set. Warm, a nav partial drops
**~−87%** and a single check **~−86%** (Linux, via `benchmarks/docker`).

This one is explicitly **tuned for the persistent-worker (Octane) model** — the audience
Grease is built for. The cache lives for the worker's life, so after a route's patterns are
first seen, every later request is a cache hit and a clear win.

**Reach for it when** you're **on Octane** *and* you match paths repeatedly with **static
patterns** — nav active-states, sidebar highlighting, middleware route guards. That's the
sweet spot: the same handful of patterns, matched on every request, cached once.

**The honest edges:**

- **Cold start (first sight of a pattern):** a single-pattern compile is fractionally
  *slower* than `Str::is` — building the matcher costs more than one `Str::is` call. Under a
  warm worker you pay this at most once per pattern, then win forever; under shared-nothing
  FPM you pay it every request, so the win is smaller.
- **Dynamic, high-cardinality patterns** (`is("user/{$id}/*")`, unique every call) never get
  a cache hit. To keep the promise that it is **never unbounded-slower**, once the per-worker
  cache fills (1,000 distinct patterns — only a flood of dynamic patterns reaches it), `is()`
  transparently **falls back to vanilla**. Memory stays bounded; behaviour stays identical.

It comes with the Request tier — opt in with `Grease\Http\Request::capture()` in
`public/index.php` (see [The Request](/guide/request)); there is nothing extra to enable.

## `CompiledPatternSet` — the array-aware pattern matcher

The engine under both helpers above, exposed for your own hot loops.
`Grease\Support\CompiledPatternSet` compiles an array of patterns **once** into the fastest
faithful equivalent of `Str::is($patterns, $value)`:

- literal patterns (no `*`) → an `isset()` hash (exact match)
- a bare `*` → a match-everything short circuit
- wildcard patterns → **one merged alternation regex**, a single `preg_match`

```php
use Grease\Support\CompiledPatternSet;

$except = new CompiledPatternSet(['admin/*', 'api/*/internal', 'webhooks/*']);

foreach ($paths as $path) {
    if ($except->matches($path)) { /* … */ }   // === Str::is($patterns, $path)
}
```

Like `Str::is`, only `*` is a wildcard; every other character is a literal. It is
byte-identical to `Str::is($patterns, $value)` (case-sensitive — the default).

::: tip The rule: it wins when the build is *amortised*
A `CompiledPatternSet` pays a one-time compile, so it only pays off when that compile is
shared across more than one match. Two clear wins, one clear loss:

- **Many matches per build** — match one pattern set against a whole collection (the
  middleware shape: build once, scrub every input leaf). **~−96%** vs `Str::is` per value
  (Linux, via `benchmarks/docker`).
- **A long wildcard list** — merging N wildcard patterns into one regex collapses N
  `preg_match` calls into one, and the merged regex fast-rejects in near-constant time while
  a per-pattern loop scales linearly with N. At ~50 wildcard patterns it's **~−96%** vs a
  pre-compiled per-pattern loop (and ~−98% vs `Str::is`) — and the gap widens as the list
  grows, because the merged match stays roughly flat while the loop does not.
- **A single pattern matched once** — *don't bother*. The compile costs more than one
  `Str::is` call; just call `Str::is`. (This is the whole reason `Request::is()` above caches
  across calls rather than compiling per call.)
:::
