<?php

namespace Grease\Tests\Routing;

use Grease\Routing\UrlGenerator as GreasedUrlGenerator;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator as VanillaUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The URL-generation contract: {@see GreasedUrlGenerator::route()} must return byte-for-byte
 * what vanilla {@see VanillaUrlGenerator::route()} returns — on the lazy-compile miss, on the
 * cache hit, and from a pre-seeded index — and must defer (never diverge) for every shape the
 * fast path does not cover. Oracle = a vanilla generator over the SAME RouteCollection, so any
 * drift fails the test. Mirrors the shapes `benchmarks/url_route_ab.php` /
 * `benchmarks/url_realworld.php` time.
 */
class UrlGeneratorParityTest extends TestCase
{
    /** name => uri — the route table both generators see. */
    private static array $routes = [
        'posts.show' => 'api/posts/{post}',
        'posts.comments' => 'api/posts/{post}/comments/{comment}',
        'users.show' => 'api/users/{user}',
        'posts.index' => 'api/posts',                       // param-less
        'root' => '/',                                       // root
        'posts.optional' => 'api/posts/{post}/{slug?}',      // optional → defer
        'posts.scoped' => 'api/posts/{post:slug}',           // scoped binding → defer
        'posts.dup' => 'api/dup/{a}/{a}',                    // duplicate param name → defer (vanilla throws)
        'admin.dash' => 'dashboard',                         // for a domain variant below
    ];

    private function routeCollection(): RouteCollection
    {
        $routes = new RouteCollection;
        foreach (self::$routes as $name => $uri) {
            $routes->add(new Route(['GET'], $uri, ['as' => $name, fn () => '']));
        }
        // A domain route → must defer.
        $routes->add((new Route(['GET'], 'panel/{tenant}', ['as' => 'tenant.panel', fn () => '']))->domain('{account}.example.com'));
        // A route with its own defaults → must defer.
        $routes->add((new Route(['GET'], 'reports/{report}', ['as' => 'reports.show', fn () => '']))->defaults('report', 7));

        return $routes;
    }

    private function generators(?Request $request = null): array
    {
        $request ??= Request::create('http://localhost/', 'GET');
        $routes = $this->routeCollection();

        return [
            new VanillaUrlGenerator($routes, $request),
            new GreasedUrlGenerator(clone $routes, $request),
        ];
    }

    public static function urlCases(): array
    {
        $model = new class implements UrlRoutable
        {
            public function getRouteKey()
            {
                return 'jane-doe';
            }

            public function getRouteKeyName()
            {
                return 'slug';
            }

            public function resolveRouteBinding($value, $field = null) {}

            public function resolveChildRouteBinding($childType, $value, $field = null) {}
        };

        return [
            // [name, parameters, absolute]
            'simple absolute' => ['posts.show', ['post' => 4217], true],
            'simple relative' => ['posts.show', ['post' => 4217], false],
            'multi-param absolute' => ['posts.comments', ['post' => 4217, 'comment' => 88301], true],
            'multi-param relative' => ['posts.comments', ['post' => 4217, 'comment' => 88301], false],
            'positional params' => ['posts.comments', [4217, 88301], true],
            'string value' => ['users.show', ['user' => 'jane-doe'], true],
            'UrlRoutable model' => ['users.show', $model, true],
            'param-less absolute' => ['posts.index', [], true],
            'param-less relative' => ['posts.index', [], false],
            'root route' => ['root', [], true],
            // Defer cases — must still match vanilla byte-for-byte.
            'extra params → query' => ['posts.show', ['post' => 1, 'page' => 2], true],
            'extra params relative' => ['posts.show', ['post' => 1, 'q' => 'x'], false],
            'optional present' => ['posts.optional', ['post' => 1, 'slug' => 'hello'], true],
            'optional absent' => ['posts.optional', ['post' => 1], true],
            'scoped binding' => ['posts.scoped', ['post' => 'my-slug'], true],
            'domain route' => ['tenant.panel', ['account' => 'acme', 'tenant' => 5], true],
            'route defaults' => ['reports.show', ['report' => 3], true],
            // Encoding edge cases.
            'value with space' => ['users.show', ['user' => 'jane doe'], true],
            'value with slash' => ['users.show', ['user' => 'a/b'], true],
            'value with at + plus' => ['users.show', ['user' => 'a@b+c'], true],
            'value with unicode' => ['users.show', ['user' => 'café'], true],
            'value with reserved' => ['users.show', ['user' => 'a;b,c=d'], true],
            'integer value' => ['users.show', ['user' => 99], true],
        ];
    }

    #[DataProvider('urlCases')]
    public function test_greased_matches_vanilla(string $name, $parameters, bool $absolute): void
    {
        [$vanilla, $greased] = $this->generators();

        $expected = $vanilla->route($name, $parameters, $absolute);

        // Cold (lazy compile) and warm (cache hit) must both match.
        $this->assertSame($expected, $greased->route($name, $parameters, $absolute), "$name (cold)");
        $this->assertSame($expected, $greased->route($name, $parameters, $absolute), "$name (warm)");
    }

