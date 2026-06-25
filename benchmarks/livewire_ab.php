<?php

/**
 * Grease × Livewire — initial-render round-trip A/B (+ parity gate).
 *
 * Livewire isn't a traditional API: every interaction re-mounts a component, re-queries its
 * models, re-renders the Blade template, and re-dehydrates the result into a snapshot. That's
 * a stack of exactly the tiers Grease accelerates — hydration, casting, date serialization,
 * `toArray()`, and the Blade compiler — fired on every round-trip rather than once per request.
 *
 * This sizes what the full greased stack buys on a Livewire initial render: a fully-configured
 * Testbench app boots vanilla vs greased (greased container + view + event providers + a greased
 * model), and `Livewire::mount()` is timed — the path that hydrates the model from the DB,
 * renders the component, dehydrates the `toArray()` payload (ISO dates, the loaded relation),
 * generates the checksum, and embeds it. The component is the UserCard fixture, whose state
 * is a serialized model — so the date/`toArray` tiers actually land in the measured work.
 *
 * Each arm runs in its own subprocess (two full apps in one process collide on facade / static
 * state, and a greased-container arm needs a different Application class — same reason as
 * container_realworld.php). The parent spawns each arm R times, takes the median, parity-checks
 * the dehydrated snapshot data byte-for-byte, and reports the delta.
 *
 * The parity gate here is the same contract tests/Livewire/LivewireParityTest asserts in CI;
 * this script proves it again on the boot path and then times it.
 *
 *   php benchmarks/livewire_ab.php [iterations] [rounds]
 *
 * macOS figures — confirm on Linux/docker per NOTES.
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Container\Application as GreasedApplication;
use Grease\Events\GreaseEventServiceProvider;
use Grease\Tests\Livewire\Fixtures\GreasedUserCard;
use Grease\Tests\Livewire\Fixtures\VanillaUserCard;
use Grease\View\GreaseViewServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchResolver;

/** Vanilla container + Livewire only — the oracle arm. */
final class VanillaLivewireResolver extends TestbenchResolver
{
    protected function resolveApplication()
    {
        return (new ApplicationBuilder(new Application($this->getApplicationBasePath())))
            ->withProviders([LivewireServiceProvider::class])
            ->withMiddleware(fn ($middleware) => null)
            ->withCommands()
            ->create();
    }
}

/** Greased container + greased view/event providers + Livewire — the full stack under test. */
final class GreasedLivewireResolver extends TestbenchResolver
{
    protected function resolveApplication()
    {
        return (new ApplicationBuilder(new GreasedApplication($this->getApplicationBasePath())))
            ->withProviders([
                LivewireServiceProvider::class,
                GreaseEventServiceProvider::class,
                GreaseViewServiceProvider::class,
            ])
            ->withMiddleware(fn ($middleware) => null)
            ->withCommands()
            ->create();
    }
}

/** Pull the dehydrated snapshot `data` out of a mounted component's HTML (deterministic). */
function snapshot_data(string $html): string
{
    preg_match('/wire:snapshot="([^"]*)"/', $html, $m);
    $snapshot = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);

    return json_encode($snapshot['data'] ?? null);
}

// ---- Arm: boot one app, schema+seed, warm + time Livewire::mount, emit JSON -----

if (($argv[1] ?? null) === '--arm') {
    $resolverClass = $argv[2];
    $component = $argv[3];
    $iterations = (int) $argv[4];

    /** @var Application $app */
    $app = $resolverClass::create(TestbenchResolver::applicationBasePath());
    $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    Schema::create('users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('email');
        $t->integer('age');
        $t->boolean('is_active');
        $t->decimal('score', 8, 2);
        $t->text('settings');
        $t->dateTime('email_verified_at')->nullable();
        $t->timestamps();
    });
    Schema::create('posts', function (Blueprint $t) {
        $t->increments('id');
        $t->integer('user_id');
        $t->string('title');
        $t->text('body');
        $t->integer('view_count');
        $t->boolean('is_published');
        $t->dateTime('published_at')->nullable();
        $t->text('meta');
        $t->timestamps();
    });

    $now = '2026-01-01 00:00:00';
    $app['db']->connection()->table('users')->insert([
        'id' => 1, 'name' => 'User 1', 'email' => 'user1@example.test', 'age' => 30,
        'is_active' => 1, 'score' => '42.50', 'settings' => '{"theme":"dark"}',
        'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach (range(1, 8) as $p) {
        $app['db']->connection()->table('posts')->insert([
            'user_id' => 1, 'title' => "Post $p", 'body' => 'lorem', 'view_count' => $p,
            'is_published' => $p % 2, 'published_at' => $now, 'meta' => '{"tags":["a"]}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    $mount = fn () => Livewire::mount($component, ['id' => 1]);

    $sample = (string) $mount(); // warm
    for ($i = 0; $i < 30; $i++) {
        $mount();
    }

    gc_collect_cycles();
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $mount();
    }
    $us = (hrtime(true) - $start) / $iterations / 1e3;

    echo json_encode(['us' => $us, 'data' => snapshot_data($sample)]);
    exit(0);
}

// ---- Orchestrator --------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 400);
$rounds = (int) ($argv[2] ?? 5);

$self = escapeshellarg(__FILE__);
$php = escapeshellarg(PHP_BINARY);

$runArm = function (string $resolver, string $component) use ($php, $self, $iterations, $rounds): array {
    $us = [];
    $data = null;
    for ($r = 0; $r < $rounds; $r++) {
        $out = shell_exec("$php $self --arm ".escapeshellarg($resolver).' '.escapeshellarg($component)." $iterations 2>&1");
        $row = json_decode((string) $out, true);
        if (! is_array($row)) {
            fwrite(STDERR, "ARM CRASHED ($component):\n$out\n");
            exit(1);
        }
        $us[] = $row['us'];
        $data = $row['data'];
    }
    sort($us);

    return ['us' => $us[intdiv(count($us), 2)], 'data' => $data];
};

echo "Booting Testbench + Livewire, vanilla vs greased ($rounds rounds × $iterations mounts)...\n\n";

$vanilla = $runArm(VanillaLivewireResolver::class, VanillaUserCard::class);
$greased = $runArm(GreasedLivewireResolver::class, GreasedUserCard::class);

// ---- Parity gate: the dehydrated snapshot must be byte-identical ----------------

if ($vanilla['data'] !== $greased['data']) {
    echo "PARITY FAILED — dehydrated snapshot data differs:\n  vanilla: {$vanilla['data']}\n  greased: {$greased['data']}\n";
    exit(1);
}
echo "Parity: OK (dehydrated snapshot data byte-identical)\n\n";

// ---- Report --------------------------------------------------------------------

$delta = ($greased['us'] - $vanilla['us']) / $vanilla['us'] * 100;
printf("%-28s %12s %12s %8s\n", '', 'vanilla', 'greased', 'delta');
printf("%-28s %10.2f µs %10.2f µs %+7.1f%%\n", 'Livewire::mount (render)', $vanilla['us'], $greased['us'], $delta);

echo "\nThe initial mount hydrates the model, renders the component, and dehydrates its\n";
echo "toArray() payload into a checksummed snapshot — the model, view, and serialization\n";
echo "tiers stacked. A subsequent update re-runs the same path, so this delta recurs on\n";
echo "every interaction, not just first paint. macOS figures — confirm on Linux/docker.\n";
