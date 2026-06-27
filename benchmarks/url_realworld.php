<?php

/**
 * SPIKE (B) — endpoint-level cost of route() in an API-Resource response, + the
 * precache question: does building the eager URL index via an optimize/cache pass
 * (the grease:route-cache / grease:view-cache machinery) buy anything within a request?
 *
 * Models a real "$this->collection" JSON payload: N seeded rows (eager-loaded), each
 * serialized exactly like an API Resource row — the model's toArray() (already greased)
 * PLUS the route() links a resource emits per row. That puts the URL-generation cost in
 * its true proportion to the rest of the response.
 *
 * Arms (interleaved, order-flipped per round so drift cancels):
 *   vanilla            PlainUser->toArray() + vanilla UrlGenerator
 *   +url (cold)        PlainUser->toArray() + greased route(), index lazily built per request
 *                      (FPM with NO cache pass — index empty at request start, self-warms)
 *   +url (precached)   PlainUser->toArray() + greased route(), index prebuilt once
 *                      (the cache pass / Octane steady state)
 *   full               GreasedUser->toArray() + greased route() (precached)
 *
 * The (cold) vs (precached) gap is the answer to "what's it look like with precaching done
 * via our cache pass". Parity-gated: greased JSON === vanilla JSON, byte-for-byte, per round.
 *
 *   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
 *       -d xdebug.mode=off benchmarks/url_realworld.php [rounds] [rows]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Tests\Fixtures\Pipeline\GreasedUser;
use Grease\Tests\Fixtures\Pipeline\PlainUser;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;

$rounds = (int) ($argv[1] ?? 60);
$rows = (int) ($argv[2] ?? 500);
$extraRoutes = (int) ($argv[3] ?? 0);   // pad the RouteCollection to a realistic app size

// --- Greased UrlGenerator: eager [segments,params] index, byte-identical-or-defer ----------
class GreasedUrlGenerator extends UrlGenerator
{
    private array $greaseIndex = [];

    private const DONT_ENCODE = [
        '%2F' => '/', '%40' => '@', '%3A' => ':', '%3B' => ';', '%2C' => ',',
        '%3D' => '=', '%2B' => '+', '%21' => '!', '%2A' => '*', '%7C' => '|',
        '%3F' => '?', '%26' => '&', '%23' => '#', '%25' => '%',
    ];

    /** The cache pass: walk the routes once, index the simple shapes. */
    public function greaseBuildIndex(): void
    {
        foreach ($this->routes->getRoutesByName() as $name => $route) {
            $uri = $route->uri();
            // Only index simple shapes: no domain, no optional/regex params.
            if ($route->getDomain() !== null) {
                continue;
            }
            if (preg_match('/\{[^}]*[?:]/', $uri) || $route->getOptionalParameterNames()) {
                continue;
            }
            $segments = preg_split('/\{[^}]+\}/', $uri);
            preg_match_all('/\{([^}]+)\}/', $uri, $m);
            $this->greaseIndex[$name] = ['segments' => $segments, 'params' => $m[1]];
        }
    }

    public function greaseFlushIndex(): void
    {
        $this->greaseIndex = [];
    }

    public function greaseIndexed(): int
    {
        return count($this->greaseIndex);
    }

    public function toRoute($route, $parameters, $absolute)
    {
        $name = is_object($route) ? $route->getName() : null;

        if (! $absolute && $name !== null && isset($this->greaseIndex[$name])) {
            $fast = $this->greaseFastToRoute($this->greaseIndex[$name], $parameters);
            if ($fast !== null) {
                return $fast;
            }
        }

        return parent::toRoute($route, $parameters, $absolute);
    }

    /** Returns the relative URL, or null to defer to vanilla for any non-simple case. */
    private function greaseFastToRoute(array $entry, $parameters): ?string
    {
        ['segments' => $segments, 'params' => $names] = $entry;

        // Normalize to positional values in the route's param order.
        $params = is_array($parameters) ? $parameters : [$parameters];
        $values = [];
        $assoc = array_keys($params) !== range(0, count($params) - 1);

        if ($assoc) {
            // Assoc must supply exactly the required names — any extra → query string → defer.
            if (count($params) !== count($names)) {
                return null;
            }
            foreach ($names as $p) {
                if (! array_key_exists($p, $params)) {
                    return null;
                }
                $values[] = $params[$p];
            }
        } else {
            // Positional: must match arity exactly (extras → query string → defer).
            if (count($params) !== count($names)) {
                return null;
            }
            $values = $params;
        }

        $path = $segments[0];
        foreach ($values as $i => $v) {
            if ($v instanceof UrlRoutable) {
                $v = $v->getRouteKey();
            }
            // Scalars only; null/bool/array → defer (vanilla has distinct semantics).
            if (! is_string($v) && ! is_int($v)) {
                return null;
            }
            $path .= $v.$segments[$i + 1];
        }

        // Mirror vanilla's relative tail exactly.
        return '/'.ltrim(strtr(rawurlencode($path), self::DONT_ENCODE), '/');
    }
}

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
// Pad to a realistic app size so the lazy index build walks a real route count.
for ($i = 0; $i < $extraRoutes; $i++) {
    $routes->add(new Route(['GET'], "filler/$i/{a}/sub/{b}", ['as' => "filler.$i", fn () => '']));
}
$request = Request::create('http://localhost/', 'GET');

