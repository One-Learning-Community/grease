<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Grease\Http\Request as GreasedRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as VanillaRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application as TestbenchResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared core for the cumulative-stack pipeline benchmark — schema/seed, the eight routes
 * (four realworld query shapes × {JSON, Blade}), per-level boot, and a parity probe.
 *
 * One source of truth, consumed three ways:
 *   - benchmarks/stack_pipeline.php — the narrative cumulative-delta + memory report.
 *   - tests/Pipeline/StackPipelineParityTest — the CI-guarded byte-identity contract.
 *   - benchmarks/Bench/StackPipelineBench — phpbench regression timing + memory.
 *
 * Each level boots in its own process (one app boot per level — the container level needs
 * a different Application class), so static state on {@see LevelResolver} is safe.
 */
final class PipelineHarness
{
    /** Cumulative opt-in levels, safest → riskiest. */
    public const LEVELS = [
        0 => 'vanilla',
        1 => '+ models',
        2 => '+ events',
        3 => '+ blade',
        4 => '+ container',
        5 => '+ request',
    ];

    /** Four query shapes × two response modes. */
    public const ROUTES = [
        'index_users.json', 'index_users.blade',
        'posts_with_author.json', 'posts_with_author.blade',
        'show_post.json', 'show_post.blade',
        'bulk_update.json', 'bulk_update.blade',
    ];

    /** Routes that mutate the DB — parity-probed inside a rolled-back transaction. */
    public const WRITE_ROUTES = ['bulk_update.json', 'bulk_update.blade'];

    private static bool $viewsWritten = false;

    /**
     * Boot a fully-configured app at the given level: DB, schema + seed, views, routes.
     */
    public static function bootLevel(int $level): Application
    {
        LevelResolver::$level = $level;
        $app = LevelResolver::create(TestbenchResolver::applicationBasePath());

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        // Per-level compiled-view dir: the greased vs vanilla Blade compilers emit
        // different compiled PHP for the same template, so they must NOT share a cache.
        $compiled = self::viewBase()."/compiled_L$level";
        self::freshDir($compiled);
        $app['config']->set('view.compiled', $compiled);

        self::ensureViews();
        $app['view']->getFinder()->prependLocation(self::viewBase().'/views');

        self::migrateAndSeed();
        self::registerRoutes($app['router'], self::models($level));
        $app['router']->getRoutes()->refreshNameLookups();

        return $app;
    }

    /** Dispatch a route through the HTTP kernel using the level-appropriate request class. */
    public static function handle(Application $app, int $level, string $route): Response
    {
        $kernel = $app->make(HttpKernel::class);
        $requestClass = $level >= 5 ? GreasedRequest::class : VanillaRequest::class;

        return $kernel->handle($requestClass::create('/'.$route, 'GET'));
    }

    /**
     * Capture {status, hash} for every route under a frozen clock, with write routes
     * rolled back so the body reflects pristine, deterministic state.
     *
     * @return array<string, array{status: int, hash: string}>
     */
    public static function parityProbe(Application $app, int $level): array
    {
        $db = $app['db']->connection();
        Carbon::setTestNow('2026-01-01 12:00:00');

        $out = [];
        foreach (self::ROUTES as $route) {
            $write = in_array($route, self::WRITE_ROUTES, true);
            if ($write) {
                $db->beginTransaction();
            }
            try {
                $resp = self::handle($app, $level, $route);
                $out[$route] = ['status' => $resp->getStatusCode(), 'hash' => sha1((string) $resp->getContent())];
            } finally {
                if ($write) {
                    $db->rollBack();
                }
            }
        }

        Carbon::setTestNow();

        return $out;
    }

    /** @return array{user: class-string, post: class-string} */
    public static function models(int $level): array
    {
        return $level >= 1
            ? ['user' => GreasedUser::class, 'post' => GreasedPost::class]
            : ['user' => PlainUser::class, 'post' => PlainPost::class];
    }

