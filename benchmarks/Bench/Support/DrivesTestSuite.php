<?php

namespace Grease\Bench\Support;

use ReflectionClass;
use ReflectionMethod;

/**
 * Bridges phpbench to a real PHPUnit/Testbench test case so an entire test file
 * can be driven as benchmark subjects: the case is booted once in setUp (excluded
 * from timing) and each no-argument `test*` method becomes a subject.
 *
 * Note: the case's tearDown() is intentionally NOT called — Testbench's teardown
 * flushes state through PHPUnit's runtime (Configuration registry / exception
 * handler), which isn't initialized under the phpbench process and would fatal.
 */
abstract class DrivesTestSuite
{
    protected object $case;

    /** The PHPUnit test case class to drive. */
    abstract protected function caseClass(): string;

    public function setUp(): void
    {
        $class = $this->caseClass();
        $this->case = new $class('benchmark');

        $this->invoke('setUp');
    }

    public function tearDown(): void
    {
        // Intentionally empty — see the class docblock.
    }

    public function runTest(array $params): void
    {
        try {
            $this->case->{$params['method']}();
        } catch (\Throwable) {
            // Some tests intentionally assert exceptions; ignore for timing.
        }
    }

    /**
     * Every zero-argument test method (data-provider tests are skipped — they
     * require arguments phpbench can't supply).
     */
    public function provideTestMethods(): iterable
    {
        foreach ((new ReflectionClass($this->caseClass()))->getMethods() as $method) {
            if (str_starts_with($method->getName(), 'test') && $method->getNumberOfRequiredParameters() === 0) {
                yield $method->getName() => ['method' => $method->getName()];
            }
        }
    }

    private function invoke(string $method): void
    {
        $reflection = new ReflectionMethod($this->case, $method);
        $reflection->setAccessible(true);
        $reflection->invoke($this->case);
    }
}
