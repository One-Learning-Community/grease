<?php

namespace Grease\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/**
 * A backed enum that ALSO implements Castable. Vanilla's isEnumCastable() returns
 * false for any type that is a Castable subclass, so such an enum routes to its
 * castUsing() cast — NOT the enum conversion path. The greased enum fast path must
 * exclude it for the same reason; gating on enum_exists() alone would return the
 * raw case instead of the cast's output.
 */
enum CastableColor: string implements Castable
{
    case Red = 'red';
    case Blue = 'blue';

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get($model, string $key, $value, array $attributes)
            {
                return is_null($value) ? null : 'COLOR:'.$value;
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return is_null($value) ? null : (string) $value;
            }
        };
    }
}
