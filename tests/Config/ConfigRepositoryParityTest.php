<?php

namespace Grease\Tests\Config;

use Grease\Config\Repository as GreasedRepository;
use Illuminate\Config\Repository as VanillaRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The config-memo contract: a greased repository must answer `get()` (and everything that
 * routes through it) byte-for-byte like vanilla — across nested keys, stored nulls, missing
 * keys with per-call defaults, closure defaults, getMany, and after every write path that
 * invalidates the memo. Oracle = vanilla {@see \Illuminate\Config\Repository}.
 */
class ConfigRepositoryParityTest extends TestCase
{
    /** A realistic loaded-config tree (what a config:cache'd repo holds). */
    private static function items(): array
    {
        return [
            'app' => [
                'name' => 'Grease', 'env' => 'production', 'debug' => false,
                'timezone' => 'UTC', 'providers' => ['A', 'B', 'C'], 'nullable' => null,
                'empty' => [],
            ],
            'database' => [
                'default' => 'mysql',
                'connections' => ['mysql' => ['host' => '127.0.0.1', 'port' => 3306]],
            ],
            'cache' => ['default' => 'redis', 'prefix' => 'gc'],
            'services' => ['stripe' => ['key' => null]], // genuinely-null stored value
        ];
    }

    /**
     * @return array<string, callable(\Illuminate\Config\Repository): mixed>
     */
    private static function probes(): array
    {
        return [
            'scalar' => fn ($c) => $c->get('app.name'),
            'bool-false' => fn ($c) => $c->get('app.debug'),
            'deep' => fn ($c) => $c->get('database.connections.mysql.host'),
            'whole-file' => fn ($c) => $c->get('app'),
            'array-value' => fn ($c) => $c->get('app.providers'),
            'stored-null' => fn ($c) => $c->get('services.stripe.key'),
            'stored-null-deep' => fn ($c) => $c->get('app.nullable'),
            'missing-no-default' => fn ($c) => $c->get('app.nope'),
            'missing-scalar-default' => fn ($c) => $c->get('app.nope', 'fallback'),
            'missing-closure-default' => fn ($c) => $c->get('x.y.z', fn () => ['built']),
            'top-level-missing' => fn ($c) => $c->get('absent', 7),
            'getMany' => fn ($c) => $c->get(['app.name', 'cache.default', 'app.nope']),
            'getMany-numeric' => fn ($c) => $c->get(['app.name', 'cache.prefix']),
            'has-present' => fn ($c) => $c->has('database.default'),
            'has-missing' => fn ($c) => $c->has('database.nope'),
            'offsetGet' => fn ($c) => $c['cache.default'],
            'offsetExists' => fn ($c) => isset($c['app.timezone']),
            'all' => fn ($c) => $c->all(),
        ];
    }

    public static function probeMatrix(): array
    {
        $cases = [];
        foreach (self::probes() as $name => $probe) {
            $cases[$name] = [$probe];
        }

        return $cases;
    }

    #[DataProvider('probeMatrix')]
    public function test_read_matches_vanilla(callable $probe): void
    {
        $this->assertSame(
            var_export($probe(new VanillaRepository(self::items())), true),
            var_export($probe(new GreasedRepository(self::items())), true),
        );
    }

    /**
     * Read each probe TWICE on one instance — the greased second read is served from the
     * memo and must equal the first (and vanilla). Catches the null-memo trap: a stored
     * `null` / `false` must come back as that value, not be re-treated as missing.
     */
    #[DataProvider('probeMatrix')]
    public function test_repeated_read_is_stable(callable $probe): void
    {
        $gre = new GreasedRepository(self::items());
        $first = var_export($probe($gre), true);
        $second = var_export($probe($gre), true);

        $this->assertSame($first, $second);
        $this->assertSame(var_export($probe(new VanillaRepository(self::items())), true), $second);
    }

    /**
     * A memo keyed only by the key string must NOT poison a later read of the SAME missing
     * key with a DIFFERENT default — missing keys are never memoized, so each call honors
     * its own default, exactly like vanilla.
     */
    public function test_missing_key_default_is_not_poisoned(): void
    {
        foreach ([new VanillaRepository(self::items()), new GreasedRepository(self::items())] as $c) {
            $this->assertSame('first', $c->get('app.nope', 'first'));
            $this->assertSame('second', $c->get('app.nope', 'second'));
            $this->assertNull($c->get('app.nope'));
        }
    }

