# The Method

How a win gets found and proven in Grease. This is the discipline behind every number
in the [Benchmarks](/guide/benchmarks) — and behind every optimization that *didn't* ship.
It's written down because it's the part that's easy to skip and expensive to skip: most of
the value here came from rejecting plausible ideas that a profiler or a hunch said were
wins and a measurement said weren't.

## The cardinal rule comes first

**Output must stay byte-identical to vanilla** (behaviour-identical for the dispatcher).
That's not a goal we optimize toward — it's a gate every change passes before its speed
even matters. The parity suite (`composer test`) is the contract; a tier that can't stay
identical for an exotic case *defers to vanilla* rather than weakening the promise. No
number is worth looking at until parity is green.

## The loop

Every win — and every dead end — went through the same five steps:

1. **Build the fixture that would surface the lever.** You can't profile a cost that your
   fixture doesn't exercise. The right fixture is the whole game: the `@foreach` `$loop`
   cost was invisible until a table-shaped fixture (`page-table`) ran it in a tight loop;
   `@yield` only showed up under a layout fixture (`page-layout`); the dominant
   `resolveClassAttribute` tax only appeared once an eager-load profiler
   (`eager_excimer.php`) hydrated thousands of rows. Suspect a lever, then build the page
   or query that leans on it.
2. **Profile it honestly.** Sampling profiler, JIT on, on the target OS — see
   [the tooling](#the-tooling) below. Read where the time *actually* is, not where you
   assumed it would be.
3. **Form a hypothesis and micro-A/B it — before believing anything.** Isolate the one
   change, run it head-to-head against vanilla, parity-asserted, and look at the delta. A
   structural hunch is not evidence. This step has flipped intuition over and over (see
   [the traps](#the-traps-we-actually-hit)); skipping it is how you ship a regression that
   "obviously" should have been faster.
4. **Parity-gate, two ways.** A unit test that compares the greased path against a verbatim
   reimplementation of vanilla's algorithm (the *oracle* pattern — see
   `FactorySectionParityTest`, `FactoryStacksParityTest`) across plain, adjacency, and
   pathological inputs, **and** the macro's parity probe that asserts identical output
   before it times anything. Never weaken an assertion to make something pass.
5. **Ship the win, or record the dead end.** A measured dead end is a deliverable: it goes
   in [NOTES](https://github.com/One-Learning-Community/grease/blob/main/NOTES.md) with the
   number and the reason, so nobody — including future you — burns a session re-deriving it.

## The tooling

| Step | Command |
| --- | --- |
| Parity (the gate) | `composer test` |
| Per-op A/B + SQL suite | `composer bench` |
| End-to-end macro (incl. SQL) | `php benchmarks/realworld.php` |
| Blade macro (9 parity-gated variants) | `php benchmarks/blade.php` |
| Honest Blade profile (Excimer) | `php benchmarks/blade_excimer.php` |
| Honest model/eager profile (Excimer) | `php benchmarks/eager_excimer.php` |

All real numbers are taken on **Linux via `benchmarks/docker`** — never the macOS the package
was first written on:

```bash
docker build -t grease-bench benchmarks/docker
docker run --rm -v "$PWD":/app -w /app grease-bench \
  php -d xdebug.mode=off -d opcache.jit=tracing benchmarks/eager_excimer.php
```

The fixtures are shared between the tests and the benches on purpose (`tests/Fixtures/
SampleData::row()`, the `Vanilla*`/`Greased*` model pairs, the `page-*` Blade fixtures). **A
bench runs exactly what a test proves identical** — keep that link intact, and a number
always corresponds to a behaviour a test certifies.

## The traps we actually hit

These are not hypotheticals. Each one cost real time and is the reason a step above exists.

- **Xdebug's self-times lie.** Its cachegrind ranked `extract()` at ~14% of a Blade render;
  a micro-A/B proved it ~0.6%. Xdebug overrides `zend_execute_ex` (disabling JIT) and
  over-attributes internal-op cost to the calling PHP frame. Its *call counts* are
  trustworthy — that's how `merge` was found — but never its self-time **percentages**. Use
  a sampling profiler (Excimer), JIT on.
- **macOS distorts the build.** Its `/var`→`/private/var` symlink confuses opcache realpath
  keying, and its stat cache thrashes — `is_file()` read ~8% of a render on macOS and ~3% on
  Linux. Same code, different number. Measure on the OS you deploy on.
- **Structural hunches lose, repeatedly.** A `str_contains` short-circuit before a
  `str_replace` looked like a free win — it was −0.6% (no-match `str_replace` doesn't
  allocate; the *scan* is the cost). `strtr` for the same multi-marker substitution was
  **+47%** (a trap — it checks every position against every key). Hoisting a doubled
  `isset … instanceof` predicate in the component boilerplate *regressed* ~1% (the temp's
  assign+read outweighs the cheap re-eval). Only the candidate the A/B actually rewarded
  (`preg_replace_callback`, −87%) shipped. The hunch was wrong three times out of four.
- **Don't poison the compiled-view cache.** Emitted Blade code that calls a Grease helper
  couples the *compiled view* to the Grease singleton being present — it breaks when someone
  flips the provider off and renders a stale compiled view. Any emit must degrade safely on
  a vanilla runtime (the loop bookkeeping was deliberately *not* fused into the `@foreach`
  emit for this reason; the `@props` emit is a static, always-available `\Grease\View\*` call).
- **Isolate the arms in a bench.** A provider `boot()` that eager-resolves the Blade engine
  captures the compiled-view path *before* a bench sets its per-arm cache — producing a bogus
  −87%. Keep view-tier wiring lazy, or set the cache before booting the arm.

## Why this is the product

Grease is a portfolio of optimizations core declined as "marginal in isolation." The only
thing that makes a portfolio of marginal wins trustworthy is that each one is *real* and
*honest* — measured on a real build, proven identical, with the losers documented next to
the winners. The method **is** the moat. A faster render nobody can falsify is a liability;
a −17.7% you can reproduce in one command, over the same fixture a test proves byte-identical,
is the whole pitch.
