<?php

namespace Grease\Eloquent;

use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedPivots;
use Illuminate\Database\Eloquent\Relations\Pivot as BasePivot;

/**
 * A greased drop-in for Eloquent's default pivot model.
 *
 * The pivot of a many-to-many is a "dynamic model" the framework hydrates internally
 * for every related row, and it never carries Grease's tiers — so a pivot-heavy
 * `belongsToMany()->get()` pays, per row, the exact per-row taxes the model tiers
 * remove (the `initialize*` booters, `resolveClassAttribute`, and the timestamp
 * Carbon round-trip on `withTimestamps()` pivots). This subclass is nothing but a
 * `Pivot` with `HasGrease`; {@see HasGreasedPivots} swaps it in.
 *
 * Byte-identical to a vanilla pivot — it IS a vanilla pivot plus the (byte-identical)
 * tiers. `setTable()`/`setConnection()` still run per instance in `AsPivot::fromAttributes`
 * after construction, so the per-class blueprint's cached table/connection defaults are
 * overwritten exactly as vanilla — the dynamic-table contract is preserved.
 */
class Pivot extends BasePivot
{
    use HasGrease;
}
