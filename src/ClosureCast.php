<?php

namespace Grease;

use Illuminate\Database\Eloquent\Model;

/**
 * A flyweight cast object built from closures.
 *
 * Each built-in cast type resolves to a single shared instance: the closures
 * take the model + key as arguments and defer to the model's own value methods
 * (asDateTime, fromJson, asDecimal, …) at call time, so one instance backs every
 * field of a given cast type across the whole process. The dispatch decision —
 * which cast type applies — is made once and memoized, instead of re-walking a
 * switch on every attribute access.
 *
 * @template TValue
 * @template TRawValue
 *
 * @internal
 */
class ClosureCast
{
    /** @var callable(Model, string, TRawValue, array<string, mixed>): TValue */
    protected $get;

    /** @var callable(Model, string, TValue, array<string, mixed>): TRawValue */
    protected $set;

    /** @var callable(Model, string, TValue, TValue): bool */
    protected $comparator;

    /**
     * @param  callable(Model, string, TRawValue, array<string, mixed>): TValue  $get
     * @param  (callable(Model, string, TValue, array<string, mixed>): TRawValue)|null  $set
     * @param  (callable(Model, string, TValue, TValue): bool)|null  $comparator
     */
    public function __construct(
        callable $get,
        ?callable $set = null,
        ?callable $comparator = null,
        public bool $setsOwnAttribute = false,
        protected bool $nullable = true,
    ) {
        $this->get = $get;
        $this->set = $set ?: fn ($model, $key, $value) => $value;
        $this->comparator = $comparator ?: fn () => false;
    }

    /**
     * Transform the attribute from its stored value.
     *
     * @param  Model  $model
     * @param  TRawValue  $value
     * @param  array<string, mixed>  $attributes
     * @return TValue
     */
    public function get($model, string $key, mixed $value, array $attributes)
    {
        if (is_null($value) && $this->nullable) {
            return null;
        }

        return ($this->get)($model, $key, $value, $attributes);
    }

    /**
     * Transform the attribute to its stored value.
     *
     * @param  Model  $model
     * @param  TValue  $value
     * @param  array<string, mixed>  $attributes
     * @return TRawValue
     */
    public function set($model, string $key, mixed $value, array $attributes)
    {
        return ($this->set)($model, $key, $value, $attributes);
    }

    /**
     * Determine if two values are equivalent for dirty-checking.
     *
     * @param  Model  $model
     * @param  TValue  $first
     * @param  TValue  $second
     */
    public function compare($model, string $key, mixed $first, mixed $second): bool
    {
        return (bool) ($this->comparator)($model, $key, $first, $second);
    }
}
