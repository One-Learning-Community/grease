<?php

namespace Grease\Concerns;

/**
 * Tier 1 — construction & hydration.
 *
 * Eloquent rebuilds class-pure state on every `new Model` (and therefore every
 * hydrated row): `initializeModelAttributes()` allocates a fresh ReflectionClass,
 * `initializeHasAttributes()` rebuilds the whole casts array, and
 * `newFromBuilder()` re-runs `newInstance()`'s cast self-merge / fill([]) /
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

            $snapshot = [$this->table, $this->connection, $this->primaryKey, $this->keyType, $this->incrementing];

            // 13.33+ also resolves `#[Refreshes]` here. Snapshot it only when set, so the warm
            // path is a single isset() on Laravel 12 (no such property — writing it would fall
            // through Model::__set into the attributes) and on the common no-attribute model.
            if (property_exists($this, 'refreshes') && $this->refreshes !== []) {
                $snapshot[5] = $this->refreshes;
            }

            static::$greaseBlueprint[$class]['model'] = $snapshot;

            return;
        }

        $snapshot = static::$greaseBlueprint[$class]['model'];

        [$this->table, $this->connection, $this->primaryKey, $this->keyType, $this->incrementing] = $snapshot;

        if (isset($snapshot[5])) {
            $this->refreshes = $snapshot[5];
        }
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
     * Memoize the derived table name. Vanilla re-derives
     * `Str::snake(Str::pluralStudly(class_basename($this)))` on every call for a model with
     * no `$table` — a class-pure string, but `getTable()` runs per hydrated row (via
     * newFromBuilder) and again under every `qualifyColumn()`/relation/`save()`. An explicit
     * or runtime-set `$table` still wins first, exactly as vanilla. (The one input outside
     * the class is the Pluralizer language: set it with `Pluralizer::useLanguage()` at boot,
     * before models are used — the same point config is read.)
     */
    public function getTable()
    {
        return $this->table ?? (static::$greaseBlueprint[static::class]['derivedTable'] ??= parent::getTable());
    }

    /**
     * Slim hydration: __construct already applied the blueprint (casts, connection
     * defaults), so skip newInstance()'s redundant self-merge of casts, fill([]) and
     * first setConnection.
     *
     * Two things newInstance() carries from the *prototype* (the query's model) are kept,
     * because they can differ from the class defaults at runtime:
     *  - its table (`setTable($this->getTable())`) — a runtime `setTable('orders_2023')`
     *    on the prototype must reach the hydrated rows, or their save()/delete() would hit
     *    the class table; a derived table name is written onto the row just as vanilla does.
     *  - its casts — `Builder::withCasts()` merges query-time casts into the prototype and
     *    relies on newInstance() to hand them on. Instances share the blueprint's casts
     *    array, so the `!==` is a pointer compare until the prototype actually diverged.
     *
     * Caveat: a model that overrides newInstance() to inject construction-time state
     * during hydration should not use this tier (or should re-apply that state here).
     */
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $model = new static;
        $model->exists = true;
        $model->setTable($this->getTable());

        if ($this->casts !== $model->casts) {
            $model->mergeCasts($this->casts);
        }

        $model->setRawAttributes((array) $attributes, true);
        $model->setConnection($connection ?? $this->getConnectionName());
        $model->fireModelEvent('retrieved', false);

        return $model;
    }
}