    private static function registerRoutes($router, array $m): void
    {
        // --- JSON (API) ---
        $router->get('/index_users.json', fn () => response()->json(
            $m['user']::query()->limit(100)->get()->toArray()
        ));
        $router->get('/posts_with_author.json', fn () => response()->json(
            $m['post']::with('user')->limit(100)->get()->toArray()
        ));
        $router->get('/show_post.json', fn () => response()->json(
            optional($m['post']::with('user')->find(50))->toArray()
        ));
        $router->get('/bulk_update.json', fn () => response()->json(
            self::bulkUpdate($m)->toArray()
        ));

        // --- Blade (page render via anonymous components) ---
        $router->get('/index_users.blade', fn () => view('pipeline_users', [
            'rows' => $m['user']::query()->limit(100)->get(),
        ]));
        $router->get('/posts_with_author.blade', fn () => view('pipeline_posts', [
            'rows' => $m['post']::with('user')->limit(100)->get(),
        ]));
        $router->get('/show_post.blade', fn () => view('pipeline_post', [
            'row' => $m['post']::with('user')->find(50),
        ]));
        $router->get('/bulk_update.blade', fn () => view('pipeline_users', [
            'rows' => self::bulkUpdate($m),
        ]));
    }

    /** Load 150 users, bump score, save — the write workload. */
    private static function bulkUpdate(array $m)
    {
        return $m['user']::query()->limit(150)->get()->each(function ($u) {
            $u->score = $u->score + 1;
            $u->save();
        });
    }

    private static function migrateAndSeed(): void
    {
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
        $db = app('db')->connection();

        $users = [];
        for ($u = 1; $u <= 300; $u++) {
            $users[] = ['name' => "User $u", 'email' => "user$u@example.test", 'age' => 18 + ($u % 60), 'is_active' => $u % 2, 'score' => number_format(($u % 100) + 0.5, 2, '.', ''), 'settings' => '{"theme":"dark"}', 'email_verified_at' => $u % 3 ? $now : null, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($users, 500) as $c) {
            $db->table('users')->insert($c);
        }

        $posts = [];
        $pid = 0;
        for ($u = 1; $u <= 300; $u++) {
            for ($p = 0; $p < 8; $p++) {
                $pid++;
                $posts[] = ['user_id' => $u, 'title' => "Post $pid", 'body' => str_repeat('lorem ', 12), 'view_count' => $pid, 'is_published' => $pid % 2, 'published_at' => $pid % 2 ? $now : null, 'meta' => '{"tags":["a"]}', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($posts, 500) as $c) {
            $db->table('posts')->insert($c);
        }
    }

    private static function viewBase(): string
    {
        return sys_get_temp_dir().'/grease_pipeline';
    }

    /**
     * Write the Blade templates once. Each row renders through an anonymous component with
     * `@props` + an `$attributes->merge()` — the two paths the greased view tier targets.
     */
    private static function ensureViews(): void
    {
        if (self::$viewsWritten) {
            return;
        }

        $views = self::viewBase().'/views';
        @mkdir($views.'/components', 0777, true);

        // Anonymous component: @props emit + attribute-bag merge, per row.
        file_put_contents($views.'/components/row.blade.php',
            "@props(['a', 'b', 'c', 'd'])\n".
            "<div {{ \$attributes->merge(['class' => 'row']) }}>{{ \$a }}|{{ \$b }}|{{ \$c }}|{{ \$d }}</div>\n"
        );

        file_put_contents($views.'/pipeline_users.blade.php',
            "@foreach (\$rows as \$r)<x-row :a=\"\$r->name\" :b=\"\$r->email\" :c=\"\$r->score\" :d=\"\$r->age\" data-id=\"{{ \$r->id }}\" />\n@endforeach\n"
        );
        file_put_contents($views.'/pipeline_posts.blade.php',
            "@foreach (\$rows as \$r)<x-row :a=\"\$r->title\" :b=\"\$r->view_count\" :c=\"\$r->user->name\" :d=\"\$r->is_published\" data-id=\"{{ \$r->id }}\" />\n@endforeach\n"
        );
        file_put_contents($views.'/pipeline_post.blade.php',
            "<x-row :a=\"\$row->title\" :b=\"\$row->view_count\" :c=\"\$row->user->name\" :d=\"\$row->is_published\" data-id=\"{{ \$row->id }}\" />\n"
        );

        self::$viewsWritten = true;
    }

    private static function freshDir(string $dir): void
    {
        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
        } else {
            @mkdir($dir, 0777, true);
        }
    }
}