$vanillaUrl = new UrlGenerator($routes, $request);
$greasedUrl = new GreasedUrlGenerator($routes, $request);

// --- The API-Resource row: model toArray() + the route() links a resource emits ------------
$resourceRow = function ($user, UrlGenerator $url): array {
    $data = $user->toArray();
    $data['links'] = ['self' => $url->route('api.users.show', $user, false)];
    $data['posts'] = array_map(function ($post) use ($url) {
        $row = $post->toArray();
        $row['links'] = [
            'self' => $url->route('api.posts.show', $post, false),
            'comments' => $url->route('api.posts.comments.index', $post, false),
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
$greasedUrl->greaseBuildIndex();
$vanillaJson = json_encode($serializeAll(PlainUser::class, $vanillaUrl));
$greasedJson = json_encode($serializeAll(GreasedUser::class, $greasedUrl));
if ($vanillaJson !== $greasedJson) {
    echo "PARITY FAILED — greased response differs from vanilla.\n";
    // Surface first diff.
    $a = $serializeAll(PlainUser::class, $vanillaUrl)[0];
    $b = $serializeAll(GreasedUser::class, $greasedUrl)[0];
    echo "vanilla[0]: ".json_encode($a)."\n";
    echo "greased[0]: ".json_encode($b)."\n";
    exit(1);
}
$linksPerResponse = $rows * (1 + 2 * 2);   // user self + 2 posts × (show+comments)
echo "Parity: OK — greased JSON === vanilla ($rows rows, {$greasedUrl->greaseIndexed()} routes indexed, ".number_format($linksPerResponse)." route() calls/response)\n";

// --- Timed arms ---------------------------------------------------------------------------
function timeIt(callable $fn, int $rounds): float
{
    $start = hrtime(true);
    for ($r = 0; $r < $rounds; $r++) {
        $fn();
    }

    return (hrtime(true) - $start) / 1e9 / $rounds * 1e3;   // ms/response
}

$arms = [
    'vanilla' => fn () => $serializeAll(PlainUser::class, $vanillaUrl),
    '+url (cold)' => function () use ($serializeAll, $greasedUrl) {
        $greasedUrl->greaseFlushIndex();
        $greasedUrl->greaseBuildIndex();   // the per-request lazy build (no cache pass)
        return $serializeAll(PlainUser::class, $greasedUrl);
    },
    '+url (precached)' => fn () => $serializeAll(PlainUser::class, $greasedUrl),
    'models only' => fn () => $serializeAll(GreasedUser::class, $vanillaUrl),
    'full (models+url)' => fn () => $serializeAll(GreasedUser::class, $greasedUrl),
];

// Warm every arm (compiled routes, JIT, query cache).
$greasedUrl->greaseBuildIndex();
foreach ($arms as $fn) {
    for ($i = 0; $i < 5; $i++) {
        $fn();
    }
}
$greasedUrl->greaseBuildIndex();

// Interleave + flip order per round so drift cancels.
$labels = array_keys($arms);
$times = array_fill_keys($labels, 0.0);
for ($r = 0; $r < $rounds; $r++) {
    $order = $r % 2 ? array_reverse($labels) : $labels;
    foreach ($order as $label) {
        $times[$label] += timeIt($arms[$label], 1);
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
$coldVsPre = ($times['+url (cold)'] - $times['+url (precached)']) / $times['+url (precached)'] * 100;
printf("precache effect (cold→precached): %+.1f%% per response (%.4f ms)\n",
    -$coldVsPre, $times['+url (cold)'] - $times['+url (precached)']);
printf("URL tier alone (vanilla→+url precached): %+.1f%%\n", ($times['+url (precached)'] - $base) / $base * 100);