    public function test_missing_required_parameter_throws_like_vanilla(): void
    {
        [$vanilla, $greased] = $this->generators();

        $vanillaThrew = false;
        try {
            $vanilla->route('posts.show', [], true);
        } catch (UrlGenerationException) {
            $vanillaThrew = true;
        }
        $this->assertTrue($vanillaThrew, 'sanity: vanilla throws on missing param');

        $this->expectException(UrlGenerationException::class);
        $greased->route('posts.show', [], true);
    }

    /**
     * Values vanilla treats as a *missing* parameter (an empty string, which it leaves as a
     * literal `{name}`) and malformed duplicate-name routes must throw `UrlGenerationException`
     * just like vanilla — the fast path must NOT silently build a URL. Regression for two
     * byte-identity divergences caught in review.
     */
    public function test_empty_string_and_duplicate_param_throw_like_vanilla(): void
    {
        [$vanilla, $greased] = $this->generators();

        $cases = [
            'empty named value' => ['posts.show', ['post' => '']],
            'empty mid-segment' => ['posts.comments', ['post' => '', 'comment' => 5]],
            'duplicate param positional' => ['posts.dup', [5, 6]],
        ];

        foreach ($cases as $label => [$name, $params]) {
            $this->assertTrue($this->throws($vanilla, $name, $params), "sanity: vanilla throws for $label");
            $this->assertTrue($this->throws($greased, $name, $params), "greased must throw like vanilla for $label");
        }
    }

    private function throws(VanillaUrlGenerator $url, string $name, $params): bool
    {
        try {
            $url->route($name, $params);

            return false;
        } catch (UrlGenerationException) {
            return true;
        }
    }

    public function test_prewarmed_index_matches_lazy(): void
    {
        [$vanilla, $greased] = $this->generators();

        // Seed the index the way grease:route-cache would, via the public static compiler.
        $index = [];
        foreach ($this->routeCollection() as $route) {
            $entry = GreasedUrlGenerator::greaseCompileEntry($route);
            if ($entry !== false) {
                $index[$route->getName()] = $entry;
            }
        }
        $greased->useGreaseRouteUrlIndex($index);

        foreach (['posts.show' => ['post' => 1], 'posts.comments' => ['post' => 1, 'comment' => 2], 'posts.index' => []] as $name => $params) {
            $this->assertSame(
                $vanilla->route($name, $params, true),
                $greased->route($name, $params, true),
                "prewarmed $name"
            );
        }
    }

    public function test_url_defaults_disable_fast_path_byte_identically(): void
    {
        $request = Request::create('http://localhost/', 'GET');
        $routes = $this->routeCollection();

        $vanilla = new VanillaUrlGenerator($routes, $request);
        $greased = new GreasedUrlGenerator(clone $routes, $request);

        $vanilla->defaults(['post' => 99]);
        $greased->defaults(['post' => 99]);

        // With a URL default for {post}, route() may be called with no explicit param.
        $this->assertSame(
            $vanilla->route('posts.show', [], true),
            $greased->route('posts.show', [], true)
        );
    }

    public function test_secure_route_scheme_matches(): void
    {
        $request = Request::create('http://localhost/', 'GET');
        $routes = new RouteCollection;
        $routes->add(new Route(['GET'], 'secure/{id}', ['as' => 'secure.show', 'https', fn () => '']));
        $vanilla = new VanillaUrlGenerator($routes, $request);
        $greased = new GreasedUrlGenerator(clone $routes, $request);

        $this->assertSame(
            $vanilla->route('secure.show', ['id' => 5], true),
            $greased->route('secure.show', ['id' => 5], true)
        );
    }

    public function test_subdirectory_app_relative_matches(): void
    {
        // An app served from /sub — request->root() carries the base path.
        $request = Request::create('http://localhost/sub/index.php/api/posts/1', 'GET');
        $request->server->set('SCRIPT_NAME', '/sub/index.php');
        $request->server->set('SCRIPT_FILENAME', '/sub/index.php');

        $routes = $this->routeCollection();
        $vanilla = new VanillaUrlGenerator($routes, $request);
        $greased = new GreasedUrlGenerator(clone $routes, $request);

        // Both absolute and relative must match even when getBaseUrl() is non-empty.
        $this->assertSame(
            $vanilla->route('posts.show', ['post' => 1], true),
            $greased->route('posts.show', ['post' => 1], true),
            'subdir absolute'
        );
        $this->assertSame(
            $vanilla->route('posts.show', ['post' => 1], false),
            $greased->route('posts.show', ['post' => 1], false),
            'subdir relative'
        );
    }

    /**
     * forceRootUrl()/useOrigin() inject a root that may carry a *path* component
     * (`https://example.com/app`) which a relative URL must keep — `/app/...`. Regression for a
     * byte-identity divergence caught in review (the relative fast path now derives from the full
     * absolute URI and strips exactly what vanilla strips, so the forced root path survives).
     */
    public function test_forced_root_url_with_path_matches(): void
    {
        foreach (['https://example.com/app', 'https://cdn.example.com'] as $root) {
            [$vanilla, $greased] = $this->generators();
            $vanilla->forceRootUrl($root);
            $greased->forceRootUrl($root);

            foreach ([true, false] as $absolute) {
                $this->assertSame(
                    $vanilla->route('posts.show', ['post' => 5], $absolute),
                    $greased->route('posts.show', ['post' => 5], $absolute),
                    "$root absolute=".var_export($absolute, true)
                );
            }
        }
    }
}
