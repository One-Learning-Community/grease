<?php

namespace Grease\Tests\Fixtures\Container;

/** A variadic class dependency — resolveVariadicClass via a contextual binding. */
class Collector
{
    public array $items;

    public function __construct(Dep1 ...$items)
    {
        $this->items = $items;
    }
}
