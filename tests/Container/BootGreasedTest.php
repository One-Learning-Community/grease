<?php

namespace Grease\Tests\Container;

use Grease\Container\Application;
use Grease\Container\Application as GreasedApplication;
use Grease\Container\ResolvesWithGreaseBlueprint;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use ReflectionProperty;

/**
 * Greased arm: a fully-configured Testbench application built on
 * {@see Application}. Boot resolves hundreds of providers/services and
 * dispatch resolves the controller's constructor + method dependencies — all through the
 * greased constructor-blueprint {@see ResolvesWithGreaseBlueprint}. It
 * must boot, serve, and return a response byte-identical to {@see BootVanillaTest}.
 */
class BootGreasedTest extends BootParityTestCase
{
    /**
     * Mirror Testbench's (final) resolveDefaultApplication(), but build on the greased
     * container. This is exactly the one-line opt-in a real app makes in bootstrap/app.php.
     */
    protected function resolveApplication()
    {
        return (new ApplicationBuilder(new GreasedApplication($this->getApplicationBasePath())))
            ->withProviders()
            ->withMiddleware(function ($middleware) {
                //
            })
            ->withCommands()
            ->create();
    }

    public function test_application_is_greased(): void
    {
        $this->assertInstanceOf(GreasedApplication::class, $this->app);
    }

    public function test_blueprint_is_genuinely_exercised_during_boot(): void
    {
        // Drive a real request so boot + dispatch both flow through build().
        $this->get('/spike?q=hello')->assertOk();

        $plans = (new ReflectionProperty($this->app, 'greaseBuildPlans'))->getValue($this->app);

        // A real Laravel boot resolves far more than 20 concrete classes transiently;
        // a low count would mean resolution bypassed the greased path.
        $this->assertGreaterThan(20, count($plans));
    }
}
