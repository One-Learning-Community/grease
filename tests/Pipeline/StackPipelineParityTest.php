<?php

namespace Grease\Tests\Pipeline;

use Grease\Tests\Fixtures\Pipeline\PipelineHarness;
use PHPUnit\Framework\TestCase;

/**
 * The cumulative-stack contract: a real request through the kernel must produce a
 * byte-identical response at EVERY Grease level — vanilla, +models, +events, +blade,
 * +container, +request — across all four query shapes × {JSON, Blade}. Oracle = vanilla
 * (level 0).
 *
 * Each level needs its own app boot (the container level uses a different Application
 * class), so each is probed in its own subprocess — clean isolation, no facade/static
 * leakage between levels. This is the correctness backstop under benchmarks/stack_pipeline.php.
 */
class StackPipelineParityTest extends TestCase
{
    public function test_every_level_is_byte_identical_to_vanilla(): void
    {
        $hashes = [];
        foreach (array_keys(PipelineHarness::LEVELS) as $level) {
            $hashes[$level] = $this->probe($level);
        }

        $base = $hashes[0];

        foreach (PipelineHarness::ROUTES as $route) {
            $this->assertSame(200, $base[$route]['status'], "vanilla $route was not 200");

            foreach ($hashes as $level => $h) {
                $label = PipelineHarness::LEVELS[$level];
                $this->assertSame(200, $h[$route]['status'], "level [$label] $route was not 200");
                $this->assertSame(
                    $base[$route]['hash'],
                    $h[$route]['hash'],
                    "level [$label] $route diverged from vanilla",
                );
            }
        }
    }

    /**
     * Boot the given level in a subprocess and return its per-route {status, hash}.
     *
     * @return array<string, array{status: int, hash: string}>
     */
    private function probe(int $level): array
    {
        $autoload = __DIR__.'/../../vendor/autoload.php';
        $code = 'require $argv[1];'
            .' $h = \Grease\Tests\Fixtures\Pipeline\PipelineHarness::class;'
            .' echo json_encode($h::parityProbe($h::bootLevel((int) $argv[2]), (int) $argv[2]));';

        $cmd = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code)
            .' '.escapeshellarg($autoload).' '.$level.' 2>&1';

        $out = (string) shell_exec($cmd);
        $data = json_decode($out, true);

        $this->assertIsArray($data, "probe for level $level failed:\n$out");

        return $data;
    }
}
