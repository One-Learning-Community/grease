<?php

namespace Grease\Tests\Fixtures\Container;

use Attribute;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use ReflectionParameter;

/**
 * A contextual-resolution attribute — exercises the `resolveFromAttribute` path in the
 * blueprint's per-parameter handling.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class FromConfigValue implements ContextualAttribute
{
    public function __construct(public string $key)
    {
    }

    public function resolve(self $attribute, Container $container, ReflectionParameter $parameter): string
    {
        return 'cfg:'.$attribute->key;
    }
}
