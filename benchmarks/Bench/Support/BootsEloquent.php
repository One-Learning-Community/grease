<?php

namespace Grease\Bench\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;

/**
 * Boots a standalone Eloquent (Capsule) for the benchmarks — with a REAL stock
 * event dispatcher wired in.
 *
 * This is the faithful default: a real app always has a dispatcher, so every
 * hydrated row fires `retrieved` and every save fires its four events, and the
 * stock dispatcher does that work even with zero listeners. Booting without one
 * (the old default) made `fireModelEvent` a no-op and understated the work an
 * endpoint actually does. Zero listeners are registered on purpose — "dispatcher
 * present, nothing on the hot path" is the common shape, and the cost of the
 * dispatch machinery itself is exactly what an events tier would target.
 */
final class BootsEloquent
{
    private static ?Capsule $capsule = null;

    /**
     * Boot once per process and return the Capsule (idempotent). Pass a database
     * path/DSN if a file-backed connection is needed; defaults to in-memory.
     */
    public static function capsule(string $database = ':memory:'): Capsule
    {
        if (self::$capsule !== null) {
            return self::$capsule;
        }

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $database]);
        $capsule->setEventDispatcher(new Dispatcher($capsule->getContainer()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return self::$capsule = $capsule;
    }
}
