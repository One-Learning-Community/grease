# Contributing

Thanks for looking under the hood. Grease has one non-negotiable rule and one method;
both are short.

## The rule

**Output must stay byte-identical to vanilla** (behaviour-identical for the event
dispatcher). It's the whole product. Every change keeps the parity suite green:

```bash
composer test     # the byte-identical contract
composer bench    # per-op A/B (CastBench) + the SQL suite (SuiteBench)
```

A tier that can't guarantee identity for an exotic case **defers to vanilla** — correct,
just unaccelerated. Never weaken a parity assertion to make something pass.

## The method

Every win — and every documented dead end — went through the same loop: build the fixture
that surfaces the lever → profile it honestly (Excimer, JIT on, on Linux via
`benchmarks/docker` — **not** Xdebug, whose self-times lie; **not** macOS, which distorts
the build) → micro-A/B the candidate *before believing it* → parity-gate two ways (an oracle
unit test + the macro's parity probe) → ship it, or record the dead end in
[`NOTES.md`](NOTES.md) so nobody re-derives it.

The full write-up, with the traps that earned every step, is **[The Method](docs/guide/method.md)**.
Read it before adding a tier — most of the value in this package came from rejecting
plausible ideas a measurement then disproved.

## Adding a tier

- Key per-class state by `static::class`; reuse the per-class blueprint
  (`Concerns/InteractsWithGreaseBlueprint`). See [How It Works](docs/guide/how-it-works.md).
- Share fixtures between tests and benches (`tests/Fixtures/`, the `page-*` Blade fixtures)
  so a bench runs exactly what a test proves identical.
- Laravel/Pint code style, PHP 8.2+, Laravel 12/13. CI gates parity across the full matrix.
