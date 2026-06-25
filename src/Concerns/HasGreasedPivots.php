<?php

namespace Grease\Concerns;

use Grease\Eloquent\Pivot as GreasedPivot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;

/**
 * Tier — greased pivot hydration for many-to-many relations.
 *
 * `BelongsToMany` builds its pivot via the *related* model's `newPivot()`
 * ({@see InteractsWithPivotTable::newPivot}
 * calls `$this->related->newPivot(...)`), and vanilla returns a bare
 * `Illuminate\…\Relations\Pivot` that carries none of Grease's tiers. So a greased
 * related model still hydrates ungreased pivots — one per related row, each paying the
 * full per-row booter/cast/timestamp cost. This override returns {@see GreasedPivot}
 * instead, so default pivots get the same byte-identical acceleration as any model.
 *
 * Two deliberate carve-outs, both byte-identical (defer to exact vanilla behaviour):
 *  - a relation with `using(CustomPivot::class)` keeps building that class (the
 *    `$using` branch is untouched — the encrypted-cast precedent);
 *  - `MorphToMany` is unaffected — it overrides `newPivot()` on the *relation* and
 *    builds `MorphPivot` directly, never reaching this model seam, so morph pivots
 *    stay vanilla (correct, just unaccelerated).
 *
 * `$parent` is typed `Model` to match the inherited `Model::newPivot(self $parent, …)`
 * signature exactly (`self` resolves to `Model` there), so this is a clean override.
 */
trait HasGreasedPivots
{
    public function newPivot(Model $parent, array $attributes, $table, $exists, $using = null)
    {
        return $using
            ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
            : GreasedPivot::fromAttributes($parent, $attributes, $table, $exists);
    }
}
