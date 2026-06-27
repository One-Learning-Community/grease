# Acyclic Serialization

Opt out of a tax almost every app pays for a problem almost no app has.

Eloquent wraps four methods in a circular-reference guard — and pays for it with a
`debug_backtrace` on **every single call**:

| method | runs on |
| --- | --- |
| `toArray()` | every serialization / `toJson()` — i.e. every API response |
| `getQueueableRelations()` | every model with relations pushed to a queue or broadcast |
| `touchOwners()` | every save of a model declaring `$touches` |
| `push()` | save-with-relations |

The guard exists to survive a **self-referential object graph** — a model reachable from its
own loaded relations, which would otherwise recurse forever. But a true cycle needs the *same
object* reachable from itself, and ordinary eager loading can't produce that:
`User::with('posts.user')` gives you a *fresh* `User` per post, not the original object back.
You essentially only reach a cycle with self-referential **tree models** (an adjacency-list
`Category` with both `parent` and `children` eager-loaded) or relations wired into a loop by
hand. So the flat-graph majority runs a `debug_backtrace` on every serialize, every queue,
every touch — to protect against a shape they don't have.

This trait lets a model say so:

```php
use Grease\Concerns\HasGreasedAcyclicSerialization;

class Invoice extends Model
{
    use HasGrease, HasGreasedAcyclicSerialization;   // "my graph isn't self-referential"
}
```

## What it does

All four methods route through one protected method, `withoutRecursion()`. The trait overrides
it to run the wrapped work directly — no `debug_backtrace`, no `WeakMap`:

```php
protected function withoutRecursion($callback, $default = null)
{
    return $callback();
}
```

One override, the tax gone from all four paths at once.

## Byte-identical — for acyclic data

The guard only ever changes output when one of those methods **re-enters the same object** —
that is, when a cycle exists. With no cycle it is pure overhead, so skipping it returns exactly
what vanilla returns. `HasGreasedAcyclicSerializationParityTest` proves it byte-for-byte against
vanilla across relation-less, `belongsTo`, `hasMany`, deep-nested, `belongsToMany`-with-pivot,
hidden, and null-relation shapes — for both `toArray()` and `getQueueableRelations()`, on a plain
model, a `HasGrease` model, and the two composed.

## The one responsibility you take on

This is the trade, stated plainly: **if a model using this trait actually has a cyclic graph, its
`toArray()`/`push()`/… will recurse until the stack overflows.** There is no guard left to break
the loop. That is the whole point of the opt-in — only you know whether your graph can close on
itself.

Leave it **off** for:

- self-referential **tree models** — adjacency lists with `parent` + `children` eager-loaded,
- **polymorphic** graphs that can point back at an ancestor,
- anything where you `setRelation()` models into each other by hand.

When unsure, don't add it. The guard stays, byte-identical and safe — you just keep paying for it.

## What it's worth

The saving is **~1,230 ns per relation-bearing model** — one `debug_backtrace` eliminated each.
So the win tracks *how many models in a response carry relations*, not row count. Measured on
Linux with opcache + JIT, on greased models (`benchmarks/acyclic_ab.php`):

| shape | greased `toArray` delta |
| --- | --- |
| `belongsTo` (one related model) | **−35%** |
| deep, 3 levels nested | **−43.6%** |
| `belongsToMany` (5, with pivot) | **−26.8%** |
| **list of 100 rows, each with a relation** (the real API index shape) | **−36%** |
| relation-less | ~0 — already short-circuited by [the serialization tier](/guide/serialization-helpers) |
| one row with a 100-model child collection | ~0 — only the single parent paid the guard |

The headline is the list: a 100-row `with()` response sheds ~130 µs of pure `debug_backtrace`,
byte-identical. This is the broadest serialization win in Grease — it lands on **every `with()`
list endpoint**, the most common API shape there is.

## Opt in

One trait, per model. Standalone (works on a plain model) or stacked on `HasGrease`:

```php
class Order extends Model
{
    use HasGrease, HasGreasedAcyclicSerialization;
}
```

It is **deliberately not** part of the `HasGrease` umbrella or `GreasedModel`. Acyclicity is a
promise only the application author can make, so it is always an explicit opt-in — never on by
default, never inferred.
