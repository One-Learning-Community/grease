<?php

/**
 * Endpoint macro — cost of route() in an API-Resource response, and where the greased URL tier
 * lands once the model tiers are already in play. Runs the SHIPPING {@see Grease\Routing\
 * UrlGenerator}, not a prototype, so this bench times exactly what UrlGeneratorParityTest proves
 * byte-identical.
 *
 * Models a real "$this->collection" JSON payload: N seeded rows (eager-loaded), each serialized
 * like an API Resource row — the model's toArray() (already greased) PLUS the ABSOLUTE route()
 * links a resource emits per row (route() defaults to absolute — the real-world shape).
 *
 * Arms (interleaved, order-flipped per round so drift cancels):
 *   vanilla       PlainUser->toArray() + vanilla UrlGenerator
 *   +url          PlainUser->toArray() + greased route() (eager index seeded — the cache pass)
 *   models only   GreasedUser->toArray() + vanilla UrlGenerator
 *   full          GreasedUser->toArray() + greased route()
 *
 * Parity-gated: greased JSON === vanilla JSON, byte-for-byte, before timing. The URL tier is a
 * thin slice of a vanilla response but a large slice of an already-model-greased one (the fixed
 * assembly cost vs a shrunken baseline) — a compounding tier. The cold-vs-precached gap is noise
 * per response (the lazy index self-warms on first call; sub-ms FPM build that scales with route
 * count) — see benchmarks/url_route_ab.php and the route-count sweep.
 *
 *   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
 *       -d xdebug.mode=off benchmarks/url_realworld.php [rounds] [rows] [extraRoutes]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Routing\UrlGenerator as GreasedUrlGenerator;
use Grease\Tests\Fixtures\Pipeline\GreasedUser;
use Grease\Tests\Fixtures\Pipeline\PlainUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;

$rounds = (int) ($argv[1] ?? 60);
$rows = (int) ($argv[2] ?? 500);
$extraRoutes = (int) ($argv[3] ?? 0);   // pad the RouteCollection to a realistic app size

// --- Boot DB + schema + seed --------------------------------------------------------------
$capsule = BootsEloquent::capsule();
$schema = $capsule->schema();

$schema->create('users', function ($t) {
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
$schema->create('posts', function ($t) {
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

$now = Carbon::parse('2026-06-27 12:00:00');
$userRows = [];
$postRows = [];
for ($i = 1; $i <= $rows; $i++) {
    $userRows[] = [
        'id' => $i, 'name' => "User $i", 'email' => "user$i@example.com", 'age' => 20 + ($i % 50),
        'is_active' => $i % 2, 'score' => sprintf('%.2f', $i + 0.5),
        'settings' => json_encode(['theme' => 'dark', 'n' => $i]),
        'email_verified_at' => $now->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now,
    ];
    for ($p = 0; $p < 2; $p++) {
        $pid = ($i - 1) * 2 + $p + 1;
        $postRows[] = [
            'id' => $pid, 'user_id' => $i, 'title' => "Post $pid", 'body' => 'Lorem ipsum dolor sit amet.',
            'view_count' => $pid * 7, 'is_published' => 1, 'published_at' => $now->toDateTimeString(),
            'meta' => json_encode(['tags' => ['a', 'b']]), 'created_at' => $now, 'updated_at' => $now,
        ];
    }
}
foreach (array_chunk($userRows, 200) as $c) {
    $capsule->getConnection()->table('users')->insert($c);
}
foreach (array_chunk($postRows, 200) as $c) {
    $capsule->getConnection()->table('posts')->insert($c);
}

// --- Routes (what an API Resource links to) -----------------------------------------------
$routes = new RouteCollection;
foreach ([
    'api.users.show' => 'api/users/{user}',
    'api.posts.show' => 'api/posts/{post}',
    'api.posts.comments.index' => 'api/posts/{post}/comments',
] as $name => $uri) {
    $routes->add(new Route(['GET'], $uri, ['as' => $name, fn () => '']));
}
for ($i = 0; $i < $extraRoutes; $i++) {
    $routes->add(new Route(['GET'], "filler/$i/{a}/sub/{b}", ['as' => "filler.$i", fn () => '']));
}
$request = Request::create('http://localhost/', 'GET');

$vanillaUrl = new UrlGenerator($routes, $request);
$greasedUrl = new GreasedUrlGenerator($routes, $request);

// Seed the eager index the way grease:route-cache would (the cache pass).
$index = [];
foreach ($routes as $route) {
    $entry = GreasedUrlGenerator::greaseCompileEntry($route);
    if ($entry !== false) {
        $index[$route->getName()] = $entry;
    }
}
$greasedUrl->useGreaseRouteUrlIndex($index);

// --- The API-Resource row: model toArray() + the ABSOLUTE route() links a resource emits ----
$resourceRow = function ($user, UrlGenerator $url): array {
    $data = $user->toArray();
    $data['links'] = ['self' => $url->route('api.users.show', $user)];
    $data['posts'] = array_map(function ($post) use ($url) {
        $row = $post->toArray();
        $row['links'] = [
            'self' => $url->route('api.posts.show', $post),
            'comments' => $url->route('api.posts.comments.index', $post),
        ];

        return $row;
    }, $user->posts->all());

    return $data;
};

$serializeAll = function (string $model, UrlGenerator $url) use ($resourceRow): array {
    $users = $model::with('posts')->get();
    $out = [];
    foreach ($users as $u) {
        $out[] = $resourceRow($u, $url);
    }

    return $out;
};

// --- Parity gate: greased JSON === vanilla JSON, byte-for-byte -----------------------------
$vanillaJson = json_encode($serializeAll(PlainUser::class, $vanillaUrl));
$greasedJson = json_encode($serializeAll(GreasedUser::class, $greasedUrl));
if ($vanillaJson !== $greasedJson) {
    echo "PARITY FAILED — greased response differs from vanilla.\n";
    echo 'vanilla[0]: '.json_encode($serializeAll(PlainUser::class, $vanillaUrl)[0])."\n";
    echo 'greased[0]: '.json_encode($serializeAll(GreasedUser::class, $greasedUrl)[0])."\n";
    exit(1);
}
$linksPerResponse = $rows * (1 + 2 * 2);
echo 'Parity: OK — greased JSON === vanilla ('.$rows.' rows, '.count($index).' routes indexed, '
    .number_format($linksPerResponse)." absolute route() calls/response)\n";

// --- Timed arms ---------------------------------------------------------------------------
$arms = [
    'vanilla' => fn () => $serializeAll(PlainUser::class, $vanillaUrl),
    '+url' => fn () => $serializeAll(PlainUser::class, $greasedUrl),
    'models only' => fn () => $serializeAll(GreasedUser::class, $vanillaUrl),
    'full (models+url)' => fn () => $serializeAll(GreasedUser::class, $greasedUrl),
];

foreach ($arms as $fn) {                // warm: compiled routes, JIT, query cache, lazy index
    for ($i = 0; $i < 5; $i++) {
        $fn();
    }
}

$labels = array_keys($arms);
$times = array_fill_keys($labels, 0.0);
for ($r = 0; $r < $rounds; $r++) {
    $order = $r % 2 ? array_reverse($labels) : $labels;
    foreach ($order as $label) {
        $start = hrtime(true);
        $arms[$label]();
        $times[$label] += (hrtime(true) - $start) / 1e6;   // ms
    }
}
foreach ($times as $k => $v) {
    $times[$k] = $v / $rounds;
}

$base = $times['vanilla'];
echo str_repeat('-', 66)."\n";
printf("%-20s %12s %10s\n", 'arm', 'ms/response', 'vs vanilla');
foreach ($times as $label => $ms) {
    $delta = $label === 'vanilla' ? '' : sprintf('%+.1f%%', ($ms - $base) / $base * 100);
    printf("%-20s %12.4f %10s\n", $label, $ms, $delta);
}
echo str_repeat('-', 66)."\n";
printf("URL tier on a vanilla response:        %+.1f%%\n", ($times['+url'] - $base) / $base * 100);
printf("URL tier on a model-greased response:  %+.1f%%\n",
    ($times['full (models+url)'] - $times['models only']) / $times['models only'] * 100);
