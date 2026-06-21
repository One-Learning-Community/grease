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
 *   - HasGreasedHydration   (construct / hydration)
 *   - HasGreasedAttributes  (cast/date/mutator metadata memoization)
 *   - HasGreasedCasts       (narrowed flyweight cast dispatch — see its caveat)
 *
 * Every tier is a method override that falls back to `parent::`, so output stays
 * byte-identical to vanilla Eloquent. Non-greased models are untouched.
 */
trait HasGrease
{
    use HasGreasedHydration;
    use HasGreasedAttributes;
    use HasGreasedCasts;
}
