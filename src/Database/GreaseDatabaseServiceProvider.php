<?php

namespace Grease\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

/**
 * Opt into the greased query grammars app-wide. Register this provider (deliberately NOT
 * auto-discovered — opt-in is the point) and every MySQL / MariaDB / PostgreSQL connection is
 * built with a grammar that memoizes identifier wrapping ({@see Concerns\MemoizesWrappedIdentifiers}):
 *
 *   // bootstrap/providers.php, or the providers array in config/app.php
 *   Grease\Database\GreaseDatabaseServiceProvider::class,
 *
 * It registers a `Connection::resolverFor()` per driver — the seam `ConnectionFactory` consults
 * first — so the greased connection class is used in place of the framework's. Output is
 * byte-identical: only the wrap path is memoized, and the prefix-flush invariant is preserved.
 *
 * SQLite and SQL Server fall back to vanilla (still correct, just unaccelerated); the wrap memo is
 * a per-grammar instance cache, so it warms within a request and — under Octane, where the
 * connection persists — stays warm across requests.
 */
class GreaseDatabaseServiceProvider extends ServiceProvider
{
    /** Driver name => greased connection class. */
    private const DRIVERS = [
        'mysql' => MySqlConnection::class,
        'mariadb' => MariaDbConnection::class,
        'pgsql' => PostgresConnection::class,
    ];

    public function register(): void
    {
        // Resolvers are process-global statics on Connection, consulted by ConnectionFactory
        // before its own driver match — so they must be set before the first connection is made.
        foreach (self::DRIVERS as $driver => $connectionClass) {
            Connection::resolverFor($driver, static function ($connection, $database, $prefix, $config) use ($connectionClass) {
                return new $connectionClass($connection, $database, $prefix, $config);
            });
        }
    }
}
