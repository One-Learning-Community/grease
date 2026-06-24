<?php

namespace Grease\Tests\Routing;

use Grease\Routing\Router;
use Grease\Tests\Fixtures\Routing\TagMiddleware;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Shared boot-parity scaffold for the routing tier. Concrete subclasses differ only in
 * whether the `router` singleton is swapped for {@see Router}. Both serve the
 * SAME middleware-bearing route through a full kernel dispatch and must return the SAME
 * byte-identical response (body + the middleware-stamped header).
 *
 * This is the routing tier's equivalent of {@see \Grease\Tests\Container\BootParityTestCase}:
 * the served output is the contract, the oracle is vanilla.
 */
abstract class BootParityTestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
    }

    protected function defineRoutes($router): void
    {
        // A route with several middleware whose final order the priority map decides — the
        // resolve+sort path the tier caches. 'throttle' sits in the default priority map.
        $router->middlewareGroup('grease-stack', [TagMiddleware::class, 'throttle:1000,1']);

        $router->get('/grease-mw', fn () => response()->json(['ok' => true]))
            ->middleware('grease-stack');
    }

    public function test_serves_byte_identical_response(): void
    {
        $response = $this->get('/grease-mw')
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $this->assertSame('ran', $response->headers->get('X-Grease-Mw'));
    }
}
