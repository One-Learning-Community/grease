<?php

namespace Grease\Tests\Fixtures\Container;

/**
 * A trivial transient service resolved through the container's build path — both as a
 * controller constructor dependency and a method-injected dependency.
 */
class SpikeService
{
    public function greeting(): string
    {
        return 'greased';
    }
}
