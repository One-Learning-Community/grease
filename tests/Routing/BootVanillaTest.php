<?php

namespace Grease\Tests\Routing;

use Illuminate\Routing\Router;

/**
 * Vanilla arm: the stock Testbench application (no router swap). Establishes the oracle
 * served response that {@see BootGreasedTest} must match byte-for-byte.
 */
class BootVanillaTest extends BootParityTestCase
{
    public function test_router_is_vanilla(): void
    {
        $this->assertSame(Router::class, $this->app['router']::class);
    }
}
