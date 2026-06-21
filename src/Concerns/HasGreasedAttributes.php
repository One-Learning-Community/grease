<?php

namespace Grease\Concerns;

/**
 * Tier 2 — attribute metadata memoization.
 *
 * The class-pure helpers Eloquent recomputes on every read/write: `getCasts()`
 * (a fresh array_merge per call), `getDates()` (a fresh array per call), the
 * uncached `Str::studly` + method_exists in `hasGetMutator`/`hasSetMutator`, and
 * `getDateFormat()` (which resolves a connection + grammar per date cast). All
 * memoized here; the cast cache is kept honest under runtime `mergeCasts()`.
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

    public function getDates()
    {
        return static::$greaseBlueprint[static::class]['dates'] ??= parent::getDates();
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
}
