<?php

/**
 * SPIKE — eager URL compiler / route() assembly cost (measure BEFORE building).
 *
 * The proposal claimed route('api.posts.show', $post) re-runs Symfony's RouteCompiler
 * 1,500×/payload. It does NOT: Route::$compiled is cached (Route.php:374) and persisted
 * through route:cache (prepareForSerialization → compileRoute, Route.php:1481/1495), so at
 * request time the Symfony compile never runs. The only per-call cost is the string/regex
 * assembly in RouteUrlGenerator::to() — formatParameters, replaceRouteParameters, a
 * preg_match_all missing-param check, rawurlencode+strtr, preg_replace for the relative case.
 *
 * This spike answers the only question that gates a tier: how big is that residual assembly,
 * and what's the ceiling if we replace it with an eager [segments[], params[]] positional
 * concat? We warm the loop first so $route->compiled is populated — the post-route:cache
 * steady state — then:
 *   1. A/B time vanilla route() vs a prototype eager fast path (relative, no-domain, simple).
 *   2. Parity-gate the fast path === vanilla output for every shape (time only matched shapes).
 *   3. (optional) Excimer self-time of the vanilla loop, to size assembly vs a real response.
 *
 *   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing \
 *       benchmarks/url_route_ab.php [calls] [--excimer]
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;

$calls = (int) ($argv[1] ?? 2_000_000);
$excimer = in_array('--excimer', $argv, true);

// --- Realistic API-resource route shapes: what $this->collection dumps per row ----------
// name => [uri, params to pass]  (scalars: a model param is getRouteKey()'d to the same).
$shapes = [
    'api.posts.show'          => ['api/posts/{post}', ['post' => 4217]],
    'api.posts.comments.show' => ['api/posts/{post}/comments/{comment}', ['post' => 4217, 'comment' => 88301]],
    'api.users.show'          => ['api/users/{user}', ['user' => 'jane-doe']],
];

$routes = new RouteCollection;
foreach ($shapes as $name => [$uri, $params]) {
    $route = (new Route(['GET'], $uri, ['as' => $name, fn () => '']));
    $routes->add($route);   // add() populates the name lookup via addLookups()
}

$request = Request::create('http://localhost/', 'GET');
$url = new UrlGenerator($routes, $request);

// --- Prototype eager index: [segments[], params[]] per name (what grease:route-cache writes) ---
// Split 'api/posts/{post}/comments/{comment}' into static segments around {tokens}.
function eagerIndex(array $shapes): array
{
    $index = [];
    foreach ($shapes as $name => [$uri]) {
        $segments = preg_split('/\{[^}]+\}/', $uri);   // static text between params
        preg_match_all('/\{([^}]+)\}/', $uri, $m);
        $index[$name] = ['segments' => $segments, 'params' => $m[1]];
    }

    return $index;
}

// The greased fast path: positional concat for the relative / no-domain / no-extra-params case.
// Mirrors vanilla's relative result: '/'.ltrim(rawurlencode-with-dontEncode(path), '/').
$dontEncode = [
    '%2F' => '/', '%40' => '@', '%3A' => ':', '%3B' => ';', '%2C' => ',',
    '%3D' => '=', '%2B' => '+', '%21' => '!', '%2A' => '*', '%7C' => '|',
    '%3F' => '?', '%26' => '&', '%23' => '#', '%25' => '%',
];

function greasedRoute(string $name, array $params, array $index, array $dontEncode): string
{
    ['segments' => $segments, 'params' => $names] = $index[$name];
    $path = $segments[0];
    foreach ($names as $i => $p) {
        $path .= $params[$p].$segments[$i + 1];
    }
    // Vanilla: format() joins root.'/'.path, then relative strips root → '/'.ltrim(path,'/').
    // rawurlencode the assembled path then restore dontEncode chars, exactly as to().
    $encoded = strtr(rawurlencode($path), $dontEncode);

    return '/'.ltrim($encoded, '/');
}

$index = eagerIndex($shapes);

// --- Parity gate: greased fast path === vanilla route(), every shape -------------------------
$names = array_keys($shapes);
foreach ($shapes as $name => [$uri, $params]) {
    $vanilla = $url->route($name, $params, false);   // absolute=false → relative, the API-resource norm
    $greased = greasedRoute($name, $params, $index, $dontEncode);
    if ($vanilla !== $greased) {
        echo "PARITY FAILED — $name:\n  vanilla: $vanilla\n  greased: $greased\n";
        exit(1);
    }
}
echo 'Parity: OK ('.count($shapes)." shapes — greased fast path === vanilla route())\n";
echo "Sample: ".$url->route('api.posts.comments.show', $shapes['api.posts.comments.show'][1], false)."\n";

// Warm so Route::$compiled is populated (post-route:cache steady state) + JIT warms.
for ($i = 0; $i < 50_000; $i++) {
    $n = $names[$i % count($names)];
    $url->route($n, $shapes[$n][1], false);
    greasedRoute($n, $shapes[$n][1], $index, $dontEncode);
}

if ($excimer) {
    if (! extension_loaded('excimer')) {
        fwrite(STDERR, "excimer not loaded\n");
        exit(1);
    }
    $profiler = new ExcimerProfiler;
    $profiler->setPeriod(0.0001);
    $profiler->setEventType(EXCIMER_REAL);
    $profiler->start();
    for ($i = 0; $i < $calls; $i++) {
        $n = $names[$i % count($names)];
        $url->route($n, $shapes[$n][1], false);
    }
    $profiler->stop();
    $log = $profiler->getLog();
    $agg = $log->aggregateByFunction();
    uasort($agg, static fn ($a, $b) => $b['self'] - $a['self']);
    $total = array_sum(array_map(static fn ($e) => $e['self'], $agg)) ?: 1;
    echo "\nExcimer self-time of vanilla route() (".count($log)." samples):\n";
    printf("%-7s %-7s  %s\n", 'self%', 'incl%', 'function');
    $k = 0;
    foreach ($agg as $fn => $e) {
        if ($k++ >= 18) break;
        printf("%6.2f%% %6.2f%%  %s\n", $e['self'] / $total * 100, $e['inclusive'] / $total * 100, $fn);
    }
    exit(0);
}

// --- A/B timing -----------------------------------------------------------------------------
$c = count($names);

$start = hrtime(true);
for ($i = 0; $i < $calls; $i++) {
    $n = $names[$i % $c];
    $url->route($n, $shapes[$n][1], false);
}
$vanillaSec = (hrtime(true) - $start) / 1e9;

$start = hrtime(true);
for ($i = 0; $i < $calls; $i++) {
    $n = $names[$i % $c];
    greasedRoute($n, $shapes[$n][1], $index, $dontEncode);
}
$greasedSec = (hrtime(true) - $start) / 1e9;

$vanNs = $vanillaSec / $calls * 1e9;
$greNs = $greasedSec / $calls * 1e9;
$delta = ($greasedSec - $vanillaSec) / $vanillaSec * 100;

echo str_repeat('-', 64)."\n";
printf("%-12s %12s %14s\n", '', 'total (s)', 'per call (ns)');
printf("%-12s %12.4f %14.1f\n", 'vanilla', $vanillaSec, $vanNs);
printf("%-12s %12.4f %14.1f\n", 'greased',  $greasedSec, $greNs);
echo str_repeat('-', 64)."\n";
printf("delta: %+.1f%%   (saves %.0f ns/call)\n", $delta, $vanNs - $greNs);
printf("\nContext: a 500-row API resource with 3 links/row = 1,500 route() calls →\n");
printf("  vanilla %.3f ms  vs  greased %.3f ms   (%.3f ms saved/response)\n",
    $vanNs * 1500 / 1e6, $greNs * 1500 / 1e6, ($vanNs - $greNs) * 1500 / 1e6);
