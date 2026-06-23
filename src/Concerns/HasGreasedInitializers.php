<?php

namespace Grease\Concerns;

/**
 * Tier — per-class init-state freeze for the four trait booters that survive the
 * other tiers' freezes.
 *
 * Eloquent runs a fixed set of `initialize*` trait booters on every `new` model
 * (via `initializeTraits()`), and each resolves class-level PHP attributes through
 * `Model::resolveClassAttribute()`:
 *
 *   - `GuardsAttributes::initializeGuardsAttributes` → `#[Fillable]`, `#[Unguarded]`, `#[Guarded]`
 *   - `HidesAttributes::initializeHidesAttributes`    → `#[Hidden]`, `#[Visible]`
 *   - `HasTimestamps::initializeHasTimestamps`        → `#[WithoutTimestamps]`, `#[Table]`(timestamps)
 *   - `HasRelationships::initializeHasRelationships`  → `#[Touches]`
 *
 * {@see HasGreasedClassAttributes} already made each `resolveClassAttribute()` *call* cheap
 * (a concat-free `[class][attr]` lookup) — yet on the eager-load profile the method is still
 * the single dominant self-time frame (~28% on the child rows), because the cache shaves the
 * per-call cost but not the **call frequency**: ~6–8 of these calls fire per hydrated row.
 *
 * Every property these four booters write is a pure function of the class (its PHP attributes
 * and its `$fillable`/`$guarded`/`$hidden`/`$visible`/`$timestamps`/`$touches` defaults) — no
 * instance state, no global state. So this tier extends the freeze pattern {@see HasGreasedHydration}
 * already applies to `initializeModelAttributes`/`initializeHasAttributes`: a trait method
 * overrides the inherited framework-trait booter (different inheritance levels — no `insteadof`),
 * the cold path runs `parent::` once and snapshots the resulting properties into the shared
 * per-class blueprint, and every warm instance applies the snapshot by direct copy — the
 * `resolveClassAttribute()` calls never run again. On the eager path that drives its self-time
 * toward zero.
 *
 * **Byte-identical, by construction:** the snapshot captures each booter's *post-merge* result
 * (so the warm copy is an overwrite, never a double-merge), and the cold path is verbatim
 * vanilla. The booters write only `$this->{property}` — none mutate static or global state
 * (the `#[Unguarded]` branch sets `$this->guarded = []`, it does *not* call `unguard()`), so a
 * snapshot-and-skip preserves exactly what vanilla leaves behind. Runtime mutation after
 * construction (`mergeFillable()`, `unguard()`, toggling `$timestamps`, …) is untouched: the
 * freeze only replaces *init-time* resolution, and the instance properties it sets behave
 * normally thereafter. No divergence guard is needed — unlike `getCasts()`, these properties
 * are read straight off the instance (`getFillable()` etc.), never re-derived from a per-class
 * cache a later mutation could leave stale.
 *
 * Keyed by `static::class` via the blueprint, so STI subclasses with differing
 * fillable/hidden/timestamp config never share a snapshot, and user-defined `initialize*`
 * booters (dispatched by the same `initializeTraits()` loop) run untouched.
 */
trait HasGreasedInitializers
{
    use InteractsWithGreaseBlueprint;

    public function initializeGuardsAttributes()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['guardsInit'])) {
            parent::initializeGuardsAttributes();

            static::$greaseBlueprint[$class]['guardsInit'] = [$this->fillable, $this->guarded];

            return;
        }

        [$this->fillable, $this->guarded] = static::$greaseBlueprint[$class]['guardsInit'];
    }

    public function initializeHidesAttributes()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['hidesInit'])) {
            parent::initializeHidesAttributes();

            static::$greaseBlueprint[$class]['hidesInit'] = [$this->hidden, $this->visible];

            return;
        }

        [$this->hidden, $this->visible] = static::$greaseBlueprint[$class]['hidesInit'];
    }

    public function initializeHasTimestamps()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['timestampsInit'])) {
            parent::initializeHasTimestamps();

            static::$greaseBlueprint[$class]['timestampsInit'] = [$this->timestamps];

            return;
        }

        [$this->timestamps] = static::$greaseBlueprint[$class]['timestampsInit'];
    }

    public function initializeHasRelationships()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['touchesInit'])) {
            parent::initializeHasRelationships();

            static::$greaseBlueprint[$class]['touchesInit'] = [$this->touches];

            return;
        }

        [$this->touches] = static::$greaseBlueprint[$class]['touchesInit'];
    }
}
