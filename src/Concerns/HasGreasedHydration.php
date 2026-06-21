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
     * Slim hydration: __construct already applied the blueprint (table, casts,
     * connection defaults), so skip newInstance()'s redundant setTable /
     * mergeCasts(self-merge) / fill([]) / second setConnection.
     *
     * Caveat: a model that overrides newInstance() to inject construction-time
     * state during hydration should not use this tier (or should re-apply that
     * state here).
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
