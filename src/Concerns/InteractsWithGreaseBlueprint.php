<?php

namespace Grease\Concerns;

/**
 * The shared per-class "blueprint" store that every Grease tier reads from.
 *
 * ONE static keyed by class (`static::$greaseBlueprint[$class]`) holds every
 * class-pure value the tiers memoize (resolved table/key config, the casts
 * baseline, mutator maps, …). A single store means invalidation is atomic —
 * dropping `static::$greaseBlueprint[$class]` clears the whole blueprint
 * coherently — and the external property surface is one name, not a dozen.
 *
 * Carve-outs that deliberately live elsewhere: connection-scoped caches (date
 * format) and per-instance flags (cast divergence) — different key domains and
 * invalidation triggers.
 */
trait InteractsWithGreaseBlueprint
{
    /**
     * The per-class blueprint, keyed by `static::class` so STI subclasses with
     * differing config never share an entry.
     *
     * @var array<class-string, array<string, mixed>>
     */
    protected static array $greaseBlueprint = [];

    /**
     * Drop the cached blueprint for this class (e.g. after a structural change
     * to casts/appends in a test). The next instance rebuilds it lazily.
     */
    public static function flushGreaseBlueprint(): void
    {
        unset(static::$greaseBlueprint[static::class]);
    }

    /**
     * Keep the blueprint in lockstep with Eloquent's own booted-model reset, so
     * tests that rebuild models never observe a stale blueprint.
     */
    public static function clearBootedModels()
    {
        static::$greaseBlueprint = [];

        parent::clearBootedModels();
    }
}
