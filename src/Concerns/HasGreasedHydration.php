<?php

namespace Grease\Concerns;

/**
 * Tier 1 — construction & hydration.
 *
 * Eloquent rebuilds class-pure state on every `new Model` (and therefore every
 * hydrated row): `initializeModelAttributes()` allocates a fresh ReflectionClass,
 * `initializeHasAttributes()` rebuilds the whole casts array, and
 * `newFromBuilder()` re-runs `newInstance()`'s setTable / mergeCasts / fill([]) /
 * double setConnection. None of it depends on instance state. This tier captures
 * it once per class and applies it by direct copy — the biggest win on any
 * workload that hydrates many rows.
 */
trait HasGreasedHydration
{
    use InteractsWithGreaseBlueprint;

    public function initializeModelAttributes()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['model'])) {
            parent::initializeModelAttributes();

            static::$greaseBlueprint[$class]['model'] = [
                $this->table, $this->connection, $this->primaryKey, $this->keyType, $this->incrementing,
            ];

            return;
        }

        [$this->table, $this->connection, $this->primaryKey, $this->keyType, $this->incrementing]
            = static::$greaseBlueprint[$class]['model'];
    }

    protected function initializeHasAttributes()
    {
        $class = static::class;

        if (! isset(static::$greaseBlueprint[$class]['castsInit'])) {
            parent::initializeHasAttributes();

            static::$greaseBlueprint[$class]['castsInit'] = [$this->casts, $this->dateFormat, $this->appends];

            return;
        }

        [$this->casts, $this->dateFormat, $this->appends] = static::$greaseBlueprint[$class]['castsInit'];
    }

    /**
     * Short-circuit the empty fill that `__construct` runs on every `new` model (and
     * therefore every hydrated row, via `newFromBuilder`'s `new static`).
     *
     * `fill([])` is pure waste: it still computes `totallyGuarded()` and
     * `fillableFromArray([])` up front, then loops over nothing and skips the discard
     * check (`count([]) !== count([])` is false). On the eager-load profile that
     * up-front `totallyGuarded()` is the single dominant self-time frame once the
     * `resolveClassAttribute` calls are frozen out — paid once per hydrated row for a
     * call that provably does nothing. Returning early on `[]` is byte-identical
     * (`fill([])` has no side effect and returns `$this`); any non-empty fill defers to
     * vanilla untouched. A model that overrides `fill()` shadows this entirely.
     */
    public function fill(array $attributes)
    {
        if ($attributes === []) {
            return $this;
        }

        return parent::fill($attributes);
    }

    /**
     * Slim hydration: __construct already applied the blueprint (table, casts,
     * connection defaults), so skip newInstance()'s redundant setTable /
     * mergeCasts(self-merge) / fill([]) / second setConnection.
     *
     * Caveat: a model that overrides newInstance() to inject construction-time
     * state during hydration should not use this tier (or should re-apply that
     * state here). Likewise a runtime setTable() on the prototype isn't carried onto
     * hydrated rows (they take the class-default table) — class-level $table, the
     * normal case, is applied by __construct. See the Caveats page.
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = new static;
        $model->exists = true;
        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?? $this->getConnectionName());
        $model->fireModelEvent('retrieved', false);

        return $model;
    }
}
