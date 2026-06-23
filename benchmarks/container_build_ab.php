<?php

/**
 * Grease container blueprint — tier-isolated A/B + parity gate.
 *
 * Vanilla `Illuminate\Container\Container` vs `Grease\Container\Container` (constructor
 * blueprint). Asserts behaviour-identical resolution across every shape the fast path
 * touches — no/simple/nested deps, primitive defaults, contextual bindings, contextual
 * attributes, variadics, nullables, `$with` overrides, singletons, deferral cases —
 * THEN times the representative transient resolve.
 *
 *   php benchmarks/container_build_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

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
use Illuminate\Container\Container as VanillaContainer;

// Fixtures are shared with tests/Container/BlueprintParityTest.php (Grease convention:
// the bench times exactly what a parity test proves identical).

// ---- Parity matrix --------------------------------------------------------------

/**
 * Each case: a label + a closure that takes a fresh container and returns a
 * canonical, comparable representation of the resolved result.
 *
 * @return array<string, callable(\Illuminate\Container\Container): mixed>
 */
function cases(): array
{
    return [
        'no-constructor' => fn ($c) => $c->make(NoCtor::class)->v,

        'simple+nested+default' => function ($c) {
            $c->bind(LoggerContract::class, FileLogger::class);
            $o = $c->make(Ctrl::class);

            return [
                get_class($o->a), $o->logger->kind, get_class($o->c),
                get_class($o->c->a), $o->n,
            ];
        },

        'nullable-unbound' => fn ($c) => $c->make(NullableDep::class)->a,

        'singleton-identity' => function ($c) {
            $c->singleton(Dep1::class);

            return $c->make(Dep1::class) === $c->make(Dep1::class);
        },

        'contextual-binding' => function ($c) {
            $c->when(Ctrl::class)->needs(LoggerContract::class)->give(FileLogger::class);
            $c->when(NeedsPrimitive::class)->needs('$size')->give(99);

            return [get_class($c->make(Ctrl::class)->logger), $c->make(NeedsPrimitive::class)->size];
        },

        'with-override' => function ($c) {
            $c->bind(LoggerContract::class, FileLogger::class);

            return $c->make(Ctrl::class, ['n' => 777])->n;
        },

        'contextual-attribute' => fn ($c) => $c->make(AttrConsumer::class)->name,

        // NB: an *unbound* variadic class dep (`Dep1 ...$items`) is unsupported in
        // vanilla Laravel — it array_merges a single object and fatals. Only the
        // contextual (array-giving) form is a valid operation, so that's all we test.
        'variadic-contextual' => function ($c) {
            $c->when(Collector::class)->needs(Dep1::class)->give(fn () => [new Dep1, new Dep1, new Dep1]);

            return count($c->make(Collector::class)->items);
        },

        'rebound-changes-result' => function ($c) {
            $c->bind(LoggerContract::class, FileLogger::class);
            $first = get_class($c->make(Ctrl::class)->logger);
            // Rebind to an anonymous impl; the blueprint must NOT cache the resolved dep.
            $c->bind(LoggerContract::class, fn () => new class implements LoggerContract
            {
                public string $kind = 'closure';
            });

            return [$first, $c->make(Ctrl::class)->logger->kind];
        },

        'unresolvable-primitive-throws' => function ($c) {
            try {
                $c->make(NeedsPrimitive::class);

                return 'NO-THROW';
            } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
                return 'threw';
            }
        },

        'missing-class-throws' => function ($c) {
            try {
                $c->make('App\\Nope\\DoesNotExist');

                return 'NO-THROW';
            } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
                return 'threw';
            }
        },

        'not-instantiable-throws' => function ($c) {
            try {
                $c->make(LoggerContract::class); // unbound interface

                return 'NO-THROW';
            } catch (\Illuminate\Contracts\Container\BindingResolutionException $e) {
                return 'threw';
            }
        },
    ];
}

// ---- Parity gate ----------------------------------------------------------------

$failures = [];

foreach (cases() as $label => $case) {
    $van = $case(new VanillaContainer());
    $gre = $case(new GreasedContainer());

    $vanS = var_export($van, true);
    $greS = var_export($gre, true);

    if ($vanS !== $greS) {
        $failures[] = "  [$label]\n    vanilla: $vanS\n    greased: $greS";
    }
}

if ($failures !== []) {
    echo "PARITY FAILED:\n".implode("\n", $failures)."\n";
    exit(1);
}

echo "Parity: OK (".count(cases())." cases)\n";

// ---- Benchmark ------------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 200_000);

function buildContainer(string $class): VanillaContainer
{
    /** @var VanillaContainer $c */
    $c = new $class();
    $c->bind(LoggerContract::class, FileLogger::class);

    return $c;
}

// Warm the blueprint + a vanilla pass (autoload, opcache parity).
$warm = buildContainer(GreasedContainer::class);
for ($i = 0; $i < 1000; $i++) {
    $warm->make(Ctrl::class);
}

function timeResolves(string $class, int $iterations): float
{
    $c = buildContainer($class);
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $c->make(Ctrl::class);
    }

    return (hrtime(true) - $start) / 1e9;
}

// Interleave rounds to cancel drift; report the mean.
$rounds = 5;
$vanTotal = 0.0;
$greTotal = 0.0;

for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $vanTotal += timeResolves(VanillaContainer::class, $iterations);
        $greTotal += timeResolves(GreasedContainer::class, $iterations);
    } else {
        $greTotal += timeResolves(GreasedContainer::class, $iterations);
        $vanTotal += timeResolves(VanillaContainer::class, $iterations);
    }
}

$van = $vanTotal / $rounds;
$gre = $greTotal / $rounds;
$delta = ($gre - $van) / $van * 100;

printf("\nResolve Ctrl (4 deps incl. 1 nested + default), %s iters × %d rounds:\n", number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/op)\n", $van, $van / $iterations * 1e6);
printf("  greased: %.4f s  (%.3f µs/op)\n", $gre, $gre / $iterations * 1e6);
printf("  delta:   %+.1f%%\n", $delta);
