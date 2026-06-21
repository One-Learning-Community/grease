<?php

namespace Grease\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * A trivial custom cast (the documented extension point). Grease must defer to
 * it identically to vanilla — this is the "write a custom cast instead" promise.
 */
class UpperCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return is_null($value) ? null : strtoupper((string) $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return is_null($value) ? null : strtolower((string) $value);
    }
}
