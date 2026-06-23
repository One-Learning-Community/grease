<?php

namespace Grease\Tests;

use Grease\View\Factory;
use Illuminate\View\Factory as BaseFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * {@see Factory} overrides `stopPush()`/`stopPrepend()` from `ManagesStacks`, dropping the
 * per-call `tap(array_pop(...), Closure)` for an inlined pop + extend. The contract is
 * byte-identical stack behaviour: for any `@push`/`@prepend`/`@pushOnce` sequence the
 * greased factory must leave the exact same `$pushes`/`$prepends` state, return the same
 * popped section name, yield the same assembled content, and throw the same exception on an
 * unbalanced stop. This drives a stock and a greased factory through the same call sequence
 * the compiled stack directives emit and compares every observation.
 *
 * The stack methods only touch `pushes`/`prepends`/`pushStack`/`renderCount` (and output
 * buffering), so the factories are built without the constructor and driven directly.
 */
class FactoryStacksParityTest extends TestCase
{
    public function test_single_push_matches_vanilla(): void
    {
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            $f->startPush('scripts');
            echo '<script>a</script>';
            $rets[] = $f->stopPush();

            return $this->snapshot($f, ['scripts'], $rets);
        });
    }

    public function test_repeated_pushes_to_same_section_concatenate(): void
    {
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            foreach (['A', 'B', 'C'] as $c) {
                $f->startPush('scripts');
                echo $c;
                $rets[] = $f->stopPush();
            }

            return $this->snapshot($f, ['scripts'], $rets);
        });
    }

    public function test_prepend_reverses_in_yield(): void
    {
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            foreach (['first', 'second', 'third'] as $c) {
                $f->startPrepend('head');
                echo $c;
                $rets[] = $f->stopPrepend();
            }

            return $this->snapshot($f, ['head'], $rets);
        });
    }

    public function test_push_and_prepend_into_same_section(): void
    {
        // yieldPushContent emits reversed prepends, then pushes — exercise both.
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            $f->startPush('head');
            echo 'P1';
            $rets[] = $f->stopPush();
            $f->startPrepend('head');
            echo 'R1';
            $rets[] = $f->stopPrepend();
            $f->startPush('head');
            echo 'P2';
            $rets[] = $f->stopPush();
            $f->startPrepend('head');
            echo 'R2';
            $rets[] = $f->stopPrepend();

            return $this->snapshot($f, ['head'], $rets);
        });
    }

    public function test_pushes_keyed_by_render_count_implode_in_order(): void
    {
        // A stack pushed to across several view renders: extendPush keys by renderCount,
        // and yieldPushContent implodes the buckets in insertion order.
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            foreach ([0, 1, 2] as $rc) {
                $this->setRenderCount($f, $rc);
                $f->startPush('scripts');
                echo "render-$rc;";
                $rets[] = $f->stopPush();
            }

            return $this->snapshot($f, ['scripts'], $rets);
        });
    }

    public function test_start_push_with_inline_content_skips_buffer(): void
    {
        // startPush($section, $content) with non-empty content extends directly (no ob_start),
        // so it never reaches stop* — confirm the greased factory leaves it identical.
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $f->startPush('scripts', '<inline>');
            $f->startPrepend('head', '<pre>');

            return $this->snapshot($f, ['scripts', 'head'], []);
        });
    }

    public function test_nested_pushes_pop_in_lifo_order(): void
    {
        // Two open push frames at once: the inner stop must pop the inner section first.
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            $f->startPush('outer');
            echo 'O-pre;';
            $f->startPush('inner');
            echo 'I;';
            $rets[] = $f->stopPush();   // pops 'inner'
            echo 'O-post;';
            $rets[] = $f->stopPush();   // pops 'outer'

            return $this->snapshot($f, ['outer', 'inner'], $rets);
        });
    }

    public function test_push_once_renders_a_section_a_single_time(): void
    {
        // @pushOnce compiles to hasRenderedOnce/markAsRenderedOnce around startPush.
        $this->assertScenarioMatches(function (BaseFactory $f) {
            $rets = [];
            foreach ([0, 1] as $_) {
                if (! $f->hasRenderedOnce('asset-x')) {
                    $f->markAsRenderedOnce('asset-x');
                    $f->startPush('head');
                    echo 'X';
                    $rets[] = $f->stopPush();
                }
            }

            return $this->snapshot($f, ['head'], $rets);
        });
    }

    public function test_stop_push_without_start_throws_identically(): void
    {
        $this->assertThrowsIdentically(
            fn (BaseFactory $f) => $f->stopPush(),
            'Cannot end a push stack without first starting one.'
        );
    }

    public function test_stop_prepend_without_start_throws_identically(): void
    {
        $this->assertThrowsIdentically(
            fn (BaseFactory $f) => $f->stopPrepend(),
            'Cannot end a prepend operation without first starting one.'
        );
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

    /** Assert both factories throw the same exception class + message for $call. */
    private function assertThrowsIdentically(callable $call, string $message): void
    {
        foreach ([BaseFactory::class, Factory::class] as $class) {
            $f = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            try {
                $call($f);
                $this->fail("$class did not throw");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    /**
     * Capture the comparable state after a scenario: returned section names, the raw
     * pushes/prepends buckets, and the assembled yield for each section touched.
     *
     * @param  array<int, string>  $sections
     * @param  array<int, mixed>  $rets
     */
    private function snapshot(BaseFactory $f, array $sections, array $rets): array
    {
        $yields = [];
        foreach ($sections as $s) {
            $yields[$s] = $f->yieldPushContent($s);
        }

        return [
            'rets' => $rets,
            'pushes' => $this->prop($f, 'pushes'),
            'prepends' => $this->prop($f, 'prepends'),
            'pushStack' => $this->prop($f, 'pushStack'),
            'yields' => $yields,
        ];
    }

    private function prop(BaseFactory $f, string $name): mixed
    {
        return (new ReflectionClass(BaseFactory::class))->getProperty($name)->getValue($f);
    }

    private function setRenderCount(BaseFactory $f, int $value): void
    {
        (new ReflectionClass(BaseFactory::class))->getProperty('renderCount')->setValue($f, $value);
    }
}
