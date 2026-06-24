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
 *   - HasGreasedSerialization   (date serialization round-trip elimination)
 *
 * Every tier is a method override that falls back to `parent::`, so output stays
 * byte-identical to vanilla Eloquent. Non-greased models are untouched.
 */
trait HasGrease
{
    use HasGreasedAttributes;
    use HasGreasedCastProbes;
    use HasGreasedCasts;
    use HasGreasedClassAttributes;
    use HasGreasedHydration;
    use HasGreasedInitializers;
    use HasGreasedSerialization;
}
