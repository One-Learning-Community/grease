# The View Cache

An eager extension to the [Blade tier](/guide/blade): `grease:view-cache`. Like the router, view
resolution is **not** a big per-request multiplier — a page resolves each view name once, mostly
cheaply. What makes it worth a tier is that the cost is *pure repeated waste* (filesystem stats for
something fixed at deploy), removed on every request and every worker — and that one slice of it,
the resolution **miss**, is recomputed *forever*, even under Octane. The lever is the same one
behind `grease:config-cache` and `grease:route-cache`: an opcache-interned index that pre-seeds
what the framework otherwise rebuilds.

## What `view:cache` leaves on the table

`view:cache` compiles every Blade template to PHP — but it throws away the *resolution* it computed
on the way there. So at runtime, every `view('name')` still:

- **stat-walks the filesystem** to map the name back to a file. `FileViewFinder::find()` tries
  `paths × extensions` (`resources/views/name.blade.php`, `.php`, `.css`, `.html`, then the next
  path…) with a `file_exists` syscall each, until one hits. The per-process `$views` memo covers
  *repeats within a process*, but it's rebuilt every FPM process — and, critically, **a miss is
  never memoized** (it throws instead of storing). So a view name that resolves dynamically —
  `@include($var)`, `<x-dynamic-component>`, a name built at runtime — re-stat-walks on **every
  render, forever**, FPM and Octane alike.
- **re-hashes the path** to find its compiled file: `CompilerEngine` computes
  `hash('xxh128', …$path)` per render (the greased [Compiler](/guide/blade) already memoizes this
  within a process; the index pre-seeds it).

None of that resolution is persisted, even though it's a pure function of the view name and the
view paths — fixed at deploy.

## What it does

`grease:view-cache` is a drop-in twin of `view:cache`: it runs it (compile + clear), then walks the
finder's paths and namespace hints and records, for every Blade view, two maps into a sibling file:

```php
// bootstrap/cache/grease_views.php
<?php return [
    'finder'   => ['admin.users' => '/app/resources/views/admin/users.blade.php', /* … */],
    'compiled' => ['/app/resources/views/admin/users.blade.php' => '/app/storage/framework/views/9f3….php', /* … */],
];
```

Because that file is a constant `return [...]`, **opcache interns it into shared memory** — `require`
hands it back by reference, so every worker and request loads it for ~free.
{@see Grease\View\GreaseViewServiceProvider} then seeds it at boot (when fresh):

- The `view.finder` singleton is swapped for `Grease\View\FileViewFinder`, whose `find()` is
  `return $this->greaseViewIndex[$view] ?? parent::find($view)` — an array hit for any known name,
  with **zero stats**, and a live `parent::find()` fall-through for anything else. The index lives in
  its own property (not the framework's `$views`), so it **survives `flush()`** and persists across
  Octane requests.
- The greased Compiler's compiled-path memo is pre-seeded from the `compiled` map, so the first
  render of each view in a fresh process skips the `xxh128` hash.

Engine resolution (extension → Blade/PHP/file engine) is left live — it's nanosecond-cheap and
self-memoizes — so there's no extra map and no Factory change.

## Byte-identical, by construction

Every entry is built by calling the **live** finder and compiler at cache time —
`$finder->find($name)` is the authority for the source path, `$compiler->getCompiledPath($source)`
for the compiled path. So a seeded hit returns *exactly* what vanilla would compute, and view
**precedence** (path order × extension order, first hit wins, shadowed duplicates) is captured
automatically rather than re-derived — the same parity-probe discipline as `grease:route-cache`.
A name the index doesn't contain falls through to the live resolver unchanged.

## Does it stay correct?

Yes — three layers keep it safe:

- **Graceful miss.** Any name not in the index (a view added after the build, a dynamic name, a
  non-Blade `.php`/`.css`/`.html` view — the command only walks `*.blade.php`) resolves live via
  `parent::find()`, byte-identical. The index can only ever *short-circuit* a known name; it never
  changes resolution for an unknown one.
- **Freshness guard.** The index loads only while it's at least as new as the compiled-view cache
  **and** the config cache (which holds `view.paths`). `grease:view-cache` writes it last, so it
  passes; a later plain `view:cache`, `config:cache`, or `php artisan optimize` makes one of those
  newer and the now-stale index is ignored — you fall back to live resolution, never served a wrong
  path.
- **Inert in development.** No artifact → nothing is swapped (a greased-but-empty finder would be
  pure overhead, so it isn't even installed); resolution is 100% vanilla.

The one contract — shared with `view:cache`/`config:cache` themselves — is **build == runtime**:
the index is a deploy artifact, so **rebuild it on deploy**. A structural change to your views
(add / move / delete) needs a rebuild, exactly as `view:cache` does. In-place *edits* don't: the
name→source mapping is unchanged and recompilation is left entirely to the framework's own
`isExpired` check.

## Under Octane vs FPM

The framework's `$views` memo already warms after the first render of each name in a process — so
under **FPM** the eager index buys request-one of each process (no cold stat-walk), and under
**Octane** it buys request-one of each worker. But the **resolution miss** is the part neither memo
ever caches: a dynamic / `@include($var)` / `<x-dynamic-component>` name re-stat-walks on every
render under both FPM and Octane. The eager index is the **only** thing that eliminates it — a
permanent win on dynamic-view-heavy pages, even on a long-lived Octane worker.

## What it's worth

The honest, platform-independent metric here is **stat count** — the syscalls eliminated — because
wall-time on these microbenches is noise next to the model/SQL tiers. Measured
(`benchmarks/view_cache_ab.php`): resolving 20 views in a cold process does **0 `file_exists` calls
vs vanilla's 20**. Wall-time on Linux (`benchmarks/docker`, opcache + JIT) tracks it:
**13.2 µs → 1.0 µs** to resolve those 20 views. On a real page that's a tree of dozens of
components, slots, and includes — so dozens of stats gone per request, plus every never-memoized
miss. Small on any single request; pure waste removed on every one.

::: tip Measure your own
Count the stats your pages actually make (the eliminated metric), not the µs — that's the honest
figure and it's platform-independent. The wall-time ratio holds on Linux; macOS CLI inflates the
absolutes (opcache off).
:::

## Memory

The index is one constant array that lives **once in opcache shared memory**, shared across every
worker and request — its per-request heap cost is ~nil. The finder's live `$views` memo is unchanged.

## Opt in

The view tier ships as the (non-auto-discovered) provider you may already register for the
[Blade greasing](/guide/blade):

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\View\GreaseViewServiceProvider::class,
```

That alone gives the lazy Blade wins and carries no caveat. For the eager view index, swap
`view:cache` for `grease:view-cache` in your deploy:

```bash
php artisan grease:view-cache   # view:cache + the opcache-interned resolution index
```

Run it **last** in your deploy (it runs `view:cache` itself), so a later `view:cache` / `optimize`
doesn't shadow it. Without a fresh artifact the provider is inert for resolution — the framework's
own memo (and the greased compiler's) still apply.
