<?php

/**
 * Grease container blueprint — request-level macro (+ parity gate).
 *
 * The micro-bench (container_build_ab.php) proves a single transient resolve is ~−31%.
 * This sizes what that's worth on a *real request*: a fully-configured Testbench app
 * (the bundled Laravel skeleton — real providers, config, kernel), booted on the vanilla
 * container vs `Grease\Container\Application`, serving a request to a DI controller.
 *
 * Two honest numbers, because the win lands in two places:
 *   - BOOT      one-time per request in classic FPM — provider/service resolution, where
 *               the blueprint's transient builds happen (~20-30 classes).
 *   - DISPATCH  per request, steady-state (and the whole story under Octane) — the
 *               controller's constructor + method dependency resolution.
 *
 * Each arm runs in its own subprocess (two full apps in one process collide on facade /
 * static state, and a clean boot sample needs a fresh process). The parent spawns each
 * arm R times, takes the median, parity-checks the served body, and reports deltas.
 *
 *   php benchmarks/container_realworld.php [dispatch_iters] [rounds]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Container\Application as GreasedApplication;
use Grease\Tests\Fixtures\Container\SpikeController;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application as TestbenchResolver;

/**
 * Testbench resolver that builds the configured app on the greased container instead of
 * vanilla. Mirrors the one-line bootstrap/app.php opt-in.
 */
final class GreasedTestbenchResolver extends TestbenchResolver
{
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
}

// ---- DI-heavy fixtures ----------------------------------------------------------
// A cascading transient graph: resolving HeavyController + its method injection forces
// ~25 build() calls/request (all unbound → all transient → all through the blueprint).
// Bench-only volume — adds no new build-path *shape* (those are covered by
// tests/Container/BlueprintParityTest), so the macro's body parity gate suffices here.

class HLeafA {}
class HLeafB {}
class HLeafC {}
class HSvc1 { public function __construct(public HLeafA $a, public HLeafB $b) {} }
class HSvc2 { public function __construct(public HLeafB $b, public HLeafC $c) {} }
class HSvc3 { public function __construct(public HLeafA $a) {} }
class HSvc4 { public function __construct(public HSvc1 $s1, public HLeafC $c) {} }
class HSvc5 { public function __construct(public HSvc2 $s2, public HSvc3 $s3) {} }

class HeavyController
{
    public function __construct(
        public HSvc1 $a,
        public HSvc2 $b,
        public HSvc3 $c,
        public HSvc4 $d,
        public HSvc5 $e,
    ) {
    }

    public function show(Request $request, HSvc4 $injected): array
    {
        return ['ok' => true, 'q' => $request->query('q')];
    }
}

// ---- Arm: boot one app, time boot + warm dispatch, emit JSON --------------------

if (($arm = $argv[1] ?? null) === '--arm') {
    $resolverClass = $argv[2];
    $iterations = (int) $argv[3];

    $base = TestbenchResolver::applicationBasePath();

    $bootStart = hrtime(true);
    /** @var \Illuminate\Foundation\Application $app */
    $app = $resolverClass::create($base);
    $bootMs = (hrtime(true) - $bootStart) / 1e6;

    $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
    $app['router']->get('/spike', [SpikeController::class, 'show']);   // light: 2 deps
    $app['router']->get('/heavy', [HeavyController::class, 'show']);   // heavy: ~25 builds
    $app['router']->getRoutes()->refreshNameLookups();

    $kernel = $app->make(HttpKernelContract::class);

    $timeRoute = function (string $uri) use ($kernel, $iterations): array {
        $make = fn () => Request::create($uri.'?q=hello', 'GET');
        $sample = $kernel->handle($make()); // warm

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $kernel->handle($make());
        }

        return [
            'us' => (hrtime(true) - $start) / $iterations / 1e3,
            'status' => $sample->getStatusCode(),
            'body' => $sample->getContent(),
        ];
    };

    $light = $timeRoute('/spike');
    $heavy = $timeRoute('/heavy');

    echo json_encode([
        'boot_ms' => $bootMs,
        'light' => $light,
        'heavy' => $heavy,
    ]);
    exit(0);
}

