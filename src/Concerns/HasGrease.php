<?php

namespace Grease\Concerns;

/**
 * The umbrella trait — pulls in every Grease tier at once.
 *
 *   use Grease\Concerns\HasGrease;
 *
 *   class User extends Model
 *   {
 *       use HasGrease;
 *   }
 *
 * Prefer a single tier? Use it directly instead — they compose freely and share
 * one per-class blueprint:
 *   - HasGreasedHydration       (construct / hydration)
 *   - HasGreasedAttributes      (cast/date/mutator metadata memoization)
 *   - HasGreasedClassAttributes (class-attribute resolution: #[Table]/#[Fillable]/…)
 *   - HasGreasedInitializers    (per-class freeze of the guards/hides/timestamps/touches booters)
 *   - HasGreasedCasts           (narrowed flyweight cast dispatch — see its caveat)
 *   - HasGreasedCastProbes      (per-key isEnum/isClass/isClassSerializable cast-probe memo)
 *   - HasGreasedSerialization   (date serialization round-trip elimination)
 *   - HasGreasedPivots          (greased pivot model for many-to-many relations)
 *
 * Every tier is a method override that falls back to `parent::`, so output stays
 * byte-identical to vanilla Eloquent. Non-greased models are untouched.
 *
 * Deliberately NOT bundled: `HasGreasedQueries` (the Eloquent builder `__call`
 * dispatch-verdict memo). It's behaviour-identical and audited, but it swaps a custom
 * builder in for *every* query on the model app-wide for a sub-0.1%-of-a-real-request
 * gain (the dominant `where`/`orWhere` verbs bypass `__call` entirely). That reach
 * isn't worth a default — add `use HasGreasedQueries;` explicitly on a model only if
 * you're chasing every last cycle (e.g. a query-construction-heavy admin/reporting path).
 *
 * Also NOT bundled: `HasGreasedDecimalCasts` (the `decimal:N` Brick\Math identity
 * short-circuit). It's byte-identical and heavily fuzzed, but `decimal` usually means money,
 * so it's left as a separate, explicit opt-in you can audit and roll back in isolation — add
 * `use HasGreasedDecimalCasts;` on a model deliberately. The win lands on drivers that return
 * canonical decimal strings (MySQL/PostgreSQL); on SQLite (floats) it defers, byte-identical,
 * with no speedup.
 */
trait HasGrease
{
    use HasGreasedAttributes;
    use HasGreasedCastProbes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedInitializers;
    use HasGreasedPivots;
    use HasGreasedSerialization;
}