    /**
     * Write paths that must invalidate the memo. Each warms the memo, mutates, re-reads —
     * greased must match vanilla. prepend/push/offsetSet/offsetUnset all funnel through set().
     *
     * @return iterable<string, array{0: callable(\Illuminate\Config\Repository): mixed}>
     */
    public static function mutations(): iterable
    {
        yield 'set scalar' => [function ($c) {
            $c->get('app.name');
            $c->set('app.name', 'Changed');

            return [$c->get('app.name'), $c->get('app')];
        }];

        yield 'set many' => [function ($c) {
            $c->get('app.env');
            $c->set(['app.env' => 'local', 'cache.default' => 'array']);

            return [$c->get('app.env'), $c->get('cache.default')];
        }];

        yield 'set nested creates' => [function ($c) {
            $c->get('app.nope', 'x');
            $c->set('app.nope', 'now-here');

            return [$c->get('app.nope'), $c->get('app.nope', 'unused-default')];
        }];

        yield 'set null shadows' => [function ($c) {
            $c->get('database.connections.mysql.host');
            $c->set('database.connections.mysql', null);

            return [$c->get('database.connections.mysql.host', 'def'), $c->get('database.connections.mysql')];
        }];

        yield 'push' => [function ($c) {
            $c->get('app.providers');
            $c->push('app.providers', 'D');

            return $c->get('app.providers');
        }];

        yield 'prepend' => [function ($c) {
            $c->get('app.providers');
            $c->prepend('app.providers', 'Z');

            return $c->get('app.providers');
        }];

        yield 'offsetSet' => [function ($c) {
            $c->get('cache.default');
            $c['cache.default'] = 'file';

            return [$c->get('cache.default'), $c['cache.default']];
        }];

        yield 'offsetUnset' => [function ($c) {
            $c->get('app.debug');
            $c->offsetUnset('app.debug');

            return [$c->get('app.debug', 'gone'), $c->has('app.debug')];
        }];
    }

    #[DataProvider('mutations')]
    public function test_mutation_invalidates_memo_like_vanilla(callable $sequence): void
    {
        $this->assertSame(
            var_export($sequence(new VanillaRepository(self::items())), true),
            var_export($sequence(new GreasedRepository(self::items())), true),
        );
    }

    /**
     * The Octane sandbox path: Octane isolates config per request with
     * `$sandbox->instance('config', clone $sandbox['config'])`. So `clone` of a greased
     * repository (memo and all) must behave exactly like `clone` of a vanilla one — the
     * clone reads correctly, and mutating the clone never touches the original (and vice
     * versa). This is what keeps the tier byte-identical under a persistent worker.
     */
    public function test_clone_is_octane_sandbox_safe(): void
    {
        $sequence = function ($class) {
            $base = new $class(self::items());
            $base->get('app.name');                 // warm the base memo (greased arm)
            $base->get('database.connections');

            $sandbox = clone $base;                  // Octane's per-request sandbox clone
            $sandbox->set('app.name', 'SandboxOnly');
            $sandbox->set('cache.default', 'array');

            return [
                'sandbox.name' => $sandbox->get('app.name'),       // sees its own mutation
                'sandbox.cache' => $sandbox->get('cache.default'),
                'sandbox.deep' => $sandbox->get('database.connections.mysql.host'),
                'base.name' => $base->get('app.name'),             // base UNCHANGED by sandbox
                'base.cache' => $base->get('cache.default'),
                'base.all' => $base->all(),
            ];
        };

        $this->assertSame(
            var_export($sequence(VanillaRepository::class), true),
            var_export($sequence(GreasedRepository::class), true),
        );
    }

