<?php

namespace Grease\Tests;

use ArrayObject;
use Grease\Events\Dispatcher as GreasedDispatcher;
use Grease\Support\WildcardPattern;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as VanillaDispatcher;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

interface ShipmentEvent {}

class OrderShipped implements ShipmentEvent
{
    public function __construct(public string $id = 'o1') {}
}

/**
 * Grease\Events\Dispatcher must behave byte-for-byte like the stock dispatcher —
 * same listeners, same order, same return values — only faster. Every test runs an
 * identical script against a vanilla and a greased dispatcher and asserts the
 * dispatch return value AND the full invocation log match.
 */
class EventsDispatcherParityTest extends TestCase
{
    /** @return array{0: VanillaDispatcher, 1: GreasedDispatcher} */
    private function pair(): array
    {
        return [new VanillaDispatcher(new Container), new GreasedDispatcher(new Container)];
    }

    /**
     * Register identical listeners (via $register) on both dispatchers, dispatch the
     * same event, and assert the return value and invocation log are identical.
     */
    private function assertParity(callable $register, string|object $event, array $payload = [], bool $halt = false): void
    {
        [$v, $g] = $this->pair();
        $vlog = new ArrayObject;
        $glog = new ArrayObject;

        $register($v, $vlog);
        $register($g, $glog);

        $vr = $v->dispatch($event, $payload, $halt);
        $gr = $g->dispatch($event, $payload, $halt);

        $this->assertSame($vr, $gr, 'dispatch() return value diverged');
        $this->assertSame($vlog->getArrayCopy(), $glog->getArrayCopy(), 'invocation log diverged');
    }

    public function test_no_listeners_returns_identical(): void
    {
        [$v, $g] = $this->pair();

        $this->assertSame($v->dispatch('foo.bar'), $g->dispatch('foo.bar'));
        $this->assertSame($v->dispatch('foo.bar', ['a', 'b']), $g->dispatch('foo.bar', ['a', 'b']));
        $this->assertSame($v->dispatch('foo.bar', [], true), $g->dispatch('foo.bar', [], true));
        $this->assertSame($v->until('foo.bar'), $g->until('foo.bar'));
    }

    public function test_direct_listeners_fire_in_order_with_spread_payload(): void
    {
        $this->assertParity(function ($d, $log) {
            $d->listen('user.created', function (...$args) use ($log) {
                $log[] = ['first', $args];

                return 'r1';
            });
            $d->listen('user.created', function (...$args) use ($log) {
                $log[] = ['second', $args];

                return 'r2';
            });
        }, 'user.created', ['alice', 42]);
    }

    public function test_halt_returns_first_non_null(): void
    {
        $this->assertParity(function ($d, $log) {
            $d->listen('q', function () use ($log) {
                $log[] = 'a';

                return null;
            });
            $d->listen('q', function () use ($log) {
                $log[] = 'b';

                return 'stop-here';
            });
            $d->listen('q', function () use ($log) {
                $log[] = 'c-should-not-run';

                return 'never';
            });
        }, 'q', [], true);
    }

    public function test_false_response_breaks_propagation(): void
    {
        $this->assertParity(function ($d, $log) {
            $d->listen('q', function () use ($log) {
                $log[] = 'a';

                return 'keep';
            });
            $d->listen('q', function () use ($log) {
                $log[] = 'b';

                return false;
            });
            $d->listen('q', function () use ($log) {
                $log[] = 'c-should-not-run';
            });
        }, 'q');
    }

    public function test_wildcard_listeners_receive_name_and_payload(): void
    {
        $this->assertParity(function ($d, $log) {
            $d->listen('user.*', function ($name, $payload) use ($log) {
                $log[] = ['wild', $name, $payload];
            });
        }, 'user.created', ['x']);
    }

    public function test_direct_then_wildcard_then_interface_order(): void
    {
        // An object event with a direct listener, a wildcard listener, and an
        // interface listener — the stock order is direct, wildcard, interface.
        $this->assertParity(function ($d, $log) {
            $d->listen(OrderShipped::class, function () use ($log) {
                $log[] = 'direct';
            });
            $d->listen('*', function ($name) use ($log) {
                $log[] = ['wild', $name];
            });
            $d->listen(ShipmentEvent::class, function () use ($log) {
                $log[] = 'interface';
            });
        }, new OrderShipped('o9'));
    }

