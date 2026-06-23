# The Config Repository

Another axis on the request lifecycle — reading configuration. Like the request's
`input()`, `config('a.b.c')` is work a request repeats **many times**: a vanilla Laravel 13
request makes ~50 config reads before your code runs a line, and a real app — models, Blade
components, packages, business logic — pushes that into the hundreds or thousands. Each one
re-walks a nested array. There are two tiers here: a lazy per-read memo (a provider rebind),
and an eager, opcache-interned flat index (an extra build step) that goes further.

## What it does

Vanilla `Repository::get()` funnels every read through `Arr::get($this->items, $key)`, which
runs `explode('.', $key)` and walks the nested config array — on **every** call. The same
handful of keys (`app.env`, `app.debug`, `app.timezone`, `database.default`, `cache.default`,
…) get read over and over from all across the framework.

**Tier 1 — the lazy memo.** `Grease\Config\Repository` memoizes the resolved value per full
key string, so a repeated read is a single hash hit instead of a re-walk. It's careful in the
two ways these memos always are: `array_key_exists`, not `??=`, so a stored `null` is a real
cached value and not re-walked every time; and only keys that **exist** are memoized — a
missing key's result depends on the per-call default, so a private sentinel tells a stored
`null` apart from a genuinely-absent key in a single walk, and absent keys are never cached.

**Tier 2 — the flat index (`grease:config-cache`).** The memo still pays one dot-walk per key
the first time it's read. The flat index removes even that. `grease:config-cache` is a drop-in
twin of `config:cache`: it runs it, then emits a sibling file holding a flat `'a.b.c' => value`
map of every leaf. Because that file is a constant `return [...]`, **opcache interns it into
shared memory** — `require` hands it back by reference, so every worker and every request loads
it for ~free, and every leaf read is a hash hit from the very first call, with no warmup. (This
is exactly the mechanism that makes `config:cache` itself fast.)

### config:cache is the baseline, not the competition

This is deliberately measured **on top of** `config:cache` (the production standard).
`config:cache` optimizes *boot* — it pre-merges your config files into one cached array — but it
does **not** touch the read path: it still builds a plain `Repository` over a nested array, and
`get()` dot-walks every read regardless. The memo and the flat index optimize the read path
`config:cache` leaves alone.

## Does it stay correct when config changes?

Yes. A runtime write (`config(['x' => 'y'])`, `set`, `prepend`, `push`, `offsetSet`,
`offsetUnset` — all funnel through `set()`) flushes the memo and **taints** the flat index for
the rest of the request, so subsequent reads fall back to the live `Arr::get` path and serve the
mutated value. The taint is per-instance, so it self-resets each request. `set()` flushes and
rewrites `$items` together, so the memo is consistent with `$items` at every instant.

### The one carve-out

**Out-of-band mutation of `$items`** — a macro or reflection writing the protected array
directly, bypassing `set()` — isn't observable, so the caches won't know. This is the exact
analogue of the request tier's direct-bag caveat (and vanishingly rare; `$items` is protected).
`flushConfigMemo()` is the explicit hook if you ever need it.

## Under Octane

Octane sandboxes config **per request** — `CreateConfigurationSandbox` runs
`instance('config', clone $sandbox['config'])` on every request, so each request handles against
a fresh clone of the base repository and runtime config mutations are isolated and discarded
(Octane handles cross-request leakage; this is not a footgun). The greased repository is safe
through that clone: PHP's shallow clone copies `$items` and the caches together and consistently,
so the sandboxed config stays greased and matches a cloned vanilla repo (pinned by a parity test).

The consequence shapes which tier wins where. The **memo** is rebuilt per request — under both
FPM and Octane — so it only pays back *within-request* repetition. The **flat index** is built on
the base repository, so every per-request clone COW-inherits it fully warm: every read is a hash
hit on every request, with the build cost amortized across the worker's whole life. So the flat
index is the tier that compounds under Octane, and the one that wins the per-request-cold case
(every FPM request; every fresh Octane clone) the memo structurally can't reach.

## Behaviour-identical, by test

Parity is the returned value, asserted against vanilla `Illuminate\Config\Repository` across the
full read surface — nested keys, whole-array vs scalar-leaf reads, descend-into-a-scalar, numeric
segments, empty arrays, stored `null`, getMany, `has`, `offsetGet`, closure defaults — plus every
write-path invalidation, copy-on-write non-poisoning, the per-request clone path, and a build-time
guarantee that every flat-index entry equals `Arr::get` (the rare literal-dotted-key collision is
detected and dropped, falling back to the vanilla path).

## What it's worth

The flat index turns a config read from a dot-walk into a hash hit, and that holds whatever your
call volume: a stable **~88% cut of config-read time vs vanilla**, independent of how many reads
a request makes. The *absolute* saving scales linearly with that volume — from a fraction of a
millisecond on a framework-floor request (~50 reads) to several milliseconds on a config-dense one
(thousands). The lazy memo's value, by contrast, depends on within-request repetition: a useful
**~−65%** on a repeat-heavy read mix, but little on reads that never repeat (which is exactly the
gap the flat index closes).

::: tip Measure your own
Config-read cost is entirely a function of *your* call density — count it (instrument
`Repository::get()`, or watch `config_request_sim.php`) and multiply by the per-read delta.
These ratios are macOS figures; the magnitudes need a Linux/`benchmarks/docker` run, which is
still owed — read the ratios, reproduce on your target.
:::

## Memory

The memo is a small per-instance array of the keys a request actually reads. The flat index is one
constant array that lives **once in opcache shared memory**, shared across every worker and request
— so its per-request heap cost is ~nil (measured at ~0.4 KB warm, regardless of index size), not a
copy per request.

## Opt in

The lazy memo is a provider rebind, like the event and view tiers — register it (deliberately not
auto-discovered) and every `config()` read goes through the greased repository:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Config\GreaseConfigServiceProvider::class,
```

For the flat index, swap `config:cache` for `grease:config-cache` in your deploy:

```bash
php artisan grease:config-cache    # config:cache + the opcache-interned flat leaf index
```

The provider loads the index only when it's at least as fresh as the config cache, so a later
plain `config:cache` or `config:clear` transparently disables a stale index — you're never served
a config that doesn't match. In development (no config cache) it simply falls back to the lazy memo.
