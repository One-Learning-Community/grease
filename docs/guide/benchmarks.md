# Benchmarks

Numbers you can trust come from a method you can see. Everything below runs over the
**same fixtures the parity tests prove byte-identical**, so each benchmark times
exactly the behaviour a test certifies. Reproduce any of it from the repo.

## End to end, per request (incl. SQL)

In-memory SQLite, real queries, controller-shaped workloads. Vanilla Eloquent vs. the
same models with `HasGrease`. Output is byte-identical.

| Endpoint (one request, incl. SQL) | vanilla | + Grease | Δ |
| --- | ---: | ---: | :---: |
| index: list 100 users → JSON | 10.9 ms | 2.9 ms | **−73%** |
| eager: 100 posts with author → JSON | 20.8 ms | 5.5 ms | **−74%** |
| bulk: load 150, mutate, save | 34.8 ms | 27.7 ms | **−21%** |
| show one post (with author) | 0.38 ms | 0.21 ms | **−45%** |

```bash
php benchmarks/realworld.php
```

The gain scales with how much your request hydrates: wide selects, eager loads, and
serialization-heavy API responses benefit most. A request that does almost no
Eloquent work has almost nothing for Grease to speed up — and that's the honest
shape of it. These are `:memory:` numbers — read the
[methodology note below](#how-to-read-these-honestly) before mapping them onto a
networked database.

## Per operation (CastBench, A/B)

In-memory, paired `*Vanilla` / `*Greased` subjects, so you read the per-operation
delta directly. Representative deltas (vanilla → greased):

| Operation | Δ |
| --- | :---: |
| hydrate a row | **−53%** |
| `toArray()` (serialize) | **−53%** |
| set + dirty-check | **−44%** |
| read all casts | **−31%** |
| read an enum cast | **−58%** |
| date serialization (per date column) | **~−92%** |

```bash
composer bench
```

The standout is **date serialization**: skipping the Carbon parse-and-reformat
round-trip saves roughly 27 µs *per date column per row*. On an API response with a
few timestamps across a hundred rows, that single tier is most of the win.

## Beyond Eloquent: the dispatcher

Measured separately (`DispatcherBench`, `EventStormBench`), because it's app-wide,
not per-model:

| Scenario | Δ |
| --- | :---: |
| dispatch with no listener | **−53%** |
| dispatch with listeners | **−18%** |
| event-dense request, warm | **−56%** |
| event-dense request, cold (with wildcards) | **−47%** |

On an event-dense request it roughly **halves** the event overhead. See
[The Event Dispatcher](/guide/events).

## How to read these honestly

This package was built measure-first, and the docs hold the same line. A few things
worth knowing so you can map these numbers onto *your* deploy:

::: warning In-memory SQLite inflates the percentage — read the absolute time
The macro runs on `:memory:` SQLite, where database I/O is near-zero. That makes the
ORM layer (and therefore Grease's slice) a *larger fraction* of total request time
than it would be against a networked Postgres/MySQL.

The portable figure is the **absolute time Grease removes from the ORM layer** — that
stays roughly the same regardless of your database. The **percentage** shrinks as
network and I/O take a bigger share of the request. So treat the per-request
percentages as "Grease's share of the Eloquent-bound work," not "your p99 will drop
17%." If your endpoint is I/O-bound, you'll see the same milliseconds saved against a
larger denominator.
:::

- **Per-op vs per-request.** A −53% on `hydrate` is a per-operation figure; it
  becomes a per-request figure only multiplied by how many rows you hydrate. The
  end-to-end table is the one that includes your SQL.
- **The win is workload-shaped.** Grease accelerates hydration, casting, and
  serialization. It does nothing for query building, validation, or your business
  logic. Profile first — if Eloquent isn't your hot path, this isn't your package.
- **Marginal numbers stay marginal numbers.** Where a tier benchmarked inside the
  noise, it was parked, not shipped, and that's recorded openly in the repo's notes.
  The figures here are the ones that cleared the bar.

## Reproduce everything

```bash
composer test                  # 208 parity tests — the byte-identical contract
composer bench                 # phpbench: CastBench (per-op A/B) + SuiteBench (SQL)
php benchmarks/realworld.php    # the end-to-end macro above
```

Same fixtures, both sides. A bench runs exactly what a test proves identical.
