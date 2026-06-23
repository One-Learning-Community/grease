<?php

namespace Grease\Tests\Container;

use Grease\Tests\Fixtures\Container\SpikeController;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Shared boot-parity scaffold. Concrete subclasses differ only in which container the
 * fully-configured Testbench application is built on — vanilla
 * {@see \Illuminate\Foundation\Application} vs {@see \Grease\Container\Application}. Both
 * serve the SAME route and must return the SAME byte-identical response.
 *
 * This is the container tier's equivalent of the model parity suite: the served output
 * is the contract, the oracle is vanilla.
 */
abstract class BootParityTestCase extends Orchestra
{
    /**
     * The expected served body — identical regardless of container implementation.
     *
     * @var array<string, mixed>
     */
    final protected const EXPECTED = [
        'ctor' => 'greased',
        'method' => 'greased',
        'q' => 'hello',
        'same_instance' => false,
    ];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/spike', [SpikeController::class, 'show']);
    }

    public function test_serves_byte_identical_response(): void
    {
        $this->get('/spike?q=hello')
            ->assertOk()
            ->assertExactJson(static::EXPECTED);
    }
}
