<?php

namespace Grease\Tests\Routing;

use Grease\Routing\Router as GreasedRouter;
use Grease\Tests\Fixtures\Routing\MiddlewareStack;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router as VanillaRouter;
use Illuminate\Session\Middleware\StartSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The resolve-cache contract: `Grease\Routing\Router::resolveMiddleware()` must return the
 * resolved class list AND its order byte-for-byte like vanilla — on the cache miss, on the
 * cache hit, and after every map mutation that invalidates the cache. Oracle = vanilla
 * {@see VanillaRouter}. Shares the {@see MiddlewareStack} fixture with
 * `benchmarks/middleware_ab.php`, so the bench times exactly what these tests prove identical.
 */
class RouterMiddlewareParityTest extends TestCase
{
    private static function router(string $class): VanillaRouter
    {
        return MiddlewareStack::installInto(new $class(new Dispatcher, new Container));
    }

    public static function shapes(): array
    {
        $cases = [];
        foreach (MiddlewareStack::shapes() as $label => [$middleware, $excluded]) {
            $cases[$label] = [$middleware, $excluded];
        }

        return $cases;
    }

    /**
     * Across every realistic route shape: the greased miss, the greased hit, and vanilla all
     * agree exactly — same classes, same order.
     */
    #[DataProvider('shapes')]
    public function test_resolve_is_byte_identical(array $middleware, array $excluded): void
    {
        $vanilla = self::router(VanillaRouter::class)->resolveMiddleware($middleware, $excluded);

        $greased = self::router(GreasedRouter::class);
        $miss = $greased->resolveMiddleware($middleware, $excluded);
        $hit = $greased->resolveMiddleware($middleware, $excluded);

        $this->assertSame($vanilla, $miss, 'greased miss must equal vanilla (list + order)');
        $this->assertSame($vanilla, $hit, 'greased hit must equal vanilla (list + order)');
    }

    /** The cache must key on BOTH operands — different signatures must not collide. */
    public function test_distinct_signatures_do_not_collide(): void
    {
        $greased = self::router(GreasedRouter::class);
        $vanilla = self::router(VanillaRouter::class);

        foreach (MiddlewareStack::shapes() as [$middleware, $excluded]) {
            $greased->resolveMiddleware($middleware, $excluded); // populate
        }

        foreach (MiddlewareStack::shapes() as $label => [$middleware, $excluded]) {
            $this->assertSame(
                $vanilla->resolveMiddleware($middleware, $excluded),
                $greased->resolveMiddleware($middleware, $excluded),
                "signature '$label' returned a colliding cache entry",
            );
        }
    }

    /** Excluding a class that the `web` group pulls in must drop it — identically to vanilla. */
    public function test_excluded_middleware_matches_vanilla(): void
    {
        $middleware = ['web', 'auth'];
        $excluded = [StartSession::class];

        $vanilla = self::router(VanillaRouter::class)->resolveMiddleware($middleware, $excluded);
        $greased = self::router(GreasedRouter::class)->resolveMiddleware($middleware, $excluded);

        $this->assertSame($vanilla, $greased);
        $this->assertNotContains(StartSession::class, $greased);
    }

    /** A closure in the middleware list has no stable key — the greased path must defer, not cache. */
    public function test_closure_middleware_defers_and_matches_vanilla(): void
    {
        $closure = function ($request, $next) {
            return $next($request);
        };

        $middleware = ['web', $closure];

        $vanilla = self::router(VanillaRouter::class)->resolveMiddleware($middleware);

        $greased = self::router(GreasedRouter::class);
        $first = $greased->resolveMiddleware($middleware);
        $second = $greased->resolveMiddleware($middleware);

        $this->assertSame($vanilla, $first);
        $this->assertSame($vanilla, $second);

        // Nothing with a closure operand should have been cached.
        $this->assertSame([], self::cacheOf($greased), 'closure signatures must not be cached');
    }

