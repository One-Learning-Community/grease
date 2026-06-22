# Blade Components

A *third axis*, like the [dispatcher](/guide/events): not a per-model trait but the
**Blade render path**. The traits make models faster; this makes the components every
page renders faster — byte-for-byte the same HTML.

## The challenge

It starts with a [tweet Taylor posted in April 2024](https://x.com/taylorotwell/status/1781039378376146970):

> Sometimes I get obsessed with figuring out if Blade component rendering performance
> can be supercharged. Rendering 1,000 anonymous components takes about 14ms on my MBP.
> Can we cut this in half? 🤔 Dig in and help me figure it out?

We've been chewing on *reliably* trimming that number ever since — on and off for two
years. "Reliably" is the whole game: it's easy to shave a render by changing what it
emits; the bar here is the same as everywhere else in Grease — **byte-identical output**,
asserted before any number is timed. And the honest framing up front: this is **one
challenge of limited breadth**, not a Blade overhaul.

## What it does

Every component, on every render, pays two costs that don't depend on the data. Grease
greases exactly those two:

- **`@props` resolution.** Vanilla compiles `@props([...])` to a block that, on each
  render, rebuilds a flat name list, scans it with `in_array` (a linear walk per
  attribute), evaluates the declaration array *twice*, and snapshots the whole scope with
  `get_defined_vars()` to clean up. Grease compiles it to one memoized
  `Props::mergeAttributes()` call (the name set is built once per `@props` site, not per
  render) followed by a tight `$$key = $value` bind loop.
- **`$attributes->merge([...])`.** Nearly every component template calls it. Vanilla runs
  it through the Collection pipeline — `new Collection`, `partition()`, `mapWithKeys()`,
  `->merge()`, `->all()`, roughly five allocations a render. Grease does the identical
  partition + append in two plain `foreach` loops, no Collections. It's a subclass of
  the framework's own `ComponentAttributeBag` (so every `instanceof` check still holds),
  handed to the component by `Props::mergeAttributes()`; `merge()` returns `new static`,
  so the fast path stays live down any chain.

## Byte-identical, by test

Same bar as the model tiers: the rendered HTML is **identical to the byte**. The macro
([`benchmarks/blade.php`](https://github.com/One-Learning-Community/grease/blob/main/benchmarks/blade.php))
renders Taylor's exact loop through the stock compiler and the greased one in two booted
apps with separate compiled-view caches, and asserts the HTML matches *before* it times
anything. Execution-level tests pin the pieces: the `@props` emit resolves the same prop
variables and leaves the same attributes as vanilla (down to the inaccessible kebab-alias
local vanilla also creates), and the greased `merge()` produces the same attribute array
and string across class/style appends, escaping, `AppendableAttributeValue`, ordering, and
the forwarded-bag `sanitizeComponentAttribute` guard.

## What it's worth

On Taylor's exact challenge — 1,000 anonymous components, output asserted identical
(Linux, [`benchmarks/docker`](/guide/benchmarks#a-benchmark-is-a-property-of-the-build)):

| Component shape | Δ |
| --- | :---: |
| simple (initials + one attribute merge) | **−38%** |
| rich (5 props, a `@php` block, conditionals, slots) | **−29.5%** |

Not the halving Taylor asked for, and worth saying plainly. The rest of a render is the
compiled template *executing* (real work — your markup, the `Str` calls) plus the
per-component resolution machinery, and we haven't found a **clean, parity-safe** win in
that machinery yet. We've measured several and rejected them — replacing `extract()` with
a bind loop (a regression: `extract` is a C builtin, ~2× faster than userland), and
caching `is_file()` (a macOS profiling artifact that's ~3% on Linux, and the wrong thing
on principle). Those dead ends are recorded openly in the repo's
[NOTES](https://github.com/One-Learning-Community/grease/blob/main/NOTES.md).

So: two years on, what we'll stand behind is **reliably about a third off, byte-for-byte**
— and the [harness](/guide/benchmarks) is right there if you want to chase the other half.

## Opt in

It is **not** auto-discovered — register the provider explicitly:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\View\GreaseViewServiceProvider::class,
```

It `extend`s the bound `blade.compiler` singleton, swapping it for the greased compiler
via `fromBase()` — so every directive, component, and condition already registered (or
registered afterwards) is carried over. Register it early so the compiler is greased
before any view compiles; existing views recompile on their next change, and a
`php artisan view:clear` forces it immediately. Output stays byte-identical.

::: tip Profiling Blade honestly
If you go chasing the other half, profile with a *sampling* profiler (the repo ships an
Excimer harness, `benchmarks/blade_excimer.php`), not Xdebug — Xdebug disables JIT and
mis-attributes internal-op cost, which sent us down a dead end (it ranked `extract` at
~14% of a render when it's ~0.6%). See [Benchmarks](/guide/benchmarks) for the method.
:::
