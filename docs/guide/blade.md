# Blade Components

A *third axis*, like the [dispatcher](/guide/events): not a per-model trait but the
**Blade render path**. The traits make models faster; this makes the views every page
renders faster — byte-for-byte the same HTML.

## The benchmark

The stress test is rendering **1,000 anonymous components** — the standard way to isolate
Blade's per-component overhead, which runs about 14 ms for that many on a typical laptop.
The question Grease asks of it: can that number come down *reliably*?

"Reliably" is the whole game: it's easy to shave a render by changing what it
emits; the bar here is the same as everywhere else in Grease — **byte-identical output**,
asserted before any number is timed. And the honest framing up front: this isn't a Blade
overhaul. It's a *pile of marginal, byte-identical wins* — the same shape as the rest of
the package — each one a thing core would wave off in isolation, that compounds once
they're all on the page.

## Two singletons, two surfaces

The provider swaps **two** bound singletons for greased, behaviour-identical drop-ins
(both built via a `fromBase()` reflection-clone that carries the existing instance's full
state, so the swap is transparent):

- **`blade.compiler` → [`Grease\View\Compiler`](https://github.com/One-Learning-Community/grease/blob/main/src/View/Compiler.php)** — the *compile-time* emit and a
  couple of per-render lookups the compiler owns.
- **`view` → [`Grease\View\Factory`](https://github.com/One-Learning-Community/grease/blob/main/src/View/Factory.php)** — the *runtime* render bookkeeping the
  view factory drives (`@foreach`'s `$loop`, `@yield`'s content stitching).

## What it does

Seven wins ride on those two singletons. Each replaces work every page repeats — work that
doesn't depend on your data — with a tighter path that emits the identical bytes.

**On the compiler (`blade.compiler`):**

- **`@props` resolution.** Vanilla compiles `@props([...])` to a block that, on each
  render, rebuilds a flat name list, scans it with `in_array` (a linear walk per
  attribute), evaluates the declaration array *twice*, and snapshots the whole scope with
  `get_defined_vars()` to clean up. Grease compiles it to one memoized
  `Props::mergeAttributes()` call (the name set is built once per `@props` site, not per
  render) followed by a tight `$$key = $value` bind loop.
- **`$attributes->merge([...])`.** Nearly every component template calls it. Vanilla runs
  it through the Collection pipeline — `new Collection`, `partition()`, `mapWithKeys()`,
  `->merge()`, `->all()`, roughly five allocations a render. Grease does the identical
  partition + append in two plain `foreach` loops, no Collections. It's a subclass of the
  framework's own `ComponentAttributeBag` (so every `instanceof` check still holds), and
  `merge()` returns `new static`, so the fast path stays live down any chain.
- **Greased bag for class components, too.** The greased bag reaches `@props` components
  for free (via `Props::mergeAttributes`), but plain class and no-`@props` components build
  a *vanilla* bag through `Component::newAttributeBag()` — so their template merge took the
  slow Collection path. The catch: the opening boilerplate hands the template its bag (via
  `data()` inside `startComponent`) *before* `withAttributes` populates it, so you can't
  reclass it after. The fix is one emitted line — Grease overrides the
  `compileClassComponentOpening` emit to seed
  `$component->attributes ??= new \Grease\View\ComponentAttributeBag([])` *before*
  `startComponent`, which `data()`'s `?:` then adopts. Byte-identical (an empty seed equals
  vanilla's lazy `newAttributeBag()`; the `??=` preserves a constructor-set bag).
- **`getCompiledPath()` memoization.** The compiled-file path is a pure
  `hash('xxh128', …)` of the view path — and it's recomputed on *every* view render
  (`CompilerEngine::get`), which on a real page is a tree of renders (every component,
  slot, `@include`, `@each` is one). The framework already memoizes this lookup's siblings
  (`normalizeName`, `getEngineFromPath`) but missed this one. Grease memoizes it keyed by
  path. It scales *with* render count, so it helps realistic pages most — the opposite of
  the `@props`/`merge` dilution below.

**On the view factory (`view`):**

- **`@foreach`'s `$loop` bookkeeping.** Blade emits full `$loop` state for every
  `@foreach`, even when the body never touches `$loop`. The machinery
  (`ManagesLoops` — `addLoop`/`incrementLoopIndices`/`getLastLoop`) is ~35% of a
  loop-heavy render: `incrementLoopIndices` `array_merge`s the 10-key state array *every
  iteration*, and the others reach the stack top through `Arr::last` (closure-default
  overhead). Grease updates the state **in place by reference** (no merge) and uses a
  direct stack index. Byte-identical — same loop-state shape, and `getLastLoop` keeps its
  *fresh* `(object)` cast every call (a micro proved reusing one object is both unsafe
  *and* ~10% slower, so there's no tension).
- **`@yield` / `yieldContent`.** `@yield('content')` hands the whole page body to
  `yieldContent`, which vanilla scans **three times** with `str_replace` (`@@parent` →
  marker, strip the placeholder, marker → `@parent`) — 29% of a layout render's self-time.
  The net is one substitution over three *non-overlapping* markers, and neither pass
  re-scans its own output, so Grease collapses all three into one
  `preg_replace_callback` over the alternation. Byte-identical, proven against a verbatim
  three-pass oracle.
- **`@push` / `@prepend` stack assembly.** A component (or a loop row) that pushes its
  assets to a `@stack` runs `stopPush()`/`stopPrepend()` once per `@endpush`/`@endprepend`,
  and vanilla wraps each pop in `tap(array_pop(…), fn ($last) => …)` — allocating a fresh
  bound closure every call (the profile puts the two stack `tap` closures at ~13% of a
  push-heavy render's self-time) for a return value the compiled directive discards. Grease
  inlines it: the same pop, the same `extendPush($last, ob_get_clean())`, the same returned
  section name — no closure.

## Byte-identical, by test

Same bar as the model tiers: the rendered HTML is **identical to the byte**. The macro
([`benchmarks/blade.php`](https://github.com/One-Learning-Community/grease/blob/main/benchmarks/blade.php))
now has **nine parity-gated variants** — `page-simple`, `page-foreach`, `page-rich`,
`page-rich-foreach`, `page-app`, `page-table`, `page-layout`, `page-stacks`, `page-full` — each rendered through the
stock compiler and the greased one in two booted apps with separate compiled-view caches,
and each asserts the HTML matches *before* it times anything. The variety is the point: the
right fixture surfaces the lever (`page-table` is what surfaced the `@foreach` cost;
`page-layout` surfaced `@yield`). Execution-level tests pin every piece down — the `@props`
emit resolves the same prop variables and leaves the same attributes (down to the
inaccessible kebab-alias local vanilla also creates); the greased `merge()` matches across
class/style appends, escaping, `AppendableAttributeValue`, and ordering; the loop tier
matches across countable / single / non-countable-generator / nested-with-parent; and
`yieldContent` is checked against its three-pass oracle across plain, marker, adjacency,
and pathological inputs.

## What it's worth

On the parity-gated macro variants (Linux,
[`benchmarks/docker`](/guide/benchmarks#a-benchmark-is-a-property-of-the-build), output
asserted identical):

| Variant | What it stresses | Δ |
| --- | --- | :---: |
| simple | initials + one attribute merge | <Delta k="page-simple" :digits="1" /> |
| rich | 5 props, a `@php` block, conditionals, slots | <Delta k="page-rich" :digits="1" /> |
| app page | class components, slots, `@include`/`@each`, a view composer | <Delta k="page-app" :digits="1" /> |
| data table | nested `@foreach`, heavy `$loop` use | <Delta k="page-table" :digits="1" /> |
| layout | `@extends` / `@section` / `@yield` / `@push` | <Delta k="page-layout" :digits="1" /> |
| asset stacks | `@push`/`@prepend` per row into a `@stack` | <Delta k="page-stacks" :digits="1" /> |
| full page | extends a layout, 5 sections, a 100-row `@foreach` table, components | <Delta k="page-full" :digits="1" /> |

The `@foreach` variants (`page-foreach`, `page-rich-foreach`) render the avatar
benchmark in the *realistic* loop form, and land on the same ~<Delta k="page-foreach" /> /
~<Delta k="page-rich-foreach" /> as their `@for` counterparts — confirming the tiers compose
with zero regression.

The **full page** is the most honest single number: a standard page that `@extends` a
primary layout, fills `head`/`styles`/`scripts`/`footer`/`content` (with a `@parent`
override), renders a 100-row `@foreach` table, and drops in a few components — every tier
firing at once. It lands at <Delta k="page-full" :digits="1" />, *lower* than any single-axis variant, and that's the
truth of the regime: on a realistic page the greasable framework slice is small because
genuine work dominates. A profile (Excimer) puts ~53% of the render in the compiled
template bodies (your markup) and ~24% in `e()`/`htmlspecialchars` (escaping ~500 table
cells) — both genuine, both off-limits. The remaining ~23% is spread thin across the engine
and Factory, and every greasable piece of it is *already* greased. So the single-axis
numbers above are what each tier is worth where it dominates; <Delta k="page-full" :digits="1" />
is what they compound to where they don't.

::: tip The regime insight — which fixture wins on which tier
The wins split by **what the loop body costs**, and that's worth knowing when you reason
about your own pages. Loop / `$loop` greasing pays when the body is *cheap* — data-table
cells, lists, menus — where the per-iteration machinery (~0.1 µs) is a real fraction of
each pass; on a component loop the body (~15 µs an iteration) dwarfs it, so component
greasing (`@props` + `merge`) is what moves the needle there instead. Neither tier
regresses the other — they just light up on different shapes. So a component-dense page
leans on the compiler tier and a table-dense page leans on the factory tier, and a page
with both gets both.
:::

Not a halving, and worth saying plainly. The rest of a render is the
compiled template *executing* (real work — your markup, the `Str` calls) plus the
per-component resolution machinery. The one big remaining slice — the
`$__componentOriginal` save/restore boilerplate the `ComponentTagCompiler` emits around
every `<x-…>` — we *did* take a hard look at, and measured it a dead end: its expensive
statements (`resolve`/`data`/`renderComponent`) are user-class or genuine assembly, the
save/restore guards are load-bearing for nesting and loop correctness, and the one
removable redundancy (a doubled `isset … instanceof` predicate) *regressed* ~1% when
hoisted. We've measured several other levers and rejected them too — replacing `extract()` with a bind
loop (a regression: `extract` is a C builtin, ~2× faster than userland), caching
`is_file()` (a macOS profiling artifact that's ~3% on Linux, and the wrong thing on
principle), a `gatherData` Renderable scan (~0.1% safe, with order and side-effect parity
walls). Those dead ends are recorded openly in the repo's
[NOTES](https://github.com/One-Learning-Community/grease/blob/main/NOTES.md), so we don't
recircle them.

So: two years on, what we'll stand behind is **reliably a fifth to two-fifths off,
byte-for-byte**, depending on the page's shape — and the [harness](/guide/benchmarks) is
right there if you want to chase the rest.

## Opt in

It is **not** auto-discovered — register the provider explicitly:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\View\GreaseViewServiceProvider::class,
```

It `extend`s the two bound singletons — `blade.compiler` and `view` — swapping each for
its greased counterpart via `fromBase()`, so every directive, component, condition, and
shared value already registered (or registered afterwards) is carried over. Register it
early so the compiler is greased before any view compiles; existing views recompile on
their next change, and a `php artisan view:clear` forces it immediately. Output stays
byte-identical.

::: tip Profiling Blade honestly
If you go chasing the rest, profile with a *sampling* profiler (the repo ships an Excimer
harness, `benchmarks/blade_excimer.php`), not Xdebug — Xdebug disables JIT and
mis-attributes internal-op cost, which sent us down a dead end (it ranked `extract` at
~14% of a render when it's ~0.6%). See [Benchmarks](/guide/benchmarks) for the method.
:::