    /** A closure default must be invoked (and its result returned), never memoized. */
    public function test_closure_default_invoked_each_time_when_missing(): void
    {
        $gre = new GreasedRepository(self::items());
        $calls = 0;
        $default = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $gre->get('not.there', $default));
        $this->assertSame('computed', $gre->get('not.there', $default));
        $this->assertSame(2, $calls, 'missing-key closure default must run every call (never memoized)');
    }

    /** `fromBase()` carries the items over verbatim. */
    public function test_from_base_carries_items(): void
    {
        $base = new VanillaRepository(self::items());
        $greased = GreasedRepository::fromBase($base);

        $this->assertSame(var_export($base->all(), true), var_export($greased->all(), true));
        $this->assertInstanceOf(GreasedRepository::class, $greased);
    }

    // --- Nested / single-value-vs-whole-array corner cases ------------------------

    /**
     * The bizarre-nesting probes: a key that resolves to a whole array vs a scalar leaf, a
     * path that descends INTO a scalar (must miss), numeric segments, and empty-array values.
     * Oracle = vanilla; each read twice so the memo path is exercised too.
     *
     * @return iterable<string, array{0: callable(\Illuminate\Config\Repository): mixed}>
     */
    public static function nestedProbes(): iterable
    {
        yield 'whole array then same' => [fn ($c) => [$c->get('database'), $c->get('database')]];
        yield 'whole array then child' => [fn ($c) => [$c->get('database.connections'), $c->get('database.connections.mysql.port')]];
        yield 'child then whole array' => [fn ($c) => [$c->get('app.name'), $c->get('app')]];
        yield 'descend into scalar leaf' => [fn ($c) => $c->get('app.name.deeper', 'fallback')]; // app.name is a string
        yield 'descend into bool leaf' => [fn ($c) => $c->get('app.debug.x', 'fallback')];
        yield 'numeric segment' => [fn ($c) => [$c->get('app.providers.0'), $c->get('app.providers.2')]];
        yield 'numeric segment OOB' => [fn ($c) => $c->get('app.providers.9', 'none')];
        yield 'empty array value' => [fn ($c) => [$c->get('app.empty'), $c->has('app.empty')]];
        yield 'empty array child' => [fn ($c) => $c->get('app.empty.anything', 'def')];
    }

    #[DataProvider('nestedProbes')]
    public function test_nested_corner_cases_match_vanilla(callable $probe): void
    {
        $this->assertSame(
            var_export($probe(new VanillaRepository(self::items())), true),
            var_export($probe(new GreasedRepository(self::items())), true),
        );
    }

    /**
     * Copy-on-write parity: a caller mutating a returned WHOLE array must not corrupt the
     * memo (or future reads), exactly as vanilla returning out of `$items`.
     */
    public function test_mutating_returned_array_does_not_poison_memo(): void
    {
        foreach ([new VanillaRepository(self::items()), new GreasedRepository(self::items())] as $c) {
            $a = $c->get('app');          // whole array (memoized in the greased arm)
            $a['name'] = 'MUTATED-LOCALLY';
            $a['providers'][] = 'INJECTED';

            // The caller's local mutation must not leak back into the repo.
            $this->assertSame('Grease', $c->get('app.name'));
            $this->assertSame(['A', 'B', 'C'], $c->get('app.providers'));
            $this->assertSame('Grease', $c->get('app')['name']);
        }
    }

    /** getMany (array key) stays consistent with single get() — before and after a write. */
    public function test_get_many_consistent_with_single_get(): void
    {
        foreach ([new VanillaRepository(self::items()), new GreasedRepository(self::items())] as $c) {
            $c->get('app.name');          // warm the single-key memo
            $c->get('cache.default');
            $this->assertSame(
                ['app.name' => 'Grease', 'cache.default' => 'redis'],
                $c->get(['app.name', 'cache.default']),
            );

            $c->set('app.name', 'Changed'); // flushes memo
            $this->assertSame('Changed', $c->get('app.name'));
            $this->assertSame(['app.name' => 'Changed'], $c->get(['app.name']));
        }
    }

    /**
     * The out-of-band carve-out + its hook: mutating the protected `$items` directly (a
     * macro / reflection write that bypasses set()) leaves the memo stale by design;
     * flushConfigMemo() recovers it to match vanilla.
     */
    public function test_flush_config_memo_recovers_from_out_of_band_mutation(): void
    {
        $gre = new GreasedRepository(self::items());
        $this->assertSame('Grease', $gre->get('app.name')); // warm the memo

        // Out-of-band write straight to the protected property (NOT via set()).
        (function () {
            $this->items['app']['name'] = 'OutOfBand';
        })->call($gre);

        // Stale by design — the memo still holds the pre-mutation value (documented carve-out).
        $this->assertSame('Grease', $gre->get('app.name'));

        // The explicit hook recovers it.
        $gre->flushConfigMemo();
        $this->assertSame('OutOfBand', $gre->get('app.name'));
    }
}
