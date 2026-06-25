<?php

namespace Grease\Concerns;

use Grease\Database\Eloquent\Builder as GreasedBuilder;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * Tier — swap the default Eloquent builder for the greased one ({@see GreasedBuilder},
 * which memoizes the `__call` dispatch verdict).
 *
 * Only the DEFAULT builder is greased; both vanilla customization paths win untouched:
 * a `#[UseEloquentBuilder]` attribute (via `resolveCustomBuilderClass()`) and a
 * `static::$builder` override. So a model that opts into a custom builder keeps it —
 * behaviour-identical, just unaccelerated.
 */
trait HasGreasedQueries
{
    /** {@inheritDoc} */
    public function newEloquentBuilder($query)
    {
        $builderClass = $this->resolveCustomBuilderClass();

        if ($builderClass && is_subclass_of($builderClass, BaseBuilder::class)) {
            return new $builderClass($query);
        }

        if (static::$builder !== BaseBuilder::class) {
            return new static::$builder($query);
        }

        return new GreasedBuilder($query);
    }
}
