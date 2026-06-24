<?php

namespace Grease\Tests\Validation;

use Grease\Validation\GreaseValidationServiceProvider;
use Grease\Validation\Validator as GreasedValidator;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The provider points the validation Factory's resolver at the greased validator, so every validator
 * the framework builds (FormRequests, `Validator::make`, `$request->validate`) is greased and behaves
 * identically.
 */
class GreaseValidationServiceProviderTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GreaseValidationServiceProvider::class];
    }

    public function test_factory_builds_the_greased_validator(): void
    {
        $validator = $this->app['validator']->make(
            ['name' => 'Alice'],
            ['name' => 'required|string'],
        );

        $this->assertInstanceOf(GreasedValidator::class, $validator);
    }

    public function test_greased_validator_validates_correctly(): void
    {
        $factory = $this->app['validator'];

        $this->assertTrue($factory->make(['age' => 30], ['age' => 'required|integer|min:18'])->passes());
        $this->assertFalse($factory->make(['age' => 5], ['age' => 'required|integer|min:18'])->passes());

        $failing = $factory->make(['email' => 'nope'], ['email' => 'required|email']);
        $this->assertTrue($failing->fails());
        $this->assertArrayHasKey('email', $failing->errors()->toArray());
    }
}
