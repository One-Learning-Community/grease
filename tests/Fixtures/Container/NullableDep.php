<?php

namespace Grease\Tests\Fixtures\Container;

/** A nullable, defaulted class dependency — resolveClass's default-value branch. */
class NullableDep
{
    public function __construct(public ?Dep1 $a = null) {}
}
