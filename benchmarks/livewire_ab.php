<?php

/**
 * Grease × Livewire — mount + update round-trip A/B (+ parity gate).
 *
 * Livewire isn't a traditional API: every interaction re-hydrates a component, re-renders the
 * Blade template, and re-dehydrates the result into a snapshot — a stack of exactly the tiers
 * Grease accelerates (hydration, casting, date serialization, `toArray()`, the Blade compiler).
 *
 * This sizes — and *attributes* — what Grease buys, across BOTH Livewire paths and TWO component
 * shapes, in a fully-configured Testbench app:
 *   - mount  — `Livewire::mount()`: hydrate from the DB, render, dehydrate to a checksummed snapshot.
 *   - update — `Livewire::update()`: re-feed a mounted snapshot + fire a bump() wire:click.
 * Each is timed across four corners (vanilla, +model trait only, +foundation tiers only, full stack)
 * so the decomposition shows the win is almost entirely the model trait, not the container/view/event
 * tiers (a single component resolve is a thin slice of a request). The two shapes show WHERE it lands:
 *   - ShowUser  — query-active: holds a model property AND lists its posts, so every update RE-QUERIES
 *                 the user + its posts (the model tier re-fires across the whole result set).
 *   - UserCard  — cached: holds a `toArray()` array, so the update rehydrates plain data (tier sits out).
 * The model-tier win scales with the number of `new Model()` a path hydrates, so the headline mount
 * delta recurs on update only in proportion to the models that update re-queries — not as a blanket.
 *
 * Each arm runs in its own subprocess (two full apps in one process collide on facade / static
 * state, and a greased-container arm needs a different Application class — same reason as
 * container_realworld.php). The parent spawns each arm R times, takes the median of each path,
 * parity-checks the mount AND update snapshot data byte-for-byte, and reports both deltas.
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
use Grease\Tests\Fixtures\Pipeline\GreasedUser;
use Grease\Tests\Fixtures\Pipeline\PlainUser;
use Grease\Tests\Livewire\Fixtures\GreasedShowUser;
use Grease\Tests\Livewire\Fixtures\GreasedUserCard;
use Grease\Tests\Livewire\Fixtures\VanillaShowUser;
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
    return json_encode(full_snapshot($html)['data'] ?? null);
}

/** Decode the whole `wire:snapshot` (data + memo + checksum) — used to drive the update path. */
function full_snapshot(string $html): array
{
    preg_match('/wire:snapshot="([^"]*)"/', $html, $m);

    return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true) ?? [];
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

    // Register under a stable alias so the snapshot's `memo.name` round-trips back to the class
    // on update (mounting by bare FQCN generates a name Livewire can't reverse — in production
    // components are always registered/discovered, so this is the realistic path, not a hack).
    Livewire::component('bench', $component);
    $mount = fn () => Livewire::mount('bench', ['id' => 1]);

    // The update path re-feeds the snapshot a mount produced, firing a bump() wire:click each
    // time. A real interaction is server-side independent, so re-using one snapshot is both
    // realistic and deterministic. ShowUser re-queries its model here (model tier re-fires);
    // UserCard rehydrates a cached array (model tier sits out) — the contrast is the finding.
    $sample = (string) $mount();
    $snapshot = full_snapshot($sample);
    $bump = [['method' => 'bump', 'params' => [], 'path' => '']];
    $update = fn () => Livewire::update($snapshot, [], $bump);

    [$updated] = $update(); // one update for the parity gate

    for ($i = 0; $i < 30; $i++) { // warm both paths
        $mount();
        $update();
    }

    gc_collect_cycles();
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $mount();
    }
    $mountUs = (hrtime(true) - $start) / $iterations / 1e3;

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $update();
    }
    $updateUs = (hrtime(true) - $start) / $iterations / 1e3;

    echo json_encode([
        'mount_us' => $mountUs,
        'update_us' => $updateUs,
        'mount_data' => snapshot_data($sample),
        'update_data' => json_encode($updated['data'] ?? null),
    ]);
    exit(0);
}

// ---- Orchestrator --------------------------------------------------------------

$iterations = (int) ($argv[1] ?? 400);
$rounds = (int) ($argv[2] ?? 5);

$self = escapeshellarg(__FILE__);
$php = escapeshellarg(PHP_BINARY);

$runArm = function (string $resolver, string $component) use ($php, $self, $iterations, $rounds): array {
    $mount = [];
    $update = [];
    $mountData = $updateData = null;
    for ($r = 0; $r < $rounds; $r++) {
        $out = shell_exec("$php $self --arm ".escapeshellarg($resolver).' '.escapeshellarg($component)." $iterations 2>&1");
        $row = json_decode((string) $out, true);
        if (! is_array($row)) {
            fwrite(STDERR, "ARM CRASHED ($component):\n$out\n");
            exit(1);
        }
        $mount[] = $row['mount_us'];
        $update[] = $row['update_us'];
        $mountData = $row['mount_data'];
        $updateData = $row['update_data'];
    }
    sort($mount);
    sort($update);
    $mid = intdiv($rounds, 2);

    return ['mount_us' => $mount[$mid], 'update_us' => $update[$mid], 'mount_data' => $mountData, 'update_data' => $updateData];
};