// ---- Orchestrator --------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 2000);
$rounds = (int) ($argv[2] ?? 5);

$self = escapeshellarg(__FILE__);
$php = escapeshellarg(PHP_BINARY);

$runArm = function (string $resolverClass) use ($php, $self, $iterations, $rounds): array {
    $boot = [];
    $light = [];
    $heavy = [];
    $rows = null;

    for ($r = 0; $r < $rounds; $r++) {
        $out = shell_exec("$php $self --arm ".escapeshellarg($resolverClass)." $iterations 2>&1");
        $row = json_decode((string) $out, true);
        if (! is_array($row)) {
            fwrite(STDERR, "ARM CRASHED ($resolverClass):\n$out\n");
            exit(1);
        }
        $boot[] = $row['boot_ms'];
        $light[] = $row['light']['us'];
        $heavy[] = $row['heavy']['us'];
        $rows = $row;
    }

    sort($boot);
    sort($light);
    sort($heavy);
    $median = fn (array $a) => $a[intdiv(count($a), 2)];

    return [
        'boot_ms' => $median($boot),
        'light_us' => $median($light),
        'heavy_us' => $median($heavy),
        'light' => $rows['light'],
        'heavy' => $rows['heavy'],
    ];
};

echo "Booting Testbench skeleton, vanilla vs greased container ($rounds rounds × $iterations dispatches)...\n\n";

$vanilla = $runArm(\Orchestra\Testbench\Foundation\Application::class);
$greased = $runArm(GreasedTestbenchResolver::class);

// ---- Parity gate ---------------------------------------------------------------

foreach (['light', 'heavy'] as $route) {
    if ($vanilla[$route]['status'] !== 200 || $greased[$route]['status'] !== 200) {
        echo "NON-200 ($route) — vanilla: {$vanilla[$route]['status']}, greased: {$greased[$route]['status']}\n";
        exit(1);
    }
    if ($vanilla[$route]['body'] !== $greased[$route]['body']) {
        echo "PARITY FAILED ($route) — served bodies differ:\n  vanilla: {$vanilla[$route]['body']}\n  greased: {$greased[$route]['body']}\n";
        exit(1);
    }
}

echo "Parity: OK (both served bodies byte-identical)\n";
echo "  light: {$greased['light']['body']}\n";
echo "  heavy: {$greased['heavy']['body']}\n\n";

// ---- Report --------------------------------------------------------------------

$delta = fn ($v, $g) => ($g - $v) / $v * 100;

printf("%-32s %12s %12s %8s\n", '', 'vanilla', 'greased', 'delta');
printf("%-32s %10.2f ms %10.2f ms %+7.1f%%\n", 'boot (one-time / FPM req)', $vanilla['boot_ms'], $greased['boot_ms'], $delta($vanilla['boot_ms'], $greased['boot_ms']));
printf("%-32s %10.2f µs %10.2f µs %+7.1f%%\n", 'dispatch light (2 deps)', $vanilla['light_us'], $greased['light_us'], $delta($vanilla['light_us'], $greased['light_us']));
printf("%-32s %10.2f µs %10.2f µs %+7.1f%%\n", 'dispatch heavy (~25 builds)', $vanilla['heavy_us'], $greased['heavy_us'], $delta($vanilla['heavy_us'], $greased['heavy_us']));

echo "\nThe dispatch delta scales with transient-resolution volume per request: a light\n";
echo "controller barely moves, a DI-heavy action approaches the per-resolve win. Boot is\n";
echo "paid once per request in FPM, once per worker under Octane (where dispatch is the\n";
echo "whole per-request story). macOS figures — confirm on Linux/docker per NOTES.\n";
