<?php

namespace Grease\Tests\Fixtures\Container;

/** A primitive with no default — unresolvable unless a contextual `$size` is bound. */
class NeedsPrimitive
{
    public function __construct(public int $size)
    {
    }
}