    public function test_interface_listener_fires_when_class_event_dispatched_by_string(): void
    {
        // Dispatch a class event BY ITS STRING NAME with only an interface listener
        // registered. Vanilla's getListeners() adds it via addInterfaceListeners()
        // (class_exists($name) is true), so it fires. The no-listener fast path —
        // which gates on hasListeners(), blind to interface listeners — must NOT
        // short-circuit past it.
        $this->assertParity(function ($d, $log) {
            $d->listen(ShipmentEvent::class, function () use ($log) {
                $log[] = 'interface';
            });
        }, OrderShipped::class);
    }

    public function test_interface_listener_fires_on_string_dispatch_with_halt(): void
    {
        // Same hole on the halt path: until()/halt must return the interface
        // listener's value, not the fast path's null.
        $this->assertParity(function ($d, $log) {
            $d->listen(ShipmentEvent::class, function () use ($log) {
                $log[] = 'interface';

                return 'handled';
            });
        }, OrderShipped::class, [], true);
    }

    public function test_interface_listener_via_typed_closure_fires_on_string_dispatch(): void
    {
        // The optimization detects interface listeners from what parent::listen()
        // actually registers — including a typed-closure auto-listen, where the event
        // name is derived from the closure's parameter type, not the listen() args.
        // If the flag missed this, the fast path would drop the listener.
        $this->assertParity(function ($d, $log) {
            $d->listen(function (ShipmentEvent $e) use ($log) {
                $log[] = 'typed-closure';
            });
        }, OrderShipped::class, [new OrderShipped('o7')]);
    }

    public function test_interface_flag_engages_only_when_an_interface_listener_exists(): void
    {
        // White-box: the fast path stays cheap (flag off) for direct + concrete-class
        // + wildcard listeners, and flips on the moment an interface listener lands —
        // so apps without interface listeners pay nothing for the parity fix.
        $g = new GreasedDispatcher(new Container);
        $flag = fn () => (new \ReflectionProperty($g, 'greaseHasInterfaceListeners'))->getValue($g);

        $g->listen('user.created', fn () => null);
        $g->listen(OrderShipped::class, fn () => null); // a concrete class, not an interface
        $g->listen('user.*', fn () => null);
        $this->assertFalse($flag(), 'no interface listener → flag stays off');

        $g->listen(ShipmentEvent::class, fn () => null);
        $this->assertTrue($flag(), 'an interface listener flips the flag on');
    }

    public function test_non_matching_wildcard_does_not_fire(): void
    {
        $this->assertParity(function ($d, $log) {
            $d->listen('billing.*', function () use ($log) {
                $log[] = 'should-not-run';
            });
            $d->listen('user.created', function () use ($log) {
                $log[] = 'direct';
            });
        }, 'user.created');
    }

    public function test_object_event_passes_instance_as_payload(): void
    {
        $event = new OrderShipped('o42');

        $this->assertParity(function ($d, $log) {
            $d->listen(OrderShipped::class, function ($e) use ($log) {
                $log[] = $e->id;
            });
        }, $event);
    }

    public function test_forget_removes_listener_identically(): void
    {
        [$v, $g] = $this->pair();

        foreach ([$v, $g] as $d) {
            $d->listen('a.b', fn () => 'x');
            $d->listen('w.*', fn () => 'y');
        }

        $v->forget('a.b');
        $g->forget('a.b');
        $v->forget('w.*');
        $g->forget('w.*');

        $this->assertSame($v->dispatch('a.b'), $g->dispatch('a.b'));
        $this->assertSame($v->dispatch('w.x'), $g->dispatch('w.x'));
        $this->assertSame($v->hasListeners('a.b'), $g->hasListeners('a.b'));
    }

