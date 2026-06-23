<?php

namespace Grease\Tests;

use Grease\View\Factory;
use Illuminate\View\Factory as BaseFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * {@see Factory} overrides the `ManagesLoops` `$loop` bookkeeping for speed
 * (in-place update instead of `array_merge`, direct stack reads instead of `Arr::last`).
 * The contract is byte-identical loop state: for any `@foreach` shape — countable,
 * nested (with a `$loop->parent`), single-element, non-countable — the greased factory
 * must produce the exact same per-iteration `$loop` snapshots and the exact same final
 * stack as the stock factory. This drives both factories through the same call sequence
 * the compiled `@foreach` emits and compares every observation.
 *
 * The loop methods only touch `loopsStack`, so the factories are built without the
 * constructor (no engines/finder/events needed) and driven directly.
 */
class FactoryLoopParityTest extends TestCase
{
    public function test_simple_countable_loop_matches_vanilla(): void
    {
        $this->assertScenarioMatches(fn ($f) => $this->driveOneLevel($f, [10, 20, 30, 40]));
    }

    public function test_single_element_loop_matches_vanilla(): void
    {
        // first and last are both true on the only iteration — a classic edge.
        $this->assertScenarioMatches(fn ($f) => $this->driveOneLevel($f, ['only']));
    }

    public function test_non_countable_loop_matches_vanilla(): void
    {
        // A generator isn't countable: count/remaining/last stay null throughout.
        $gen = static function () {
            yield 'a';
            yield 'b';
            yield 'c';
        };

        $this->assertScenarioMatches(fn ($f) => $this->driveOneLevel($f, $gen()));
    }

    public function test_nested_loops_and_parent_snapshot_match_vanilla(): void
    {
        // Outer loop of 3; on each outer iteration an inner loop of 2 runs. Captures
        // $loop->parent on the inner snapshots — the trickiest byte-for-byte case.
        $this->assertScenarioMatches(function ($f) {
            $observed = [];

            $f->addLoop([1, 2, 3]);
            foreach ([1, 2, 3] as $_) {
                $f->incrementLoopIndices();
                $observed[] = $this->normalize($f->getLastLoop());

                $f->addLoop(['x', 'y']);
                foreach (['x', 'y'] as $__) {
                    $f->incrementLoopIndices();
                    $observed[] = $this->normalize($f->getLastLoop());
                }
                $f->popLoop();
            }
            $f->popLoop();

            return $observed;
        });
    }

    public function test_from_base_carries_state_and_repoints_env(): void
    {
        // A base factory carrying shared state, with __env pointing at itself (as the
        // real constructor leaves it).
        $base = (new ReflectionClass(BaseFactory::class))->newInstanceWithoutConstructor();
        $shared = new ReflectionClass(BaseFactory::class);
        $prop = $shared->getProperty('shared');
        $prop->setValue($base, ['__env' => $base, 'app' => 'the-container']);

        $greased = Factory::fromBase($base);

        // Shared state carries over verbatim...
        $this->assertSame('the-container', $greased->getShared()['app']);
        // ...but __env is repointed at the greased instance, so compiled views'
        // `$__env->incrementLoopIndices()` reaches the greased methods.
        $this->assertSame($greased, $greased->getShared()['__env']);
        $this->assertNotSame($base, $greased->getShared()['__env']);
    }

    /**
     * Run the scenario on a vanilla and a greased factory and assert identical output.
     *
     * @param  callable(BaseFactory): array  $scenario
     */
    private function assertScenarioMatches(callable $scenario): void
    {
        $vanilla = (new ReflectionClass(BaseFactory::class))->newInstanceWithoutConstructor();
        $greased = (new ReflectionClass(Factory::class))->newInstanceWithoutConstructor();

        $this->assertSame($scenario($vanilla), $scenario($greased));
    }

    /** Drive one `@foreach` level, capturing the $loop snapshot at each iteration. */
    private function driveOneLevel(BaseFactory $f, iterable $data): array
    {
        $observed = [];

        $f->addLoop($data);
        foreach ($data as $_) {
            $f->incrementLoopIndices();
            $observed[] = $this->normalize($f->getLastLoop());
        }
        $f->popLoop();

        return $observed;
    }

    /** Flatten a $loop snapshot (incl. its parent object) to a comparable array. */
    private function normalize(?object $loop): ?array
    {
        if ($loop === null) {
            return null;
        }

        $data = (array) $loop;

        if (isset($data['parent']) && is_object($data['parent'])) {
            $data['parent'] = (array) $data['parent'];
        }

        return $data;
    }
}
