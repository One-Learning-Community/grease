# Grease & Octane

## Octane doesn't make Grease faster — it stops hiding it

Under classic PHP-FPM, every request boots the framework from cold: autoload, register
and boot service providers, build the container, resolve config and routes — then,
somewhere inside all that, do the actual work of *your* request. Grease speeds up that
last part — hydration, casting, serialization, the model hot path. But against a full
cold boot, the slice Grease removes is a slice of a much larger pie, and it reads as
noise on the bootstrap.

A persistent worker — [Octane](https://laravel.com/docs/octane) on FrankenPHP, Swoole,
or RoadRunner — boots **once** and then handles thousands of requests on that warm
process. The bootstrap cost amortizes toward zero. What's left in each request is the
real work: querying, hydrating, casting, serializing. That's exactly the work Grease
cuts — so the same absolute saving stops being a rounding error on boot and becomes a
visible fraction of the request.

> Add Grease and you'll see a difference under FPM. Under Octane, you'll see real change.

Nothing about Grease changes between the two. The work it removes is identical. Octane
just clears away the bootstrap that was standing in front of it.

## Why the worker model amplifies it

Every Grease tier pays a small **one-time** cost to compile its per-class "blueprint" —
the frozen cast map, the date format, the constructor plan, the cast flyweights, the
compiled view paths. After that, every operation on that class is a cheap cache read.

The catch under FPM is that "once" means *once per process*, and an FPM process serves a
single request. So FPM rebuilds the blueprint on every request and tears it down at the
end — paying the warmup tax forever, and realizing the cached win for only the back half
of the request that the warmup didn't eat.

A persistent worker pays that warmup **once**, on its first request, and every request
after that runs entirely on the warm blueprint — full per-operation win, zero warmup.
Across a worker's lifetime of thousands of requests, the warmup is a single rounding
error and the steady state is all gain. The optimization that looked "marginal" under
FPM's per-request reset is, under a worker, the whole point.

## `HasGrease` alone is enough to justify it

You do not need to adopt every tier to make this worthwhile. The foundation tiers —
[container](/guide/container), [request](/guide/request), [config](/guide/config),
[router](/guide/routing) — compound nicely on top, but they each shave a *thin slice* of
a request. The thick slice on a data-bound endpoint is the model work: a warm worker
serving an API spends its time hydrating rows and serializing them to JSON.

That's precisely what the trait greases. Add `HasGrease` to the models on your hot
paths — nothing else — and on a persistent worker the package has already earned its
place. The other tiers are upside, not a prerequisite. This is the conclusion our own
Octane testing kept landing on: the model tier alone moved the request enough to settle
the question, before any other axis was layered in.

## Why there's no benchmark table on this page

You'll have noticed every other corner of these docs leads with a number. This page
doesn't, and that's deliberate.

Our [per-operation and macro benchmarks](/guide/benchmarks) are honest about what they
measure: a single operation, or a single request shape, against in-memory SQLite, with
parity asserted byte-for-byte first. Those micro numbers tell a clean story *at the
micro level*. What they don't do is **compose** into one fair "Grease under Octane"
headline. The macro result on a real worker swings on things we can't choose for you:
your workload mix, your worker count, how much of each request is database I/O versus
model work, even how warm the process is when the request lands. Any single
"comprehensive" Octane number we published would be a number wearing *our* assumptions —
and the more comprehensive we made the harness, the more assumptions it would quietly
bake in.

So we won't hand you a figure we can't make fair. **Your mileage will vary**, genuinely
and by a lot, and the only Octane number worth trusting is the one you measure on your
own traffic.

If you want to *see* the mechanism rather than take our word for it, the
[Benchmarks](/guide/benchmarks) page has the per-operation and macro deltas, and
`benchmarks/octane.php` measures the warmup tax directly — the cold first-request cost
(what FPM pays) against the warm steady state (what a worker pays), on the same
parity-proven fixtures, so you can size the gap for yourself.

## Give it a whirl

The experiment is close to free. Grease is opt-in and
[byte-identical to vanilla](/guide/why#the-one-rule-byte-identical-output) — a greased
model returns the same values, types, and JSON as before — and the model tiers are
[Octane-safe with nothing to configure](/guide/getting-started): no provider, no cache
to warm, just a trait. The blueprint builds itself on first use and persists for the
worker's life.

So try it where it would matter:

1. Add `HasGrease` to the models on a hot, data-bound endpoint.
2. Deploy on your Octane setup.
3. Watch your own p50/p99 on that route.

If your profiler points at hydration, casting, or serialization, you'll see it. If it
doesn't, you've lost nothing — the output is identical either way. From there, the
[foundation tiers](/guide/container) and their per-tier "Under Octane vs FPM" notes
([container](/guide/container#opt-in), [router](/guide/routing#under-octane-vs-fpm),
[config](/guide/config#under-octane), [view cache](/guide/view-cache#under-octane-vs-fpm))
are the compounding upside on top.
