<?php

namespace Grease\Bench;

use Grease\Bench\Support\DrivesTestSuite;
use Grease\Tests\SqlRoundtripTest;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;

/**
 * Times the real SQL roundtrip test suite end-to-end through phpbench — migrate,
 * seed, query, write — driven against a booted Testbench app. Each subject is one
 * test method, so a regression in any covered path shows up as a timing shift.
 */
#[
    BeforeMethods('setUp'),
    Revs(10),
    Iterations(5),
    RetryThreshold(3),
]
class SuiteBench extends DrivesTestSuite
{
    protected function caseClass(): string
    {
        return SqlRoundtripTest::class;
    }

    #[ParamProviders('provideTestMethods')]
    public function benchTest(array $params): void
    {
        $this->runTest($params);
    }
}
