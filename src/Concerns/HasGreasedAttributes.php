<?php

namespace Grease\Concerns;

/**
 * Tier 2 — attribute metadata memoization.
 *
 * The class-pure helpers Eloquent recomputes on every read/write: `getCasts()`
 * (a fresh array_merge per call), `getCastType()` (a string->type re-walk on
 * every cast access), `getDates()` (a fresh array per call), the uncached
 * `Str::studly` + method_exists in `hasGetMutator`/`hasSetMutator`, and
 * `getDateFormat()` (which resolves a connection + grammar per date cast). All
 * memoized here; the cast caches are kept honest under runtime `mergeCasts()`.
 */
trait HasGreasedAttributes
{
    use InteractsWithGreaseBlueprint;

    /**
     * Date format cached by connection NAME, not class — its key domain and its
     * invalidation trigger (connection reconfig / Octane) differ, so it stays
     * out of the per-class blueprint.
     *
     * @var array<string, string>
     */
    protected static array $greaseDateFormatByConnection = [];

    /** Set when a runtime mergeCasts() genuinely changes this instance's casts. */
    protected bool $greaseCastsDiverged = false;

    public function getCasts()
    {
        if ($this->greaseCastsDiverged) {
            return parent::getCasts();
        }

        return static::$greaseBlueprint[static::class]['casts'] ??= parent::getCasts();
    }

    protected function getCastType($key)
    {
        // The resolved cast type is a pure function of getCasts()[$key] (already
        // memoized above) — so cache it per key, exactly like getCasts() itself,
        // instead of re-walking the string->type conversion on every cast access.
        // Diverged instances defer to live resolution. A subclass that genuinely
        // overrides getCastType() shadows this trait method entirely (a class
        // method wins over a trait method), so real overrides stay live and fully
        // honored — they simply opt out of the cache.
        if ($this->greaseCastsDiverged) {
            return parent::getCastType($key);
        }

        return static::$greaseBlueprint[static::class]['castTypes'][$key] ??= parent::getCastType($key);
    }

    public function getDates()
    {
        // Keyed by usesTimestamps() — the one per-instance input to getDates(). A
        // model with timestamps disabled ($model->timestamps = false /
        // withoutTimestamps(), both normal Eloquent) returns [], and must not poison
        // the cache for timestamps-on instances of the same class (which would drop
        // created_at/updated_at from their serialization). The column names are
        // class-level, so two slots cover every instance.
        return static::$greaseBlueprint[static::class]['dates'][$this->usesTimestamps()] ??= parent::getDates();
    }

    public function hasGetMutator($key)
    {
        return static::$greaseBlueprint[static::class]['getMutators'][$key] ??= parent::hasGetMutator($key);
    }

    public function hasSetMutator($key)
    {
        return static::$greaseBlueprint[static::class]['setMutators'][$key] ??= parent::hasSetMutator($key);
    }

    public function getDateFormat()
    {
        if ($this->dateFormat) {
            return $this->dateFormat;
        }

        return static::$greaseDateFormatByConnection[$this->getConnectionName() ?? '@default']
            ??= $this->getConnection()->getQueryGrammar()->getDateFormat();
    }

    public function mergeCasts($casts)
    {
        $before = $this->casts;

        $result = parent::mergeCasts($casts);

        // A genuine change (withCasts / runtime mergeCasts) — stop trusting the
        // per-class cast cache for this instance. Hydration self-merges, which
        // re-merge identical casts, leave $this->casts unchanged and stay fast.
        if ($this->casts !== $before) {
            $this->greaseCastsDiverged = true;
        }

        return $result;
    }

    // getCasts() prepends [keyName => keyType] when incrementing, so these three
    // per-instance properties feed its output. They're class-level in normal use
    // (read once into the cache), but a *runtime* setter must drop this instance off
    // the cached path — same divergence flag as mergeCasts(). The framework never
    // calls these internally (hydration/relations don't touch them), so the warm path
    // is unaffected; only explicit user calls diverge, and only the calling instance.

    public function setKeyName($key)
    {
        $this->greaseCastsDiverged = true;

        return parent::setKeyName($key);
    }

    public function setKeyType($type)
    {
        $this->greaseCastsDiverged = true;

        return parent::setKeyType($type);
    }

    public function setIncrementing($value)
    {
        $this->greaseCastsDiverged = true;

        return parent::setIncrementing($value);
    }
}