    /**
     * After a runtime map mutation the cache must invalidate: re-resolving the same signature
     * must reflect the new map, exactly as vanilla does.
     */
    public function test_alias_mutation_invalidates_cache(): void
    {
        $signature = ['custom'];

        $greased = self::router(GreasedRouter::class);
        $vanilla = self::router(VanillaRouter::class);

        // First resolve with the alias mapped to one class.
        $greased->aliasMiddleware('custom', SubstituteBindings::class);
        $vanilla->aliasMiddleware('custom', SubstituteBindings::class);
        $this->assertSame(
            $vanilla->resolveMiddleware($signature),
            $greased->resolveMiddleware($signature),
        );

        // Remap the alias — the cached entry must be discarded, not served stale.
        $greased->aliasMiddleware('custom', StartSession::class);
        $vanilla->aliasMiddleware('custom', StartSession::class);
        $this->assertSame(
            $vanilla->resolveMiddleware($signature),
            $greased->resolveMiddleware($signature),
        );
        $this->assertSame([StartSession::class], $greased->resolveMiddleware($signature));
    }

    /** Mutating a group (push/prepend/remove/flush) must also invalidate. */
    public function test_group_mutation_invalidates_cache(): void
    {
        $signature = ['web'];

        $greased = self::router(GreasedRouter::class);
        $vanilla = self::router(VanillaRouter::class);

        $this->assertSame(
            $vanilla->resolveMiddleware($signature),
            $greased->resolveMiddleware($signature),
        );

        $greased->pushMiddlewareToGroup('web', SetCacheHeadersStub::class);
        $vanilla->pushMiddlewareToGroup('web', SetCacheHeadersStub::class);

        $this->assertSame(
            $vanilla->resolveMiddleware($signature),
            $greased->resolveMiddleware($signature),
        );
        $this->assertContains(SetCacheHeadersStub::class, $greased->resolveMiddleware($signature));
    }

    // ---- Eager index (grease:route-cache) seed contract ---------------------------

    /**
     * An index built as `grease:route-cache` would (signature => vanilla resolved) must, once
     * seeded, serve every shape as a pre-populated HIT that is byte-identical to vanilla.
     */
    public function test_eager_index_seed_serves_hits_matching_vanilla(): void
    {
        $vanilla = self::router(VanillaRouter::class);

        $index = [];
        foreach (MiddlewareStack::shapes() as [$mw, $ex]) {
            $index[GreasedRouter::greaseMiddlewareSignature($mw, $ex)] = $vanilla->resolveMiddleware($mw, $ex);
        }

        $greased = self::router(GreasedRouter::class);
        $greased->useGreaseRouteMiddlewareCache($index);

        // Genuinely seeded — every entry present before any live resolve on this instance.
        $this->assertSame(count($index), count(self::cacheOf($greased)));

        foreach (MiddlewareStack::shapes() as $label => [$mw, $ex]) {
            $this->assertSame(
                $vanilla->resolveMiddleware($mw, $ex),
                $greased->resolveMiddleware($mw, $ex),
                "seeded '$label' must match vanilla",
            );
        }
    }

    /**
     * Live truth must win: a live-resolved entry is never overwritten by a (possibly stale)
     * seeded entry for the same signature (the `+=` union semantics).
     */
    public function test_seed_never_overwrites_a_live_entry(): void
    {
        $greased = self::router(GreasedRouter::class);
        $live = $greased->resolveMiddleware(['web']); // resolved + cached live

        $key = GreasedRouter::greaseMiddlewareSignature(['web'], []);
        $greased->useGreaseRouteMiddlewareCache([$key => ['Stale\\Bogus']]);

        $this->assertSame($live, $greased->resolveMiddleware(['web']));
    }

    /**
     * A runtime map mutation must flush seeded entries too — the next resolve goes live and
     * matches vanilla, never serving a now-stale seed.
     */
    public function test_map_mutation_flushes_seeded_entries(): void
    {
        $key = GreasedRouter::greaseMiddlewareSignature(['web'], []);

        $greased = self::router(GreasedRouter::class);
        $greased->useGreaseRouteMiddlewareCache([$key => ['Seeded\\Stale']]);

        $greased->pushMiddlewareToGroup('web', SetCacheHeadersStub::class);
        $vanilla = self::router(VanillaRouter::class);
        $vanilla->pushMiddlewareToGroup('web', SetCacheHeadersStub::class);

        $resolved = $greased->resolveMiddleware(['web']);
        $this->assertSame($vanilla->resolveMiddleware(['web']), $resolved);
        $this->assertNotContains('Seeded\\Stale', $resolved);
    }

    /** Read the greased router's internal resolve cache. */
    private static function cacheOf(GreasedRouter $router): array
    {
        return (new ReflectionProperty(GreasedRouter::class, 'greaseResolvedMiddleware'))->getValue($router);
    }
}

/** Trivial stand-in class for the group-push invalidation test (just needs to exist). */
class SetCacheHeadersStub {}
