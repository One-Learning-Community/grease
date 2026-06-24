<?php

namespace Grease\Tests\Container;

use Grease\Container\Container as GreasedContainer;
use Grease\Tests\Fixtures\Container\AttrConsumer;
use Grease\Tests\Fixtures\Container\Collector;
use Grease\Tests\Fixtures\Container\Ctrl;
use Grease\Tests\Fixtures\Container\Dep1;
use Grease\Tests\Fixtures\Container\FileLogger;
use Grease\Tests\Fixtures\Container\LoggerContract;
use Grease\Tests\Fixtures\Container\NeedsPrimitive;
use Grease\Tests\Fixtures\Container\NoCtor;
use Grease\Tests\Fixtures\Container\NullableDep;
use Grease\Tests\Fixtures\Container\NullLogger;
use Illuminate\Container\Container;
use Illuminate\Container\Container as VanillaContainer;
use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The constructor-blueprint contract: `Grease\Container\Container` must resolve
 * byte-for-byte like vanilla across every build-path shape — no/simple/nested deps,
 * primitive defaults, contextual bindings, contextual attributes, variadics, nullables,
 * `$with` overrides, singletons, rebinding (proving resolved instances are NOT cached),
 * and the unresolvable/throw paths.
 *
 * Oracle = vanilla {@see Container}. Each case runs against a fresh
 * instance of each container and the canonical results must be identical. Shares the
 * fixtures with `benchmarks/container_build_ab.php`, so the bench times exactly what
 * these tests prove identical.
 */
class BlueprintParityTest extends TestCase
{
    /**
     * Each case returns a canonical, comparable representation of a resolution. Keep in
     * lockstep with `benchmarks/container_build_ab.php::cases()`.
     *
     * @return array<string, array{0: callable(Container): mixed}>
     */
    public static function cases(): array
    {
        return [
            'no-constructor' => [fn ($c) => $c->make(NoCtor::class)->v],

            'simple+nested+default' => [function ($c) {
                $c->bind(LoggerContract::class, FileLogger::class);
                $o = $c->make(Ctrl::class);

                return [get_class($o->a), $o->logger->kind, get_class($o->c), get_class($o->c->a), $o->n];
            }],

            'nullable-unbound' => [fn ($c) => $c->make(NullableDep::class)->a],

            'singleton-identity' => [function ($c) {
                $c->singleton(Dep1::class);

                return $c->make(Dep1::class) === $c->make(Dep1::class);
            }],

            'contextual-binding' => [function ($c) {
                $c->when(Ctrl::class)->needs(LoggerContract::class)->give(FileLogger::class);
                $c->when(NeedsPrimitive::class)->needs('$size')->give(99);

                return [get_class($c->make(Ctrl::class)->logger), $c->make(NeedsPrimitive::class)->size];
            }],

            'with-override' => [function ($c) {
                $c->bind(LoggerContract::class, FileLogger::class);

                return $c->make(Ctrl::class, ['n' => 777])->n;
            }],

            'contextual-attribute' => [fn ($c) => $c->make(AttrConsumer::class)->name],

            // An *unbound* variadic class dep is unsupported in vanilla Laravel (it
            // array_merges a single object and fatals), so only the contextual form is valid.
            'variadic-contextual' => [function ($c) {
                $c->when(Collector::class)->needs(Dep1::class)->give(fn () => [new Dep1, new Dep1, new Dep1]);

                return count($c->make(Collector::class)->items);
            }],

            // Rebinding must change the result — proves the blueprint caches the plan,
            // never the resolved dependency.
            'rebound-changes-result' => [function ($c) {
                $c->bind(LoggerContract::class, FileLogger::class);
                $first = get_class($c->make(Ctrl::class)->logger);
                $c->bind(LoggerContract::class, fn () => new class implements LoggerContract
                {
                    public string $kind = 'closure';
                });

                return [$first, $c->make(Ctrl::class)->logger->kind];
            }],

            'unresolvable-primitive-throws' => [fn ($c) => self::catchBinding(fn () => $c->make(NeedsPrimitive::class))],

            'missing-class-throws' => [fn ($c) => self::catchBinding(fn () => $c->make('App\\Nope\\DoesNotExist'))],

            'not-instantiable-throws' => [fn ($c) => self::catchBinding(fn () => $c->make(LoggerContract::class))],
        ];
    }

    #[DataProvider('cases')]
    public function test_resolves_identically_to_vanilla(callable $case): void
    {
        $vanilla = $case(new VanillaContainer);
        $greased = $case(new GreasedContainer);

        $this->assertSame(var_export($vanilla, true), var_export($greased, true));
    }

    /**
     * The blueprint must NOT poison resolution after a rebind within the same container:
     * resolve, rebind a dependency, resolve again — the second result reflects the rebind.
     */
    public function test_rebind_is_reflected_within_a_container(): void
    {
        $c = new GreasedContainer;
        $c->bind(LoggerContract::class, FileLogger::class);

        $this->assertInstanceOf(FileLogger::class, $c->make(Ctrl::class)->logger);

        $c->bind(LoggerContract::class, fn () => new class implements LoggerContract
        {
            public string $kind = 'rebound';
        });

        $this->assertSame('rebound', $c->make(Ctrl::class)->logger->kind);
    }

    /**
     * The plan caches reflection, not resolution — a contextual binding added AFTER the
     * plan is warmed must still be honored, identically to vanilla.
     */
    public function test_contextual_binding_added_after_plan_is_warmed(): void
    {
        $probe = function (VanillaContainer $c) {
            $c->bind(LoggerContract::class, FileLogger::class);
            $before = get_class($c->make(Ctrl::class)->logger);     // warms the plan

            $c->when(Ctrl::class)->needs(LoggerContract::class)->give(NullLogger::class);
            $after = get_class($c->make(Ctrl::class)->logger);      // must reflect the new binding

            return [$before, $after];
        };

        $this->assertSame($probe(new VanillaContainer), $probe(new GreasedContainer));
    }

    /** flush() must drop the blueprint and leave the container resolving cleanly. */
    public function test_flush_clears_blueprint(): void
    {
        $c = new GreasedContainer;
        $c->bind(LoggerContract::class, FileLogger::class);
        $c->make(Ctrl::class); // warm

        $plans = new \ReflectionProperty($c, 'greaseBuildPlans');
        $this->assertNotEmpty($plans->getValue($c));

        $c->flush();
        $this->assertSame([], $plans->getValue($c));

        // Still resolves from cold after a flush.
        $c->bind(LoggerContract::class, NullLogger::class);
        $this->assertSame('null', $c->make(Ctrl::class)->logger->kind);
    }

    /** A diverged plan must not leak between separate container instances. */
    public function test_plan_is_per_container_instance(): void
    {
        $a = new GreasedContainer;
        $a->bind(LoggerContract::class, FileLogger::class);
        $a->make(Ctrl::class); // warms the plan in $a

        $b = new GreasedContainer;
        $b->bind(LoggerContract::class, fn () => new class implements LoggerContract
        {
            public string $kind = 'other';
        });

        $this->assertSame('other', $b->make(Ctrl::class)->logger->kind);
    }

    private static function catchBinding(callable $resolve): string
    {
        try {
            $resolve();

            return 'NO-THROW';
        } catch (BindingResolutionException) {
            return 'threw';
        }
    }
}
