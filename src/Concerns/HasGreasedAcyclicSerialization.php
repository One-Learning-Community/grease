<?php

namespace Grease\Concerns;

use Grease\GreasedModel;

/**
 * Opt out of Eloquent's circular-reference guard — for models whose relation graph is acyclic.
 *
 * Eloquent wraps four methods in `PreventsCircularRecursion::withoutRecursion()`, which runs a
 * `debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2)` (via `Onceable::hashFromTrace`) plus a
 * `WeakMap` on **every** call, to survive a self-referential object graph:
 *
 *   - `toArray()`               — every serialization / `toJson()` (the API-response tax)
 *   - `getQueueableRelations()` — every model with relations pushed to a queue or broadcast
 *   - `touchOwners()`           — every save of a model declaring `$touches`
 *   - `push()`                  — save-with-relations
 *
 * A true cycle needs the *same object* reachable from itself — which ordinary eager loading
 * can't produce (`User::with('posts.user')` yields a fresh User per post, not the original
 * back). You essentially only reach it with self-referential tree models (a `Category` with
 * both `parent` and `children` loaded) or manual `setRelation()` wiring. So the flat-graph
 * majority pays a stack walk on every serialize / queue / touch to protect a graph they don't
 * have. This trait lets a model say "mine is acyclic" and skip the guard entirely:
 *
 *   class Invoice extends Model
 *   {
 *       use HasGrease, HasGreasedAcyclicSerialization;
 *   }
 *
 * All four methods route through `$this->withoutRecursion()`, so this one override removes the
 * tax from all of them at once.
 *
 * **Byte-identical for acyclic data.** The guard only ever changes output when one of those
 * methods *re-enters the same object* — i.e. a cycle. With no cycle it is pure overhead, so
 * skipping it returns exactly what vanilla returns (proven across relation-less / belongsTo /
 * hasMany / deep-nested / belongsToMany-pivot / hidden shapes, for both `toArray()` and
 * `getQueueableRelations()`, in tests/HasGreasedAcyclicSerializationParityTest.php).
 *
 * **The one responsibility you take on.** If a model using this trait *does* have a cyclic
 * graph (a model reachable from its own loaded relations), `toArray()`/`push()`/… will recurse
 * until the stack overflows — there is no guard to break the loop. That is the entire point of
 * the opt-in: only the application author knows whether the graph can close on itself. Do **not**
 * add this trait to self-referential tree models (adjacency lists with `parent`+`children`
 * eager-loaded), polymorphic graphs that can point back, or anywhere relations are wired by hand
 * into a loop. When unsure, leave it off — the guard stays, byte-identical and safe.
 *
 * Deliberately **not** bundled into {@see HasGrease}/{@see GreasedModel}: it is a promise
 * only you can make, so it is always an explicit, per-model opt-in (the `HasGreasedDecimalCasts`
 * precedent). It needs nothing else from Grease — it works standalone on a plain model — and
 * composes with `HasGrease` (whose relation-less `toArray()` short-circuit already avoids even
 * calling `withoutRecursion`; this covers every other shape).
 */
trait HasGreasedAcyclicSerialization
{
    /**
     * {@inheritDoc}
     *
     * The acyclic promise: run the wrapped work directly, with no `debug_backtrace`/`WeakMap`
     * re-entry guard. Identical output to vanilla whenever the graph has no cycle.
     */
    protected function withoutRecursion($callback, $default = null)
    {
        return $callback();
    }
}
