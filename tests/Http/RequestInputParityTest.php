<?php

namespace Grease\Tests\Http;

use Grease\Http\Request as GreasedRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Request as VanillaRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The input-memoization contract: a greased request must answer `input()`/`all()`/
 * `has()`/`only()`/`__get`/`offsetGet`/`isJson()` (and everything funneling through them)
 * byte-for-byte like vanilla — including after the Laravel-level mutators that invalidate
 * the memo. Oracle = vanilla {@see Request}.
 */
class RequestInputParityTest extends TestCase
{
    /** A GET request with nested + scalar query params. */
    private static function get(string $class): VanillaRequest
    {
        return $class::create('/x?a=1&b=&nested[x]=10&nested[y]=20&list[]=p&list[]=q', 'GET');
    }

    /** A POST request whose body and query collide (source must win on `input`). */
    private static function post(string $class): VanillaRequest
    {
        return $class::create('/x?a=query&q=1', 'POST', ['a' => 'body', 'c' => ['d' => 5]]);
    }

    /** A JSON-body request (input source is the JSON bag). */
    private static function json(string $class): VanillaRequest
    {
        return $class::create('/x?q=1', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'a' => 1,
            'nested' => ['x' => 10],
        ]));
    }

    /**
     * Read-only accessor probes. Each returns a canonical, comparable result.
     *
     * @return array<string, callable(Request): mixed>
     */
    private static function probes(): array
    {
        return [
            'input(key)' => fn ($r) => $r->input('a'),
            'input(dot)' => fn ($r) => $r->input('nested.x'),
            'input(missing,default)' => fn ($r) => $r->input('nope', 'def'),
            'input(all)' => fn ($r) => $r->input(),
            'all()' => fn ($r) => $r->all(),
            'all(keys)' => fn ($r) => $r->all(['a', 'nested']),
            'has(present)' => fn ($r) => $r->has('a'),
            'has(missing)' => fn ($r) => $r->has('nope'),
            'has(array)' => fn ($r) => $r->has(['a', 'q']),
            'filled' => fn ($r) => $r->filled('a'),
            'only' => fn ($r) => $r->only(['a', 'nested']),
            'except' => fn ($r) => $r->except(['a']),
            'magic-get' => fn ($r) => $r->a,
            'offsetExists' => fn ($r) => isset($r['a']),
            'offsetGet' => fn ($r) => $r['a'],
            'keys' => fn ($r) => $r->keys(),
            'isJson' => fn ($r) => $r->isJson(),
        ];
    }

    public static function matrix(): array
    {
        $cases = [];
        foreach (['get', 'post', 'json'] as $shape) {
            foreach (self::probes() as $name => $probe) {
                $cases["$shape / $name"] = [$shape, $probe];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_accessor_matches_vanilla(string $shape, callable $probe): void
    {
        $vanilla = $probe(self::$shape(VanillaRequest::class));
        $greased = $probe(self::$shape(GreasedRequest::class));

        $this->assertSame(var_export($vanilla, true), var_export($greased, true));
    }

    /**
     * The memo must invalidate: read (warms the memo), mutate, read again — the second
     * read must reflect the mutation, identically to vanilla.
     *
     * @return iterable<string, array{0: callable(Request): mixed}>
     */
    public static function mutations(): iterable
    {
        yield 'merge then read' => [function ($r) {
            $r->input('a');                       // warm memo
            $r->merge(['injected' => 'yes', 'a' => 'overwritten']);

            return [$r->input('injected'), $r->input('a'), $r->all()];
        }];

        yield 'mergeIfMissing then read' => [function ($r) {
            $r->all();
            $r->mergeIfMissing(['a' => 'should-not-win', 'fresh' => 'added']);

            return [$r->input('a'), $r->input('fresh')];
        }];

        yield 'replace then read' => [function ($r) {
            $r->input('a');
            $r->replace(['only' => 'this']);

            return [$r->input('a'), $r->input('only'), $r->all()];
        }];

        yield 'offsetSet then read' => [function ($r) {
            $r->all();
            $r['zz'] = 'set';

            return [$r->input('zz'), $r->all()];
        }];

        yield 'offsetUnset then read' => [function ($r) {
            $r->input('a');
            unset($r['a']);

            return [$r->has('a'), $r->input('a', 'gone'), $r->all()];
        }];
    }

    #[DataProvider('mutations')]
    public function test_mutation_invalidates_memo_like_vanilla(callable $sequence): void
    {
        $vanilla = $sequence(self::post(VanillaRequest::class));
        $greased = $sequence(self::post(GreasedRequest::class));

        $this->assertSame(var_export($vanilla, true), var_export($greased, true));
    }

    /**
     * Lifecycle paths that swap whole bags after the memo is warmed. Each warms the memo,
     * performs the lifecycle operation, then re-reads — greased must match vanilla.
     *
     * @return iterable<string, array{0: callable(class-string): mixed}>
     */
    public static function lifecycle(): iterable
    {
        // duplicate() = clone (copies the memo) + swap bags. The dup must reflect the NEW
        // query, not the warmed original's. This path is also hit under route:cache.
        yield 'duplicate swaps query' => [function (string $class) {
            $r = $class::create('/x?a=1&b=2', 'GET');
            $r->input('a'); // warm the original
            $dup = $r->duplicate(['a' => 'new', 'c' => '3']);

            return [$dup->input('a'), $dup->input('b', 'MISSING'), $dup->input('c'), $dup->all()];
        }];

        // A plain clone shares no state with the original: mutating the clone must not
        // touch the original (and vice versa).
        yield 'clone is independent' => [function (string $class) {
            $r = $class::create('/x', 'POST', ['a' => 'orig']);
            $r->input('a');
            $clone = clone $r;
            $clone->merge(['a' => 'cloned']);

            return [$r->input('a'), $clone->input('a')];
        }];

        // setMethod() rewrites REQUEST_METHOD → getInputSource() flips request→query, so a
        // body-only key disappears from input().
        yield 'setMethod flips input source' => [function (string $class) {
            $r = $class::create('/x?qonly=Q', 'POST', ['ponly' => 'P']);
            $before = [$r->input('qonly'), $r->input('ponly', 'NIL')];
            $r->setMethod('GET');
            $after = [$r->input('qonly'), $r->input('ponly', 'NIL')];

            return [$before, $after];
        }];

        // initialize() re-seeds every bag.
        yield 'initialize reseeds' => [function (string $class) {
            $r = $class::create('/x?a=1', 'GET');
            $r->all();
            $r->initialize(['a' => 'reinit', 'z' => '9']);

            return [$r->input('a'), $r->input('z'), $r->all()];
        }];
    }

    #[DataProvider('lifecycle')]
    public function test_lifecycle_path_invalidates_like_vanilla(callable $probe): void
    {
        $this->assertSame(
            var_export($probe(VanillaRequest::class), true),
            var_export($probe(GreasedRequest::class), true),
        );
    }

    /**
     * Bags OUTSIDE the input surface may be mutated directly without affecting the memo —
     * `attributes` (route params / middleware context) and `cookies` feed none of
     * `input()`/`all()`/`isJson()`. Replicates the common middleware pattern
     * `$request->attributes->set(...)` after input has been read.
     */
    public function test_attributes_bag_mutation_is_safe(): void
    {
        $probe = function (string $class) {
            $r = $class::create('/x?a=1&b=2', 'GET');
            $r->all();                                  // warm the memo
            $r->input('a');
            $r->attributes->set('mw_context', 'injected');   // middleware adds context
            $r->cookies->set('sid', 'xyz');

            return [
                'all' => $r->all(),                     // unaffected by attributes/cookies
                'input_a' => $r->input('a'),
                'attr' => $r->attributes->get('mw_context'),
                'get_attr' => $r->get('mw_context'),    // deprecated get() reads attributes first
            ];
        };

        $this->assertSame(
            var_export($probe(VanillaRequest::class), true),
            var_export($probe(GreasedRequest::class), true),
        );
    }

    public function test_repeated_reads_are_stable(): void
    {
        $r = self::post(GreasedRequest::class);

        $first = $r->all();
        $r->input('a');
        $r->has('c');
        $second = $r->all();

        $this->assertSame($first, $second);
        $this->assertSame('body', $r->input('a'));
    }
}