echo "Booting Testbench + Livewire — two shapes × four corners, mount + update\n";
echo "($rounds rounds × $iterations iterations each)...\n\n";

// The resolver controls the foundation tiers (container/view/events); the component class
// controls the model tier (Greased vs Plain) AND the shape. The four corners isolate which
// tier the win comes from; the two shapes isolate where it lands across the round-trip:
//   - ShowUser holds a live model property → the update path re-queries it (model tier re-fires);
//   - UserCard holds a toArray() array → the update rehydrates a cached array (model tier sits out).
$shapes = [
    'query-active (ShowUser — re-queries user + posts on every update)' => [VanillaShowUser::class, GreasedShowUser::class],
    'cached array (UserCard — toArray() snapshot, no re-query on update)' => [VanillaUserCard::class, GreasedUserCard::class],
];

foreach ($shapes as $title => [$vanilla, $greased]) {
    $baseline = $runArm(VanillaLivewireResolver::class, $vanilla);    // nothing greased
    $model = $runArm(VanillaLivewireResolver::class, $greased);       // + model trait only
    $foundation = $runArm(GreasedLivewireResolver::class, $vanilla);  // + container/view/events only
    $full = $runArm(GreasedLivewireResolver::class, $greased);        // everything

    // ---- Parity gate: greased snapshots byte-identical to vanilla, mount AND update ----
    // The contract: a greased model serialized through Livewire must dehydrate exactly like a
    // vanilla one (same ISO dates, decimal string, relation) or the checksum breaks. Both the
    // model-only and full arms carry the greased model, on both the mount and update paths.
    //
    // The model-as-property shape dehydrates to a `{class,key}` reference, so the snapshot
    // carries the model's FQCN — which differs ONLY because the A/B fixtures are two classes
    // (GreasedUser vs PlainUser). In production it's one `User` class greased or not, so that
    // class string is normalized away before comparing (mirrors LivewireParityTest's envelope
    // test). The toArray() shape carries no class reference, so this is a no-op there.
    $normalize = fn (?string $json): string => str_replace(
        [trim(json_encode(GreasedUser::class), '"'), trim(json_encode(PlainUser::class), '"')],
        'App\\\\Models\\\\User',
        (string) $json,
    );

    foreach (['model' => $model, 'full' => $full] as $label => $arm) {
        foreach (['mount_data', 'update_data'] as $field) {
            if ($normalize($baseline[$field]) !== $normalize($arm[$field])) {
                echo "PARITY FAILED ($title / $label / $field) — snapshot differs from vanilla:\n";
                echo "  vanilla: {$baseline[$field]}\n  greased: {$arm[$field]}\n";
                exit(1);
            }
        }
    }

    // ---- Report: each tier's contribution, baseline-relative, mount | update ----
    $mDelta = fn (array $arm) => ($arm['mount_us'] - $baseline['mount_us']) / $baseline['mount_us'] * 100;
    $uDelta = fn (array $arm) => ($arm['update_us'] - $baseline['update_us']) / $baseline['update_us'] * 100;

    echo "$title\n";
    echo "  parity: OK (mount + update snapshots byte-identical to vanilla)\n\n";
    printf("  %-30s %11s %9s %11s %9s\n", '', 'mount', 'vs base', 'update', 'vs base');
    printf("  %-30s %8.2f µs %9s %8.2f µs %9s\n", 'vanilla (baseline)', $baseline['mount_us'], '—', $baseline['update_us'], '—');
    printf("  %-30s %8.2f µs %+8.1f%% %8.2f µs %+8.1f%%\n", '+ model trait only', $model['mount_us'], $mDelta($model), $model['update_us'], $uDelta($model));
    printf("  %-30s %8.2f µs %+8.1f%% %8.2f µs %+8.1f%%\n", '+ container/view/events only', $foundation['mount_us'], $mDelta($foundation), $foundation['update_us'], $uDelta($foundation));
    printf("  %-30s %8.2f µs %+8.1f%% %8.2f µs %+8.1f%%\n", 'full greased stack', $full['mount_us'], $mDelta($full), $full['update_us'], $uDelta($full));
    echo "\n";
}

echo "The model-tier win is a FIXED cost per `new Model()` (reflection, class-attribute\n";
echo "resolution, the initialize* booters), so it scales with how many models a path hydrates —\n";
echo "and the two shapes show that update cost tracks update WORK:\n";
echo "  - ShowUser (query-active): the win recurs on UPDATE because every update re-queries the\n";
echo "    user + its posts — a data table sorting/paginating/filtering behaves the same way.\n";
echo "  - UserCard (cached): ~0 on UPDATE — it rehydrates a cached toArray() array, never\n";
echo "    re-queries, so the model tier sits out (only render + checksum remain). And that's fine.\n";
echo "So the headline does NOT blanket-recur every interaction: an update that does no model work\n";
echo "is free; one that re-queries gets the win, scaled to the query. Confirm on your own stack\n";
echo "(NOTES: macOS distorts; this also reproduces on Linux+JIT via benchmarks/docker).\n";