    public function test_listener_added_after_dispatch_is_seen(): void
    {
        // Exercises listener-cache invalidation: dispatch once (populating the cache),
        // then register another listener and dispatch again.
        [$v, $g] = $this->pair();
        $vlog = new ArrayObject;
        $glog = new ArrayObject;

        foreach ([[$v, $vlog], [$g, $glog]] as [$d, $log]) {
            $d->listen('e', function () use ($log) {
                $log[] = 'first';
            });
            $d->dispatch('e');
            $d->listen('e', function () use ($log) {
                $log[] = 'second';
            });
            $d->dispatch('e');
        }

        $this->assertSame($vlog->getArrayCopy(), $glog->getArrayCopy());
    }

    public function test_repeated_dispatch_of_same_event_is_identical(): void
    {
        // The listener-cache path: dispatching the same event many times must yield
        // the same responses each time.
        $this->assertParity(function ($d, $log) {
            $d->listen('tick', function () use ($log) {
                $log[] = 'x';

                return 'resp';
            });
        }, 'tick');

        [$v, $g] = $this->pair();
        $v->listen('tick', fn () => 'resp');
        $g->listen('tick', fn () => 'resp');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($v->dispatch('tick'), $g->dispatch('tick'), "iteration $i");
        }
    }

    public function test_frombase_migrates_direct_and_wildcard_listeners(): void
    {
        $stock = new VanillaDispatcher(new Container);
        $log = new ArrayObject;
        $stock->listen('user.created', function () use ($log) {
            $log[] = 'direct';
        });
        $stock->listen('user.*', function ($name) use ($log) {
            $log[] = ['wild', $name];
        });

        $greased = GreasedDispatcher::fromBase($stock);

        // The migrated dispatcher sees the listeners and fires them (the closures are
        // the same instances, carried over).
        $this->assertTrue($greased->hasListeners('user.created'));
        $this->assertTrue($greased->hasWildcardListeners('user.created'));

        $greased->dispatch('user.created');
        $this->assertSame(['direct', ['wild', 'user.created']], $log->getArrayCopy());
    }

    public function test_frombase_then_listen_works(): void
    {
        $stock = new VanillaDispatcher(new Container);
        $log = new ArrayObject;
        $stock->listen('e', function () use ($log) {
            $log[] = 'migrated';
        });

        $greased = GreasedDispatcher::fromBase($stock);
        $greased->listen('e', function () use ($log) {
            $log[] = 'added-after';
        });

        $greased->dispatch('e');
        $this->assertSame(['migrated', 'added-after'], $log->getArrayCopy());
    }

    public function test_frombase_dispatch_matches_the_source_dispatcher(): void
    {
        // A migrated dispatcher returns exactly what the source would for the same event.
        $stock = new VanillaDispatcher(new Container);
        $stock->listen('calc', fn () => 'a');
        $stock->listen('calc', fn () => 'b');
        $stock->listen('calc.*', fn () => 'wild');

        $greased = GreasedDispatcher::fromBase($stock);

        $this->assertSame($stock->dispatch('calc'), $greased->dispatch('calc'));
        $this->assertSame($stock->dispatch('calc.run'), $greased->dispatch('calc.run'));
        $this->assertSame($stock->dispatch('unheard'), $greased->dispatch('unheard'));
    }

    #[DataProvider('wildcardCases')]
    public function test_wildcard_pattern_matches_str_is(string $pattern, string $value): void
    {
        // The pre-compiled pattern must agree with Str::is on every case — that is
        // the equivalence the dispatcher relies on for wildcard matching.
        $this->assertSame(
            Str::is($pattern, $value),
            (new WildcardPattern($pattern))->matches($value),
            "[$pattern] vs [$value]"
        );
    }

    public static function wildcardCases(): array
    {
        $patterns = ['*', 'user.*', 'user.created', 'a.*.c', 'eloquent.retrieved: *', 'App\\Events\\*', 'a*b', 'no.match'];
        $values = ['user.created', 'user.profile.updated', 'a.b.c', 'a.c', 'eloquent.retrieved: App\\User', 'App\\Events\\Foo', 'aXXb', 'billing.charged', ''];

        $cases = [];
        foreach ($patterns as $p) {
            foreach ($values as $val) {
                $cases["[$p] ~ [$val]"] = [$p, $val];
            }
        }

        return $cases;
    }
}
