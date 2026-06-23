# Grease

[![tests](https://github.com/One-Learning-Community/grease/actions/workflows/tests.yml/badge.svg)](https://github.com/One-Learning-Community/grease/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/onelearningcommunity/grease.svg)](https://packagist.org/packages/onelearningcommunity/grease)
[![Total Downloads](https://img.shields.io/packagist/dt/onelearningcommunity/grease.svg)](https://packagist.org/packages/onelearningcommunity/grease)
[![License](https://img.shields.io/packagist/l/onelearningcommunity/grease.svg)](LICENSE)

**Opt-in performance for Laravel's hot paths — built from optimizations declined upstream.**

Grease speeds up the work Eloquent repeats on every request — attribute hydration,
casting, serialization — with **byte-identical output** to vanilla. Add a trait to the
models you care about; leave the rest untouched. Zero framework changes.

📖 **[Full documentation →](https://one-learning-community.github.io/grease/)**

## Install

```bash
composer require onelearningcommunity/grease
```

```php
use Grease\Concerns\HasGrease;

class User extends Model
{
    use HasGrease;
}
```

That's the whole setup for the model tiers — no config, no provider, no cache to warm.
The model's hydration, casting, and serialization now run the greased fast paths, and its
output stays byte-identical to vanilla Eloquent. Prefer inheritance? Extend
`\Grease\GreasedModel` instead.

Two further strands go beyond the model trait, each opt-in via a (non-auto-discovered)
provider:

```php
// bootstrap/providers.php, or the providers array in config/app.php
Grease\Events\GreaseEventServiceProvider::class,   // faster event dispatcher, app-wide
Grease\View\GreaseViewServiceProvider::class,      // faster Blade component render
```

## What you get

Representative deltas, measured on Linux ([reproduce on your own build](https://one-learning-community.github.io/grease/guide/benchmarks) — one command):

- **End-to-end requests (incl. SQL):** −78% list-100-users, −77% eager-load, −47% show, −18% bulk write.
- **Per operation:** hydrate −34%, `toArray` −47%, set+dirty −39%, read −27%, enum −48%, date serialization −87%.
- **Event dispatcher** (app-wide): −53% no-listener dispatch, ~halves a render-dense request's event overhead.
- **Blade** (render path, app-wide): −38.9% simple / −29.9% rich component renders, −27.8% a `$loop`-heavy table, −19.4% a layout — byte-identical HTML.

These are `:memory:`/Linux figures — read them as Grease's share of the work, not your p99,
and reproduce on your target. The [Benchmarks guide](https://one-learning-community.github.io/grease/guide/benchmarks)
has the methodology, the build-to-build variance, and the honest caveats.

## Byte-identical, or it's a failing test

That promise is the whole product. Every cast type, edge value, null, and dirty-check is
asserted equal to vanilla across PHP 8.2–8.5 and Laravel 12/13; the benchmarks run the
*same fixtures the parity tests prove identical*. Where Grease can't guarantee byte-identity
for an exotic case, it defers to vanilla — correct, just unaccelerated.

```bash
composer test     # the byte-identical contract
composer bench    # phpbench per-op A/B + the SQL suite
```

## Learn more

- **[Getting Started](https://one-learning-community.github.io/grease/guide/getting-started)** — install, the à-la-carte tiers, the optional providers
- **[Why Grease](https://one-learning-community.github.io/grease/guide/why)** — the "marginal in isolation" story and the declined core PRs
- **[How It Works](https://one-learning-community.github.io/grease/guide/how-it-works)** — the per-class blueprint and each tier
- **[Benchmarks](https://one-learning-community.github.io/grease/guide/benchmarks)** — full numbers, methodology, and reproducing them on your build
- **[The Event Dispatcher](https://one-learning-community.github.io/grease/guide/events)** · **[Blade Components](https://one-learning-community.github.io/grease/guide/blade)** — the two beyond-Eloquent strands
- **[Caveats & Narrowing](https://one-learning-community.github.io/grease/guide/caveats)** — the two small, obscure things that change

## Requirements

PHP 8.2+, Laravel 12/13.

## License

Released under the [MIT License](LICENSE) — Copyright © 2026 One Learning Community LTD.

## Built with Claude

Grease was built proudly in collaboration with [Claude](https://claude.com/claude-code)
— a small proof of what a strong engineering mindset and AI can do together: measure
first, keep the parity spine honest, and ship the wins core couldn't.
