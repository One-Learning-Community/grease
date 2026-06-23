<?php

namespace Grease\Tests\Fixtures\Container;

/** A nested dependency — forces a recursive build (Dep3 → Dep1). */
class Dep3
{
    public function __construct(public Dep1 $a) {}
}
