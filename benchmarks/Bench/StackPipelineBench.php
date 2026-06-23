<?php

namespace Grease\Bench;

use Grease\Tests\Fixtures\Pipeline\PipelineHarness;
use Illuminate\Foundation\Application;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * Regression timing + memory for the cumulative-stack pipeline, one subject per Grease
 * level. Each subject boots its level's app once per process (warmup primes it) and then
 * serves the full eight-route suite (four query shapes × {JSON, Blade}) per revolution —
 * so phpbench's `time` and `mem_peak` track a whole page-load's worth of requests at that
 * level.
 *
 * Shares all fixtures with tests/Pipeline/StackPipelineParityTest and the narrative report
 * benchmarks/stack_pipeline.php via {@see PipelineHarness}.
 *
 *   composer bench -- benchmarks/Bench/StackPipelineBench.php
 */
#[
    Revs(5),
    Iterations(3),
    Warmup(2),
]
class StackPipelineBench
{
    /** @var array<int, Application> */
    private static array $apps = [];

    public function benchL0Vanilla(): void
    {
        $this->serve(0);
    }

    public function benchL1Models(): void
    {
        $this->serve(1);
    }

    public function benchL2Events(): void
    {
        $this->serve(2);
    }

    public function benchL3Blade(): void
    {
        $this->serve(3);
    }

    public function benchL4Container(): void
    {
        $this->serve(4);
    }

    public function benchL5Request(): void
    {
        $this->serve(5);
    }

    private function serve(int $level): void
    {
        $app = self::$apps[$level] ??= PipelineHarness::bootLevel($level);

        foreach (PipelineHarness::ROUTES as $route) {
            PipelineHarness::handle($app, $level, $route);
        }
    }
}
